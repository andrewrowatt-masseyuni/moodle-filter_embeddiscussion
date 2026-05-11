<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace filter_embeddiscussion;

/**
 * Core data layer for filter_embeddiscussion.
 *
 * Owns thread initialisation, post CRUD, voting, and the per-user view
 * representation that the JS consumes.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /**
     * Build the render context — batched user records, role assignments and
     * vote counts — needed to render a list of posts. Returned as a plain array
     * with the lifetime of the calling render: no class statics, no leakage
     * across requests or tests, no possibility of a write path reading stale
     * values from a render-populated cache.
     *
     * @param \stdClass[] $posts
     * @param \context $context
     * @param int $vieweruserid the user we are rendering for
     * @return array{users:array<int,\stdClass>,roles:array<int,array>,votecounts:array<int,array{up:int,down:int}>,myvotes:array<int,int>}
     */
    protected static function build_render_context(array $posts, \context $context, int $vieweruserid): array {
        global $DB;

        $ctx = ['users' => [], 'roles' => [], 'votecounts' => [], 'myvotes' => []];
        if (empty($posts)) {
            return $ctx;
        }

        $userids = [];
        $postids = [];
        foreach ($posts as $p) {
            $userids[(int)$p->userid] = true;
            $postids[(int)$p->id] = true;
        }
        $userids = array_keys($userids);
        $postids = array_keys($postids);

        // Users — single IN query.
        $users = $DB->get_records_list('user', 'id', $userids);
        foreach ($users as $u) {
            $ctx['users'][(int)$u->id] = $u;
        }

        // Roles per user in this context.
        foreach ($userids as $uid) {
            $ctx['roles'][$uid] = get_user_roles($context, $uid, true);
        }

        // Vote totals — one GROUP BY query for up/down per post.
        foreach ($postids as $pid) {
            $ctx['votecounts'][$pid] = ['up' => 0, 'down' => 0];
            $ctx['myvotes'][$pid] = 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'p');
        $rows = $DB->get_records_sql(
            "SELECT postid, vote, COUNT(1) AS c
               FROM {filter_embeddiscussion_vote}
              WHERE postid $insql
           GROUP BY postid, vote",
            $params
        );
        foreach ($rows as $row) {
            $pid = (int)$row->postid;
            if ((int)$row->vote === 1) {
                $ctx['votecounts'][$pid]['up'] = (int)$row->c;
            } else if ((int)$row->vote === -1) {
                $ctx['votecounts'][$pid]['down'] = (int)$row->c;
            }
        }

        // The viewer's own vote per post.
        $params['userid'] = $vieweruserid;
        $myrows = $DB->get_records_sql(
            "SELECT postid, vote
               FROM {filter_embeddiscussion_vote}
              WHERE postid $insql AND userid = :userid",
            $params
        );
        foreach ($myrows as $row) {
            $ctx['myvotes'][(int)$row->postid] = (int)$row->vote;
        }

        return $ctx;
    }

    /**
     * Get an existing thread by idnumber+context, or null.
     *
     * @param string $idnumber
     * @param int $contextid
     * @return \stdClass|null
     */
    public static function find_thread(string $idnumber, int $contextid): ?\stdClass {
        global $DB;
        $idnumber = trim($idnumber);
        $hash = sha1($idnumber);
        $record = $DB->get_record('filter_embeddiscussion_thread', [
            'namehash' => $hash,
            'contextid' => $contextid,
        ]);
        return $record ?: null;
    }

    /**
     * Get or create the thread for an idnumber+context. Creation is logged.
     *
     * @param string $idnumber
     * @param \context $context
     * @param string|null $threadname thread name at token-processing time; refreshed
     *                                on each call when available.
     * @return \stdClass
     */
    public static function get_or_create_thread(
        string $idnumber,
        \context $context,
        ?string $threadname = null
    ): \stdClass {
        global $DB;

        $idnumber = trim($idnumber);
        if ($idnumber === '') {
            throw new \invalid_parameter_exception('Thread idnumber cannot be empty');
        }
        $threadname = trim((string)$threadname);

        $existing = self::find_thread($idnumber, $context->id);
        if ($existing) {
            // Refresh the threadname from the token only when the viewer is allowed to
            // mutate the thread record. Read-only viewers fall through unchanged so the
            // discussion still renders for them.
            if (
                $threadname !== ''
                && (string)($existing->threadname ?? '') !== $threadname
                && has_capability('filter/embeddiscussion:createthread', $context)
            ) {
                $existing->threadname = $threadname;
                $existing->timemodified = time();
                $DB->update_record('filter_embeddiscussion_thread', $existing);
            }
            return $existing;
        }

        // Initialising the thread requires the createthread capability. The text filter
        // catches this exception so users without it just see no embedded discussion.
        require_capability('filter/embeddiscussion:createthread', $context);

        // Discover an enclosing course id, if any.
        $courseid = 0;
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            $courseid = (int)$coursecontext->instanceid;
        }

        $now = time();
        $masterlistsize = max(count(handles::master_list()), 1);

        $record = (object)[
            'idnumber' => $idnumber,
            'threadname' => ($threadname !== '') ? $threadname : $idnumber,
            'namehash' => sha1($idnumber),
            'contextid' => $context->id,
            'courseid' => $courseid,
            'anonymous' => 0,
            'handleoffset' => random_int(0, $masterlistsize - 1),
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        try {
            $record->id = $DB->insert_record('filter_embeddiscussion_thread', $record);
        } catch (\dml_write_exception $e) {
            // Race condition: re-fetch the row created by the racing request.
            $existing = self::find_thread($idnumber, $context->id);
            if ($existing) {
                return $existing;
            }
            throw $e;
        }

        \filter_embeddiscussion\event\thread_initialised::create_for_thread($record, $context)->trigger();

        return $record;
    }

    /**
     * Sync the thread's anonymous flag to match the value declared on
     * the filter token. Authority lives with whoever can edit the host content,
     * so no extra capability check is performed here.
     *
     * @param \stdClass $thread
     * @param array $settings keys: anonymous (bool)
     * @return \stdClass updated thread record
     */
    public static function sync_settings_from_token(\stdClass $thread, array $settings): \stdClass {
        global $DB;

        $context = \context::instance_by_id((int)$thread->contextid, IGNORE_MISSING);
        if (!$context || !has_capability('filter/embeddiscussion:createthread', $context)) {
            return $thread;
        }

        $changed = false;
        if (array_key_exists('anonymous', $settings)) {
            $newval = $settings['anonymous'] ? 1 : 0;
            if ((int)$thread->anonymous !== $newval) {
                $thread->anonymous = $newval;
                $changed = true;
            }
        }
        if ($changed) {
            $thread->timemodified = time();
            $DB->update_record('filter_embeddiscussion_thread', $thread);
        }

        return $thread;
    }

    /**
     * Sanitise post HTML through Moodle's KSES-based cleaner.
     *
     * Uses clean_text() with FORMAT_HTML, which strips event handlers, blocks
     * dangerous URL schemes, and enforces the site-configured allowed tag set.
     *
     * @param string $html
     * @return string
     */
    public static function sanitise(string $html): string {
        return clean_text($html, FORMAT_HTML);
    }

    /**
     * Create a new post.
     *
     * @param \stdClass $thread
     * @param \context $context
     * @param int $parentid 0 for top level
     * @param string $content sanitised HTML
     * @param int $userid
     * @return \stdClass the new post record
     */
    public static function create_post(
        \stdClass $thread,
        \context $context,
        int $parentid,
        string $content,
        int $userid
    ): \stdClass {
        global $DB;

        require_capability('filter/embeddiscussion:createpost', $context);

        $clean = self::sanitise($content);
        if (trim(strip_tags($clean)) === '' && stripos($clean, '<img') === false) {
            throw new \moodle_exception('error_emptypost', 'filter_embeddiscussion');
        }

        if ($parentid > 0) {
            // Parent must belong to this thread.
            $parent = $DB->get_record('filter_embeddiscussion_post', ['id' => $parentid], 'id, threadid');
            if (!$parent || (int)$parent->threadid !== (int)$thread->id) {
                throw new \invalid_parameter_exception('Invalid parent post');
            }
        }

        $now = time();
        $record = (object)[
            'threadid' => $thread->id,
            'parentid' => $parentid,
            'userid' => $userid,
            'content' => $clean,
            'edited' => 0,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('filter_embeddiscussion_post', $record);

        // If anonymous mode is on and the user can't see real authors, lock in their handle now.
        if (
            $thread->anonymous && !has_capability(
                'filter/embeddiscussion:viewallauthorsinanonymousthreads',
                $context,
                $userid
            )
        ) {
            handles::get_or_assign($thread, $userid);
        }

        \filter_embeddiscussion\event\post_created::create_for_post($record, $thread, $context)->trigger();

        return $record;
    }

    /**
     * Edit an existing post.
     *
     * @param int $postid
     * @param \stdClass $thread
     * @param \context $context
     * @param string $content
     * @param int $userid current user
     * @return \stdClass updated post
     */
    public static function edit_post(
        int $postid,
        \stdClass $thread,
        \context $context,
        string $content,
        int $userid
    ): \stdClass {
        global $DB;

        $post = $DB->get_record('filter_embeddiscussion_post', ['id' => $postid], '*', MUST_EXIST);
        if ((int)$post->threadid !== (int)$thread->id) {
            throw new \invalid_parameter_exception('Post does not belong to this thread');
        }
        if ($post->deleted) {
            throw new \moodle_exception('error_invalidthread', 'filter_embeddiscussion');
        }

        $isown = ((int)$post->userid === $userid);
        if ($isown) {
            require_capability('filter/embeddiscussion:editownpost', $context);
        } else {
            require_capability('filter/embeddiscussion:manageposts', $context);
        }

        $clean = self::sanitise($content);
        if (trim(strip_tags($clean)) === '' && stripos($clean, '<img') === false) {
            throw new \moodle_exception('error_emptypost', 'filter_embeddiscussion');
        }

        $post->content = $clean;
        $post->edited = 1;
        $post->timemodified = time();
        $DB->update_record('filter_embeddiscussion_post', $post);

        \filter_embeddiscussion\event\post_edited::create_for_post($post, $thread, $context)->trigger();

        return $post;
    }

    /**
     * Delete a post (soft delete: keep the row, blank content).
     *
     * @param int $postid
     * @param \stdClass $thread
     * @param \context $context
     * @param int $userid current user
     */
    public static function delete_post(int $postid, \stdClass $thread, \context $context, int $userid): void {
        global $DB;

        $post = $DB->get_record('filter_embeddiscussion_post', ['id' => $postid], '*', MUST_EXIST);
        if ((int)$post->threadid !== (int)$thread->id) {
            throw new \invalid_parameter_exception('Post does not belong to this thread');
        }
        if ($post->deleted) {
            return;
        }

        $isown = ((int)$post->userid === $userid);
        $candeleteown = has_capability('filter/embeddiscussion:deleteownpost', $context);
        $candeleteany = has_capability('filter/embeddiscussion:deleteanypost', $context);

        $allowed = ($isown && $candeleteown) || $candeleteany;
        if (!$allowed) {
            throw new \required_capability_exception(
                $context,
                'filter/embeddiscussion:deleteanypost',
                'nopermissions',
                ''
            );
        }

        $post->deleted = 1;
        $post->content = '';
        $post->timemodified = time();
        $DB->update_record('filter_embeddiscussion_post', $post);

        \filter_embeddiscussion\event\post_deleted::create_for_post($post, $thread, $context)->trigger();
    }

    /**
     * Vote on a post. $direction is 1, -1, or 0 to clear.
     *
     * @param int $postid
     * @param \stdClass $thread
     * @param \context $context
     * @param int $direction
     * @param int $userid
     * @return array [int up, int down, int my] my is -1/0/1
     */
    public static function vote_post(
        int $postid,
        \stdClass $thread,
        \context $context,
        int $direction,
        int $userid
    ): array {
        global $DB;

        require_capability('filter/embeddiscussion:createpost', $context);

        $post = $DB->get_record('filter_embeddiscussion_post', ['id' => $postid], '*', MUST_EXIST);
        if ((int)$post->threadid !== (int)$thread->id) {
            throw new \invalid_parameter_exception('Post does not belong to this thread');
        }
        if ($post->deleted) {
            throw new \moodle_exception('error_invalidthread', 'filter_embeddiscussion');
        }

        $direction = max(-1, min(1, $direction));

        $existing = $DB->get_record('filter_embeddiscussion_vote', [
            'postid' => $postid, 'userid' => $userid,
        ]);

        if ($direction === 0) {
            if ($existing) {
                $DB->delete_records('filter_embeddiscussion_vote', ['id' => $existing->id]);
            }
        } else if ($existing) {
            if ((int)$existing->vote !== $direction) {
                $existing->vote = $direction;
                $existing->timecreated = time();
                $DB->update_record('filter_embeddiscussion_vote', $existing);
            }
        } else {
            $DB->insert_record('filter_embeddiscussion_vote', (object)[
                'postid' => $postid,
                'userid' => $userid,
                'vote' => $direction,
                'timecreated' => time(),
            ]);
        }

        \filter_embeddiscussion\event\post_voted::create_for_post($post, $thread, $context, $direction)->trigger();

        return self::vote_summary($postid, $userid);
    }

    /**
     * Aggregate vote counts plus the named user's vote, read directly from the
     * database. Render paths read prefetched counts via build_render_context;
     * this helper is for the write path (vote_post) and for any caller that
     * needs a fresh authoritative summary.
     *
     * @param int $postid
     * @param int $userid
     * @return array [int up, int down, int my]
     */
    public static function vote_summary(int $postid, int $userid): array {
        global $DB;

        $up = (int)$DB->count_records('filter_embeddiscussion_vote', ['postid' => $postid, 'vote' => 1]);
        $down = (int)$DB->count_records('filter_embeddiscussion_vote', ['postid' => $postid, 'vote' => -1]);
        $myrec = $DB->get_record('filter_embeddiscussion_vote', ['postid' => $postid, 'userid' => $userid]);
        $my = $myrec ? (int)$myrec->vote : 0;
        return ['up' => $up, 'down' => $down, 'my' => $my];
    }

    /**
     * Get a user's primary non-student role in this context (display label).
     *
     * @param \context $context
     * @param int $userid
     * @param array|null $roles pre-fetched role assignments, or null to look up
     * @return string empty if student/no special role
     */
    public static function user_role_label(\context $context, int $userid, ?array $roles = null): string {
        $roles ??= get_user_roles($context, $userid, true);
        foreach ($roles as $r) {
            $archetype = self::role_archetype((int)$r->roleid);
            if ($archetype !== 'student' && $archetype !== '' && $archetype !== 'guest') {
                return role_get_name($r, $context, ROLENAME_ALIAS);
            }
        }
        return '';
    }

    /**
     * Cached lookup of role archetype.
     *
     * @param int $roleid
     * @return string
     */
    protected static function role_archetype(int $roleid): string {
        static $cache = [];
        if (!array_key_exists($roleid, $cache)) {
            global $DB;
            $cache[$roleid] = (string)$DB->get_field('role', 'archetype', ['id' => $roleid]);
        }
        return $cache[$roleid];
    }

    /**
     * Build the data payload describing a thread plus all its posts, scoped to
     * the viewing user (visibility, permissions, anonymisation).
     *
     * @param \stdClass $thread
     * @param \context $context
     * @return array
     */
    public static function get_thread_view(\stdClass $thread, \context $context): array {
        global $DB, $USER, $PAGE;

        $posts = $DB->get_records(
            'filter_embeddiscussion_post',
            ['threadid' => $thread->id],
            'timecreated ASC'
        );

        $renderctx = self::build_render_context($posts, $context, (int)$USER->id);

        $canviewfullnames = has_capability('moodle/site:viewfullnames', $context);
        $canmanageposts = has_capability('filter/embeddiscussion:manageposts', $context);
        $candeleteany = has_capability('filter/embeddiscussion:deleteanypost', $context);
        $candeleteown = has_capability('filter/embeddiscussion:deleteownpost', $context);
        $caneditown = has_capability('filter/embeddiscussion:editownpost', $context);
        $canpost = has_capability('filter/embeddiscussion:createpost', $context);

        $renderer = $PAGE->get_renderer('core');

        $postsout = [];
        foreach ($posts as $p) {
            $postsout[] = self::build_post_view(
                $p,
                $thread,
                $context,
                $renderctx,
                $canviewfullnames,
                $canmanageposts,
                $candeleteany,
                $candeleteown,
                $caneditown,
                $renderer
            );
        }

        $currentuserisanonymous = (bool)$thread->anonymous
            && !has_capability(
                'filter/embeddiscussion:viewallauthorsinanonymousthreads',
                $context
            );

        return [
            'threadid' => (int)$thread->id,
            'name' => self::thread_display_name($thread),
            'anonymous' => (bool)$thread->anonymous,
            'currentuserisanonymous' => $currentuserisanonymous,
            'canpost' => $canpost,
            'canmanageposts' => $canmanageposts,
            'postcount' => count($postsout),
            'posts' => $postsout,
            'currentuserid' => (int)$USER->id,
            'currentuseravatar' => $renderer->user_picture($USER, ['size' => 64, 'link' => false]),
            'currentuserprofileurl' => isloggedin() && !isguestuser()
                ? (new \moodle_url('/user/profile.php', ['id' => $USER->id]))->out(false)
                : '',
        ];
    }

    /**
     * Build the JSON-shaped representation of a single post for $USER.
     *
     * @param \stdClass $post
     * @param \stdClass $thread
     * @param \context $context
     * @param array $renderctx render context produced by build_render_context
     * @param bool $canviewfullnames
     * @param bool $canmanageposts
     * @param bool $candeleteany
     * @param bool $candeleteown
     * @param bool $caneditown
     * @param \renderer_base $renderer
     * @return array
     */
    protected static function build_post_view(
        \stdClass $post,
        \stdClass $thread,
        \context $context,
        array $renderctx,
        bool $canviewfullnames,
        bool $canmanageposts,
        bool $candeleteany,
        bool $candeleteown,
        bool $caneditown,
        \renderer_base $renderer
    ): array {
        global $USER, $DB;

        $authorid = (int)$post->userid;
        $author = $renderctx['users'][$authorid] ?? $DB->get_record('user', ['id' => $authorid]);
        $authorroles = $renderctx['roles'][$authorid] ?? null;

        $isanon = false;
        $handle = '';
        $authorname = '';
        $profileurl = '';
        $avatar = '';
        $rolelabel = '';
        $isown = $author && ((int)$author->id === (int)$USER->id);

        if ($post->deleted) {
            $avatar = \html_writer::empty_tag('img', [
                'class' => 'userpicture',
                'alt' => '',
                'src' => $renderer->image_url('u/f1')->out(false),
                'width' => 48,
                'height' => 48,
            ]);
        } else if ($author) {
            $canseeauthor = has_capability(
                'filter/embeddiscussion:viewallauthorsinanonymousthreads',
                $context,
                (int)$author->id
            );
            $rolelabel = self::user_role_label($context, (int)$author->id, $authorroles);

            if ($thread->anonymous && !$canseeauthor) {
                [$handle, ] = handles::get_or_assign($thread, (int)$author->id);
                $isanon = true;
            }

            if ($canviewfullnames || !$isanon || $isown) {
                $authorname = fullname($author);
                $profileurl = (new \moodle_url('/user/profile.php', ['id' => $author->id]))->out(false);
                $avatar = $renderer->user_picture($author, ['size' => 64, 'link' => false]);
            } else {
                $authorname = $handle;
                $avatar = \html_writer::empty_tag('img', [
                    'class' => 'userpicture',
                    'alt' => '',
                    'src' => identicon::data_uri('embeddisc:' . $thread->id . ':' . $author->id),
                    'width' => 48,
                    'height' => 48,
                ]);
            }
        }

        $postid = (int)$post->id;
        $up = (int)($renderctx['votecounts'][$postid]['up'] ?? 0);
        $down = (int)($renderctx['votecounts'][$postid]['down'] ?? 0);
        $my = (int)($renderctx['myvotes'][$postid] ?? 0);

        $candelete = !$post->deleted && (
            ($isown && $candeleteown) || $candeleteany
        );
        $canedit = !$post->deleted && (
            ($isown && $caneditown) || $canmanageposts
        );

        return [
            'id' => $postid,
            'parentid' => (int)$post->parentid,
            'content' => $post->deleted ? '' : format_text(
                $post->content,
                FORMAT_HTML,
                ['context' => $context, 'filter' => false, 'noclean' => false]
            ),
            'deleted' => (bool)$post->deleted,
            'edited' => (bool)$post->edited,
            'timecreated' => (int)$post->timecreated,
            'timecreatediso' => userdate($post->timecreated, get_string('strftimedatetime', 'langconfig')),
            'authorname' => $authorname,
            'authorhandle' => $handle,
            'authorrole' => $rolelabel,
            'isanonymous' => $isanon,
            'profileurl' => $profileurl,
            'avatar' => $avatar,
            'votes_up' => $up,
            'votes_down' => $down,
            'votes_my' => $my,
            'canedit' => $canedit,
            'candelete' => $candelete,
            'canreply' => has_capability('filter/embeddiscussion:createpost', $context),
        ];
    }

    /**
     * Return a unique identifier for this thread to use in HTML as an id
     *
     * @param mixed $threadid
     * @param mixed $contextid
     * @return string
     */
    public static function get_thread_uid($threadid, $contextid): string {
        return "embeddisc_$threadid-$contextid";
    }

    /**
     * Build the course feed payload: all posts in visible-module threads,
     * newest-first, with unread markers for posts newer than last access.
     *
     * @param int $courseid
     * @param int $userid viewing user
     * @return array
     */
    public static function get_dashboard_view(int $courseid, int $userid): array {
        global $DB, $PAGE;

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modinfo = get_fast_modinfo($course, $userid);

        $lastaccess = (int)($DB->get_field('user_lastaccess', 'timeaccess', [
            'userid' => $userid,
            'courseid' => $courseid,
        ]) ?: 0);

        // Include both module contexts (label/page/book, etc.) and course
        // context threads (for section summary discussions).
        $contextids = [(int)\context_course::instance($courseid)->id];
        foreach ($modinfo->cms as $cm) {
            if ($cm->uservisible) {
                $contextids[] = (int)$cm->context->id;
            }
        }
        $contextids = array_values(array_unique($contextids));

        if (empty($contextids)) {
            return self::empty_dashboard_payload($lastaccess);
        }

        $renderer = $PAGE->get_renderer('core');

        [$insql, $inparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $threads = $DB->get_records_select(
            'filter_embeddiscussion_thread',
            "contextid $insql",
            $inparams,
            'threadname ASC, idnumber ASC'
        );

        $threadsout = [];
        $postsout = [];
        foreach ($threads as $thread) {
            $entry = self::build_dashboard_thread_entry($thread, $lastaccess, $renderer);
            if ($entry === null) {
                continue;
            }
            $threadsout[] = $entry;
            foreach ($entry['posts'] as $p) {
                $postsout[] = $p;
            }
        }

        usort($postsout, function (array $a, array $b): int {
            if ((int)$a['timecreated'] === (int)$b['timecreated']) {
                return (int)$b['id'] <=> (int)$a['id'];
            }
            return (int)$b['timecreated'] <=> (int)$a['timecreated'];
        });

        return self::dashboard_payload($lastaccess, $threadsout, count($postsout), $postsout);
    }

    /**
     * Build the per-thread payload of all posts, or null if the thread has no
     * posts to render.
     *
     * @param \stdClass $thread
     * @param int $lastaccess
     * @param \renderer_base $renderer
     * @return array|null
     */
    protected static function build_dashboard_thread_entry(
        \stdClass $thread,
        int $lastaccess,
        \renderer_base $renderer
    ): ?array {
        global $DB, $USER;

        $posts = $DB->get_records_select(
            'filter_embeddiscussion_post',
            'threadid = :tid',
            ['tid' => (int)$thread->id],
            'timecreated DESC'
        );
        if (empty($posts)) {
            return null;
        }

        $context = \context::instance_by_id((int)$thread->contextid);
        $renderctx = self::build_render_context($posts, $context, (int)$USER->id);
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $context);

        $postsout = [];
        foreach ($posts as $p) {
            $view = self::build_post_view(
                $p,
                $thread,
                $context,
                $renderctx,
                $canviewfullnames,
                false,
                false,
                false,
                false,
                $renderer
            );
            $postsout[] = self::dashboard_post_from_view($view, $thread, (int)$p->id, $lastaccess);
        }
        return [
            'threadid' => (int)$thread->id,
            'name' => self::thread_display_name($thread),
            'postcount' => count($postsout),
            'posts' => $postsout,
        ];
    }

    /**
     * Human-facing thread label for read-only views.
     *
     * @param \stdClass $thread
     * @return string
     */
    protected static function thread_display_name(\stdClass $thread): string {
        $name = trim((string)($thread->threadname ?? ''));
        if ($name === '') {
            $name = trim((string)($thread->idnumber ?? ''));
        }
        if ($name === '') {
            return '';
        }
        $context = \context::instance_by_id((int)$thread->contextid, IGNORE_MISSING)
            ?: \context_system::instance();
        return format_string($name, true, ['context' => $context]);
    }

    /**
     * Empty-state payload used when the user has no visible modules in the
     * course or no visible posts.
     *
     * @param int $lastaccess
     * @return array
     */
    protected static function empty_dashboard_payload(int $lastaccess): array {
        return self::dashboard_payload($lastaccess, [], 0, []);
    }

    /**
     * Build the dashboard payload envelope around the per-thread entries.
     *
     * @param int $lastaccess
     * @param array $threads
     * @param int $totalposts
     * @param array $posts
     * @return array
     */
    protected static function dashboard_payload(int $lastaccess, array $threads, int $totalposts, array $posts): array {
        return [
            'lastaccess' => $lastaccess,
            'lastaccessiso' => $lastaccess
                ? userdate($lastaccess, get_string('strftimedatetime', 'langconfig'))
                : '',
            'lastaccessrelative' => $lastaccess
                ? format_time(time() - $lastaccess)
                : '',
            'hasitems' => $totalposts > 0,
            'neverbefore' => ($lastaccess === 0),
            'threadcount' => count($threads),
            'postcount' => $totalposts,
            'threads' => $threads,
            'posts' => $posts,
        ];
    }

    /**
     * Convert the rich per-post view payload returned by build_post_view into
     * the lighter shape the dashboard expects: drops the interactive fields
     * (votes, parentid, can-edit/delete/reply) and adds a posturl anchor.
     *
     * @param array $view a row produced by self::build_post_view
     * @param \stdClass $thread thread record
     * @param int $postid the post's id (used to compose the anchor)
     * @param int $lastaccess the viewer's last course access timestamp
     * @return array
     */
    protected static function dashboard_post_from_view(
        array $view,
        \stdClass $thread,
        int $postid,
        int $lastaccess
    ): array {
        unset(
            $view['parentid'],
            $view['votes_up'],
            $view['votes_down'],
            $view['votes_my'],
            $view['canedit'],
            $view['candelete'],
            $view['canreply']
        );
        $view['threadid'] = (int)$thread->id;
        $view['threadname'] = self::thread_display_name($thread);
        $view['isunread'] = ((int)$view['timecreated'] > $lastaccess);
        $view['posturl'] = '#embeddisc-post-' . $postid;
        return $view;
    }
}

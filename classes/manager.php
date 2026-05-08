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
    /** Allowed inline tags after sanitisation. */
    const ALLOWED_TAGS = '<p><br><b><strong><i><em><u><a><img><mark><ul><ol><li><blockquote><span>';

    /** @var bool|null cache of whether the threadname column exists. */
    protected static $threadnamecol = null;

    /**
     * Whether the thread table has the threadname column (new schema).
     *
     * @return bool
     */
    protected static function has_threadname_column(): bool {
        global $DB;

        if (self::$threadnamecol === null) {
            $manager = $DB->get_manager();
            $table = new \xmldb_table('filter_embeddiscussion_thread');
            $field = new \xmldb_field('threadname');
            self::$threadnamecol = $manager->field_exists($table, $field);
        }

        return self::$threadnamecol;
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
     * @param string|null $pageurl URL of the page hosting the thread; refreshed on each
     *                             call so the stored value tracks the current location.
     * @param string|null $threadname thread name at token-processing time; refreshed
     *                                on each call when available.
     * @return \stdClass
     */
    public static function get_or_create_thread(
        string $idnumber,
        \context $context,
        ?string $pageurl = null,
        ?string $threadname = null
    ): \stdClass {
        global $DB;

        $idnumber = trim($idnumber);
        if ($idnumber === '') {
            throw new \invalid_parameter_exception('Thread idnumber cannot be empty');
        }
        $threadname = trim((string)$threadname);
        $threadnamefield = self::has_threadname_column() ? 'threadname' : 'pagetitle';

        $existing = self::find_thread($idnumber, $context->id);
        if ($existing) {
            $changed = false;
            if ($pageurl !== null && $pageurl !== '' && (string)($existing->pageurl ?? '') !== $pageurl) {
                $existing->pageurl = $pageurl;
                $changed = true;
            }
            if ($threadname !== '' && (string)($existing->{$threadnamefield} ?? '') !== $threadname) {
                $existing->{$threadnamefield} = $threadname;
                $changed = true;
            }
            if ($changed) {
                $existing->timemodified = time();
                $DB->update_record('filter_embeddiscussion_thread', $existing);
            }
            return $existing;
        }

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
            $threadnamefield => ($threadname !== '') ? $threadname : $idnumber,
            'namehash' => sha1($idnumber),
            'contextid' => $context->id,
            'courseid' => $courseid,
            'anonymous' => 0,
            'handleoffset' => random_int(0, $masterlistsize - 1),
            'pageurl' => ($pageurl !== null && $pageurl !== '') ? $pageurl : null,
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
     * Sanitise post HTML to the allowed inline tag set.
     *
     * @param string $html
     * @return string
     */
    public static function sanitise(string $html): string {
        $clean = strip_tags($html, self::ALLOWED_TAGS);
        // Strip any javascript: / data: URLs in href/src.
        $clean = preg_replace_callback(
            '/(href|src)\s*=\s*"([^"]*)"/i',
            function ($m) {
                $url = trim($m[2]);
                if (preg_match('/^\s*javascript:/i', $url)) {
                    return $m[1] . '="#"';
                }
                return $m[0];
            },
            $clean
        );
        return $clean;
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

        // If anonymous mode is on and the user is a student, lock in their handle now.
        if ($thread->anonymous && self::user_is_student($context, $userid)) {
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
     * Aggregate vote counts plus the current user's vote.
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
     * Determine whether a user has the student archetype in this context.
     *
     * @param \context $context
     * @param int $userid
     * @return bool
     */
    public static function user_is_student(\context $context, int $userid): bool {
        // Use only directly assigned roles; archetype 'student' or no role at all means treat as student.
        $roles = get_user_roles($context, $userid, true);
        if (empty($roles)) {
            return true;
        }
        $hasstudent = false;
        $hasnonstudent = false;
        foreach ($roles as $r) {
            $archetype = self::role_archetype((int)$r->roleid);
            if ($archetype === 'student' || $archetype === '' || $archetype === 'guest') {
                $hasstudent = true;
            } else {
                $hasnonstudent = true;
            }
        }
        return $hasstudent && !$hasnonstudent;
    }

    /**
     * Get a user's primary non-student role in this context (display label).
     *
     * @param \context $context
     * @param int $userid
     * @return string empty if student/no special role
     */
    public static function user_role_label(\context $context, int $userid): string {
        $roles = get_user_roles($context, $userid, true);
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
                $canviewfullnames,
                $canmanageposts,
                $candeleteany,
                $candeleteown,
                $caneditown,
                $renderer
            );
        }

        $currentuserisanonymous = (bool)$thread->anonymous
            && self::user_is_student($context, (int)$USER->id);

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
        bool $canviewfullnames,
        bool $canmanageposts,
        bool $candeleteany,
        bool $candeleteown,
        bool $caneditown,
        \renderer_base $renderer
    ): array {
        global $USER, $DB;

        $author = $DB->get_record('user', ['id' => $post->userid]);

        $isanon = false;
        $handle = '';
        $authorname = '';
        $profileurl = '';
        $avatar = '';
        $rolelabel = '';
        $isown = $author && ((int)$author->id === (int)$USER->id);

        if ($post->deleted) {
            $avatar = '<img class="userpicture" alt="" src="' .
                s($renderer->image_url('u/f1')->out(false)) . '" width="48" height="48">';
        } else if ($author) {
            $isstudent = self::user_is_student($context, (int)$author->id);
            $rolelabel = self::user_role_label($context, (int)$author->id);

            if ($thread->anonymous && $isstudent) {
                [$handle, ] = handles::get_or_assign($thread, (int)$author->id);
                $isanon = true;
            }

            if ($canviewfullnames || !$isanon || $isown) {
                $authorname = fullname($author);
                $profileurl = (new \moodle_url('/user/profile.php', ['id' => $author->id]))->out(false);
                $avatar = $renderer->user_picture($author, ['size' => 64, 'link' => false]);
            } else {
                $authorname = $handle;
                $avatar = '<img class="userpicture" alt="" src="' .
                    s(identicon::data_uri('embeddisc:' . $thread->id . ':' . $author->id)) .
                    '" width="48" height="48">';
            }
        }

        $votes = self::vote_summary((int)$post->id, (int)$USER->id);

        $candelete = !$post->deleted && (
            ($isown && $candeleteown) || $candeleteany
        );
        $canedit = !$post->deleted && (
            ($isown && $caneditown) || $canmanageposts
        );

        return [
            'id' => (int)$post->id,
            'parentid' => (int)$post->parentid,
            'content' => $post->deleted ? '' : $post->content,
            'deleted' => (bool)$post->deleted,
            'edited' => (bool)$post->edited,
            'timecreated' => (int)$post->timecreated,
            'timecreatediso' => userdate($post->timecreated, get_string('strftimedatetime', 'langconfig')),
            'timecreatedrelative' => format_time(time() - $post->timecreated) . ' ' . get_string('ago', 'filter_embeddiscussion'),
            'authorname' => $authorname,
            'authorhandle' => $handle,
            'authorrole' => $rolelabel,
            'isanonymous' => $isanon,
            'profileurl' => $profileurl,
            'avatar' => $avatar,
            'votes_up' => (int)$votes['up'],
            'votes_down' => (int)$votes['down'],
            'votes_my' => (int)$votes['my'],
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

        $contextids = [];
        foreach ($modinfo->cms as $cm) {
            if ($cm->uservisible) {
                $contextids[] = (int)$cm->context->id;
            }
        }

        if (empty($contextids)) {
            return self::empty_dashboard_payload($lastaccess);
        }

        $renderer = $PAGE->get_renderer('core');

        [$insql, $inparams] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $orderfield = self::has_threadname_column() ? 'threadname' : 'pagetitle';
        $threads = $DB->get_records_select(
            'filter_embeddiscussion_thread',
            "contextid $insql",
            $inparams,
            $orderfield . ' ASC, idnumber ASC'
        );

        $threadsout = [];
        $postsout = [];
        foreach ($threads as $thread) {
            $entry = self::build_dashboard_thread_entry($thread, $lastaccess, $renderer);
            if ($entry === null) {
                continue;
            }
            $threadsout[] = $entry;
            $postsout = array_merge($postsout, $entry['posts']);
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
        global $DB;

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
        $canviewfullnames = has_capability('moodle/site:viewfullnames', $context);
        $pageurl = (string)($thread->pageurl ?? '');

        $postsout = [];
        foreach ($posts as $p) {
            $view = self::build_post_view(
                $p,
                $thread,
                $context,
                $canviewfullnames,
                false,
                false,
                false,
                false,
                $renderer
            );
            $postsout[] = self::dashboard_post_from_view($view, $thread, (int)$p->id, $pageurl, $lastaccess);
        }
        return [
            'threadid' => (int)$thread->id,
            'name' => self::thread_display_name($thread),
            'pageurl' => $pageurl,
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
        $threadname = trim((string)($thread->threadname ?? ''));
        if ($threadname !== '') {
            return $threadname;
        }
        $threadname = trim((string)($thread->pagetitle ?? ''));
        if ($threadname !== '') {
            return $threadname;
        }
        return trim((string)($thread->idnumber ?? ''));
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
     * (votes, parentid, can-edit/delete/reply) and adds a posturl that deep-
     * links back to the post in its hosting page.
     *
     * @param array $view a row produced by self::build_post_view
     * @param \stdClass $thread thread record
     * @param int $postid the post's id (used to compose the anchor)
     * @param string $pageurl the host page URL stored on the thread
     * @param int $lastaccess the viewer's last course access timestamp
     * @return array
     */
    protected static function dashboard_post_from_view(
        array $view,
        \stdClass $thread,
        int $postid,
        string $pageurl,
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
        $view['posturl'] = ($pageurl !== '') ? $pageurl . '#embeddisc-post-' . $postid : '';
        return $view;
    }
}

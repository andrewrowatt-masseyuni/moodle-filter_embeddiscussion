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
 * Owns thread initialisation, post CRUD, emoji reactions, and the per-user view
 * representation that the JS consumes.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** @var string Default emoji set as comma-separated shortcode:unicode pairs. */
    const DEFAULT_EMOJIS = 'thumbsup:👍,heart:❤️,laugh:😂,think:🤔,celebrate:🎉,surprise:😮,thanks:🙏';

    /** @var array<string,string>|null Per-request cache of the parsed emoji set. */
    private static ?array $emojisetcache = null;

    /**
     * Get the configured emoji set.
     *
     * @return array Associative array of shortcode => unicode emoji, in configured order.
     */
    public static function get_emoji_set(): array {
        if (self::$emojisetcache !== null) {
            return self::$emojisetcache;
        }

        $config = get_config('filter_embeddiscussion', 'emojis');
        if (empty($config)) {
            $config = self::DEFAULT_EMOJIS;
        }

        $emojis = [];
        $pairs = explode(',', $config);
        foreach ($pairs as $pair) {
            $parts = explode(':', trim($pair), 2);
            if (count($parts) === 2) {
                $emojis[trim($parts[0])] = trim($parts[1]);
            }
        }
        return self::$emojisetcache = $emojis;
    }

    /**
     * Build the render context — batched user records, role assignments and
     * reaction counts — needed to render a list of posts. Returned as a plain
     * array with the lifetime of the calling render: no class statics, no
     * leakage across requests or tests, no possibility of a write path reading
     * stale values from a render-populated cache.
     *
     * @param \stdClass[] $posts
     * @param \context $context
     * @param int $vieweruserid the user we are rendering for
     * @return array{users:array<int,\stdClass>,roles:array<int,array>,reactions:array<int,array{counts:array<string,int>,userreactions:string[]}>}
     */
    protected static function build_render_context(array $posts, \context $context, int $vieweruserid): array {
        global $DB;

        $ctx = ['users' => [], 'roles' => [], 'reactions' => []];
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

        // Reaction counts plus the viewer's own reactions, batched across all posts.
        $ctx['reactions'] = self::get_reactions($postids, $vieweruserid);

        return $ctx;
    }

    /**
     * Toggle a reaction. If the user already has this emoji on the post, remove it.
     * Otherwise add it. When $allowmultiple is false, any existing reactions by this
     * user on the same post are removed before adding the new one (single-reaction mode).
     * When $allowmultiple is true (the default), users can have multiple different emoji
     * reactions on the same post.
     *
     * @param int $postid Post ID.
     * @param int $userid User ID.
     * @param string $emoji Emoji shortcode.
     * @param bool $allowmultiple When false, enforce single-reaction-per-post mode.
     * @return array ['action' => 'added'|'removed', 'emoji' => string]
     */
    public static function toggle_reaction(
        int $postid,
        int $userid,
        string $emoji,
        bool $allowmultiple = true
    ): array {
        global $DB;

        // Validate emoji is in the configured set.
        $emojiset = self::get_emoji_set();
        if (!isset($emojiset[$emoji])) {
            throw new \invalid_parameter_exception('Invalid emoji: ' . $emoji);
        }

        $existing = $DB->get_record('filter_embeddiscussion_reaction', [
            'postid' => $postid,
            'userid' => $userid,
            'emoji' => $emoji,
        ]);

        if ($existing) {
            // Already reacted with this emoji - remove it.
            $DB->delete_records('filter_embeddiscussion_reaction', ['id' => $existing->id]);
            return ['action' => 'removed', 'emoji' => $emoji];
        }

        // In single-reaction mode, wrap the delete-then-insert atomically so that
        // concurrent requests from the same user cannot both slip through and add
        // multiple reactions in a mode that is meant to allow only one.
        $transaction = null;
        if (!$allowmultiple) {
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records('filter_embeddiscussion_reaction', [
                'postid' => $postid,
                'userid' => $userid,
            ]);
        }
        // Add the reaction. Two near-simultaneous requests for the same
        // (post, user, emoji) can both reach this insert; the unique index will
        // reject the loser with a dml_write_exception. Treat that as an idempotent
        // success so a double-click does not surface as an error to the user.
        $key = [
            'postid' => $postid,
            'userid' => $userid,
            'emoji' => $emoji,
        ];
        $record = (object) ($key + ['timecreated' => time()]);
        try {
            $DB->insert_record('filter_embeddiscussion_reaction', $record);
        } catch (\dml_write_exception $e) {
            // Only swallow the "duplicate key" case; re-throw any other write failure
            // so a real DB error doesn't get reported to the user as a successful add.
            if (!$DB->record_exists('filter_embeddiscussion_reaction', $key)) {
                throw $e;
            }
        }
        if ($transaction) {
            $DB->commit_delegated_transaction($transaction);
        }
        return ['action' => 'added', 'emoji' => $emoji];
    }

    /**
     * Get reaction counts and the current user's reactions for multiple posts.
     *
     * @param int[] $postids Array of post IDs.
     * @param int $userid Current user ID.
     * @return array Keyed by postid, each containing 'counts' (emoji => total) and 'userreactions' (shortcodes).
     */
    public static function get_reactions(array $postids, int $userid): array {
        global $DB;

        $result = [];
        foreach ($postids as $postid) {
            $result[(int)$postid] = [
                'counts' => [],
                'userreactions' => [],
            ];
        }

        if (empty($postids)) {
            return $result;
        }

        // Counts per emoji per post.
        [$insql, $params] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED, 'p');

        // Synthesise a unique first column so get_records_sql can key the result rows
        // (postid alone is not unique when multiple emoji counts exist for one post).
        // Use sql_concat() for cross-database portability (Oracle's CONCAT takes only 2 args).
        $uid = $DB->sql_concat('postid', "'_'", 'emoji');
        $sql = "SELECT $uid AS uid, postid, emoji, COUNT(1) AS total
                  FROM {filter_embeddiscussion_reaction}
                 WHERE postid $insql
              GROUP BY postid, emoji
              ORDER BY postid, total DESC";

        $counts = $DB->get_records_sql($sql, $params);
        foreach ($counts as $row) {
            $result[(int)$row->postid]['counts'][$row->emoji] = (int) $row->total;
        }

        // The current user's reactions.
        $params['userid'] = $userid;
        $myrows = $DB->get_records_sql(
            "SELECT id, postid, emoji
               FROM {filter_embeddiscussion_reaction}
              WHERE postid $insql AND userid = :userid",
            $params
        );
        foreach ($myrows as $row) {
            $result[(int)$row->postid]['userreactions'][] = $row->emoji;
        }

        return $result;
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
     * Delete every thread anchored to any of the given contexts, along with the
     * posts, reactions and anonymous handles that hang off them.
     *
     * Used when Moodle deletes a context (course, module or block) so the
     * orphaned discussion data does not outlive the thing it was embedded in.
     * A {@see \filter_embeddiscussion\event\post_deleted} event is emitted for
     * each post and a {@see \filter_embeddiscussion\event\thread_deleted} event
     * for each thread.
     *
     * @param int[] $contextids context ids whose threads should be removed
     */
    public static function delete_threads_for_contexts(array $contextids): void {
        global $DB;

        $contextids = array_values(array_unique(array_filter(array_map('intval', $contextids))));
        if (empty($contextids)) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
        $threads = $DB->get_records_select('filter_embeddiscussion_thread', "contextid $insql", $params);
        foreach ($threads as $thread) {
            self::delete_thread($thread);
        }
    }

    /**
     * Delete a single thread and all data hanging off it, emitting a
     * post_deleted event per post and a thread_deleted event for the thread.
     *
     * Events are triggered while the rows still exist so observers can read
     * the records being removed.
     *
     * @param \stdClass $thread
     */
    protected static function delete_thread(\stdClass $thread): void {
        global $DB;

        $context = \context::instance_by_id((int)$thread->contextid, IGNORE_MISSING);

        $posts = $DB->get_records('filter_embeddiscussion_post', ['threadid' => $thread->id]);

        if ($context) {
            foreach ($posts as $post) {
                \filter_embeddiscussion\event\post_deleted::create_for_post($post, $thread, $context)->trigger();
            }
        }

        if ($posts) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($posts), SQL_PARAMS_NAMED, 'p');
            $DB->delete_records_select('filter_embeddiscussion_reaction', "postid $insql", $params);
        }
        $DB->delete_records('filter_embeddiscussion_handle', ['threadid' => $thread->id]);
        $DB->delete_records('filter_embeddiscussion_post', ['threadid' => $thread->id]);

        if ($context) {
            \filter_embeddiscussion\event\thread_deleted::create_for_thread($thread, $context)->trigger();
        }

        $DB->delete_records('filter_embeddiscussion_thread', ['id' => $thread->id]);
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

        // Ordered emoji set for the reactions picker; reacting reuses the post capability.
        $emojis = [];
        foreach (self::get_emoji_set() as $shortcode => $unicode) {
            $emojis[] = ['shortcode' => $shortcode, 'unicode' => $unicode];
        }

        return [
            'threadid' => (int)$thread->id,
            'name' => self::thread_display_name($thread),
            'anonymous' => (bool)$thread->anonymous,
            'currentuserisanonymous' => $currentuserisanonymous,
            'canpost' => $canpost,
            'canmanageposts' => $canmanageposts,
            'canreact' => $canpost,
            'emojis' => $emojis,
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
        $reactiondata = $renderctx['reactions'][$postid] ?? ['counts' => [], 'userreactions' => []];
        $reactioncounts = [];
        foreach ($reactiondata['counts'] as $emoji => $count) {
            $reactioncounts[] = ['emoji' => $emoji, 'count' => (int)$count];
        }

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
            'reactions' => [
                'counts' => $reactioncounts,
                'userreactions' => array_values($reactiondata['userreactions']),
            ],
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
     * (reactions, parentid, can-edit/delete/reply) and adds a posturl anchor.
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
            $view['reactions'],
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

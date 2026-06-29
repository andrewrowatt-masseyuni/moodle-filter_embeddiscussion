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

namespace filter_embeddiscussion\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for filter_embeddiscussion.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Describe what data the plugin stores.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('filter_embeddiscussion_post', [
            'userid' => 'privacy:metadata:post:userid',
            'content' => 'privacy:metadata:post:content',
            'timecreated' => 'privacy:metadata:post:timecreated',
            'timemodified' => 'privacy:metadata:post:timemodified',
        ], 'privacy:metadata:post');

        $collection->add_database_table('filter_embeddiscussion_reaction', [
            'userid' => 'privacy:metadata:reaction:userid',
            'emoji' => 'privacy:metadata:reaction:emoji',
            'timecreated' => 'privacy:metadata:reaction:timecreated',
        ], 'privacy:metadata:reaction');

        $collection->add_database_table('filter_embeddiscussion_handle', [
            'userid' => 'privacy:metadata:handle:userid',
            'handleindex' => 'privacy:metadata:handle:handleindex',
        ], 'privacy:metadata:handle');

        return $collection;
    }

    /**
     * Get contexts that have data for a given user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT t.contextid
                  FROM {filter_embeddiscussion_thread} t
                  JOIN {filter_embeddiscussion_post} p ON p.threadid = t.id
                 WHERE p.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        $sql = "SELECT t.contextid
                  FROM {filter_embeddiscussion_thread} t
                  JOIN {filter_embeddiscussion_post} p ON p.threadid = t.id
                  JOIN {filter_embeddiscussion_reaction} r ON r.postid = p.id
                 WHERE r.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        $sql = "SELECT t.contextid
                  FROM {filter_embeddiscussion_thread} t
                  JOIN {filter_embeddiscussion_handle} h ON h.threadid = t.id
                 WHERE h.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        return $contextlist;
    }

    /**
     * Get users in a context who have stored data.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        $sql = "SELECT p.userid
                  FROM {filter_embeddiscussion_thread} t
                  JOIN {filter_embeddiscussion_post} p ON p.threadid = t.id
                 WHERE t.contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);

        $sql = "SELECT r.userid
                  FROM {filter_embeddiscussion_thread} t
                  JOIN {filter_embeddiscussion_post} p ON p.threadid = t.id
                  JOIN {filter_embeddiscussion_reaction} r ON r.postid = p.id
                 WHERE t.contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);

        $sql = "SELECT h.userid
                  FROM {filter_embeddiscussion_thread} t
                  JOIN {filter_embeddiscussion_handle} h ON h.threadid = t.id
                 WHERE t.contextid = :contextid";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
    }

    /**
     * Export user data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $threads = $DB->get_records('filter_embeddiscussion_thread', ['contextid' => $context->id]);
            foreach ($threads as $thread) {
                $posts = $DB->get_records('filter_embeddiscussion_post', [
                    'threadid' => $thread->id, 'userid' => $userid,
                ]);
                if (!$posts) {
                    continue;
                }
                $exportposts = array_map(function ($p) {
                    return [
                        'content' => $p->content,
                        'timecreated' => $p->timecreated,
                        'timemodified' => $p->timemodified,
                        'edited' => (bool)$p->edited,
                        'deleted' => (bool)$p->deleted,
                    ];
                }, array_values($posts));

                $threadtitle = trim((string)($thread->threadname ?? ''));
                if ($threadtitle === '') {
                    $threadtitle = 'thread-' . $thread->id;
                }

                writer::with_context($context)->export_data(
                    [get_string('filtername', 'filter_embeddiscussion'), $threadtitle],
                    (object)['posts' => $exportposts]
                );
            }
        }
    }

    /**
     * Delete all data for a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        $threadids = $DB->get_fieldset_select(
            'filter_embeddiscussion_thread',
            'id',
            'contextid = :ctx',
            ['ctx' => $context->id]
        );
        if (!$threadids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($threadids, SQL_PARAMS_NAMED);
        $postids = $DB->get_fieldset_select('filter_embeddiscussion_post', 'id', "threadid $insql", $params);
        if ($postids) {
            [$insql2, $params2] = $DB->get_in_or_equal($postids, SQL_PARAMS_NAMED);
            $DB->delete_records_select('filter_embeddiscussion_reaction', "postid $insql2", $params2);
        }
        $DB->delete_records_select('filter_embeddiscussion_handle', "threadid $insql", $params);
        $DB->delete_records_select('filter_embeddiscussion_post', "threadid $insql", $params);
        $DB->delete_records_select('filter_embeddiscussion_thread', "id $insql", $params);
    }

    /**
     * Delete user's data in approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $threadids = $DB->get_fieldset_select(
                'filter_embeddiscussion_thread',
                'id',
                'contextid = :ctx',
                ['ctx' => $context->id]
            );
            if (!$threadids) {
                continue;
            }
            [$insql, $params] = $DB->get_in_or_equal($threadids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;

            // Anonymise posts: keep the row to preserve thread shape but blank content.
            $DB->execute("UPDATE {filter_embeddiscussion_post}
                             SET content = '', deleted = 1
                           WHERE userid = :userid AND threadid $insql", $params);

            // Remove reactions.
            $DB->execute("DELETE FROM {filter_embeddiscussion_reaction}
                          WHERE userid = :userid AND postid IN (
                              SELECT id FROM {filter_embeddiscussion_post}
                              WHERE threadid $insql)", $params);

            // Remove handle assignment.
            $DB->execute("DELETE FROM {filter_embeddiscussion_handle}
                          WHERE userid = :userid AND threadid $insql", $params);
        }
    }

    /**
     * Delete data for users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        $threadids = $DB->get_fieldset_select(
            'filter_embeddiscussion_thread',
            'id',
            'contextid = :ctx',
            ['ctx' => $context->id]
        );
        if (!$threadids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($threadids, SQL_PARAMS_NAMED, 'tids');
        [$uinsql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uids');
        $params = array_merge($params, $uparams);

        $DB->execute("UPDATE {filter_embeddiscussion_post}
                         SET content = '', deleted = 1
                       WHERE userid $uinsql AND threadid $insql", $params);

        $DB->execute("DELETE FROM {filter_embeddiscussion_reaction}
                      WHERE userid $uinsql AND postid IN (
                          SELECT id FROM {filter_embeddiscussion_post}
                          WHERE threadid $insql)", $params);

        $DB->execute("DELETE FROM {filter_embeddiscussion_handle}
                      WHERE userid $uinsql AND threadid $insql", $params);
    }
}

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

namespace filter_embeddiscussion\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use filter_embeddiscussion\manager;

/**
 * Up- or down-vote a post.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vote_post extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'Post id'),
            'direction' => new external_value(PARAM_INT, 'Vote direction: -1, 0, or 1'),
        ]);
    }

    /**
     * Vote.
     *
     * @param int $postid
     * @param int $direction
     * @return array
     */
    public static function execute(int $postid, int $direction): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'postid' => $postid,
            'direction' => $direction,
        ]);

        $post = $DB->get_record('filter_embeddiscussion_post', ['id' => $params['postid']], '*', MUST_EXIST);
        $thread = $DB->get_record(
            'filter_embeddiscussion_thread',
            ['id' => $post->threadid],
            '*',
            MUST_EXIST
        );

        // Derive the context from the thread; never trust a client-supplied context.
        $context = \context::instance_by_id((int)$thread->contextid);
        self::validate_context($context);

        $summary = manager::vote_post(
            $params['postid'],
            $thread,
            $context,
            $params['direction'],
            $USER->id
        );

        return [
            'postid' => (int)$post->id,
            'votes_up' => (int)$summary['up'],
            'votes_down' => (int)$summary['down'],
            'votes_my' => (int)$summary['my'],
        ];
    }

    /**
     * Return value definition.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'postid' => new external_value(PARAM_INT, 'Post id'),
            'votes_up' => new external_value(PARAM_INT, 'Up votes'),
            'votes_down' => new external_value(PARAM_INT, 'Down votes'),
            'votes_my' => new external_value(PARAM_INT, "Viewer's own vote"),
        ]);
    }
}

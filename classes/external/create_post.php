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
use core_external\external_value;
use filter_embeddiscussion\manager;

/**
 * Create a post or reply.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_post extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'threadid' => new external_value(PARAM_INT, 'Thread id'),
            'parentid' => new external_value(PARAM_INT, 'Parent post id, 0 for top-level', VALUE_DEFAULT, 0),
            'content' => new external_value(PARAM_RAW, 'Post HTML content'),
        ]);
    }

    /**
     * Create.
     *
     * @param int $threadid
     * @param int $parentid
     * @param string $content
     * @return array
     */
    public static function execute(int $threadid, int $parentid, string $content): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'threadid' => $threadid,
            'parentid' => $parentid,
            'content' => $content,
        ]);

        $thread = $DB->get_record(
            'filter_embeddiscussion_thread',
            ['id' => $params['threadid']],
            '*',
            MUST_EXIST
        );

        // Derive the context from the thread, not from the client. The client
        // must not be able to choose a context where it happens to hold the
        // capability and post into a thread anchored elsewhere.
        $context = \context::instance_by_id((int)$thread->contextid);
        self::validate_context($context);

        manager::create_post($thread, $context, $params['parentid'], $params['content'], $USER->id);

        return manager::get_thread_view($thread, $context);
    }

    /**
     * Return value definition.
     *
     * @return \core_external\external_description
     */
    public static function execute_returns() {
        return helper::thread_structure();
    }
}

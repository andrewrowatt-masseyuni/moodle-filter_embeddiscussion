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
 * Return an embedded discussion thread by id.
 *
 * The thread is created (and its anonymous flag synced from the filter token)
 * server-side by the filter at render time, so this endpoint only needs the
 * thread id — never trust thread settings from the client.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_thread extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'threadid' => new external_value(PARAM_INT, 'Thread id'),
        ]);
    }

    /**
     * Return the per-viewer thread payload.
     *
     * @param int $threadid
     * @return array
     */
    public static function execute(int $threadid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'threadid' => $threadid,
        ]);

        $thread = $DB->get_record(
            'filter_embeddiscussion_thread',
            ['id' => $params['threadid']],
            '*',
            MUST_EXIST
        );

        $context = \context::instance_by_id((int)$thread->contextid);
        self::validate_context($context);

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

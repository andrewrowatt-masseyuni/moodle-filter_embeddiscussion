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

declare(strict_types=1);

namespace filter_embeddiscussion\reportbuilder\local\systemreports;

use core_reportbuilder\local\helpers\database;
use core_reportbuilder\local\report\action;
use core_reportbuilder\system_report;
use filter_embeddiscussion\reportbuilder\local\entities\thread;
use lang_string;
use moodle_url;
use pix_icon;

/**
 * System report listing all embedded discussion threads in a course (or site-wide).
 *
 * Required parameter: courseid (int). Pass 0 for site-wide.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class threads extends system_report {
    /**
     * Initialise the report.
     */
    protected function initialise(): void {
        $entity = new thread();
        $alias = $entity->get_table_alias('filter_embeddiscussion_thread');

        $this->set_main_table('filter_embeddiscussion_thread', $alias);
        $this->add_entity($entity);

        $courseid = (int)$this->get_parameter('courseid', 0, PARAM_INT);
        if ($courseid > 0) {
            $param = database::generate_param_name();
            $this->add_base_condition_sql("{$alias}.courseid = :{$param}", [$param => $courseid]);
        }

        $this->add_base_fields("{$alias}.id, {$alias}.idnumber, {$alias}.contextid, {$alias}.courseid");

        $this->add_columns();
        $this->add_filters();
        $this->add_actions();

        $this->set_default_per_page(25);
        $this->set_downloadable(true, get_string('threadsincourse', 'filter_embeddiscussion'));
    }

    /**
     * Access check.
     *
     * The page calling this report is responsible for the more specific
     * course-context capability check.
     *
     * @return bool
     */
    protected function can_view(): bool {
        return isloggedin() && !isguestuser();
    }

    /**
     * Default visible columns.
     */
    public function add_columns(): void {
        $this->add_columns_from_entities([
            'thread:name',
            'thread:postcount',
            'thread:lastpost',
            'thread:anonymous',
            'thread:timecreated',
        ]);

        if ($column = $this->get_column('thread:lastpost')) {
            $column->set_is_sortable(true, ['lastpost' => SORT_DESC]);
        }
    }

    /**
     * Default filters.
     */
    protected function add_filters(): void {
        $this->add_filters_from_entities([
            'thread:name',
            'thread:anonymous',
            'thread:timecreated',
        ]);
    }

    /**
     * Per-row actions: link through to the location where the thread is embedded.
     */
    protected function add_actions(): void {
        $this->add_action((new action(
            new moodle_url(
                '/filter/embeddiscussion/index.php',
                ['action' => 'view', 'threadid' => ':id']
            ),
            new pix_icon('i/preview', '', 'core'),
            [],
            false,
            new lang_string('viewthread', 'filter_embeddiscussion')
        )));
    }
}

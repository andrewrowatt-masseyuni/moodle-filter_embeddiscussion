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

/**
 * Course-level page listing all embedded discussion threads.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use core_reportbuilder\system_report_factory;
use filter_embeddiscussion\reportbuilder\local\systemreports\threads;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$threadid = optional_param('threadid', 0, PARAM_INT);

if ($action === 'view' && $threadid) {
    $thread = $DB->get_record('filter_embeddiscussion_thread', ['id' => $threadid], '*', MUST_EXIST);
    $context = context::instance_by_id($thread->contextid, IGNORE_MISSING);
    if ($context) {
        require_login($thread->courseid ?: SITEID, false);
        require_capability(
            'filter/embeddiscussion:managethreads',
            $thread->courseid ? context_course::instance($thread->courseid) : context_system::instance()
        );
        // Best-effort redirect to where this thread lives.
        $url = $context->get_url();
        redirect($url);
    }
}

if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
} else {
    require_login();
    $context = context_system::instance();
}

require_capability('filter/embeddiscussion:managethreads', $context);

$indexurl = new moodle_url('/filter/embeddiscussion/index.php', ['courseid' => $courseid]);
$PAGE->set_url($indexurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout($courseid ? 'incourse' : 'admin');
$PAGE->set_title(get_string('threadsincourse', 'filter_embeddiscussion'));
$PAGE->set_heading(get_string('threadsincourse', 'filter_embeddiscussion'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('threadsincourse', 'filter_embeddiscussion'));

$report = system_report_factory::create(
    threads::class,
    $context,
    '',
    '',
    0,
    ['courseid' => $courseid]
);
echo $report->output();

echo $OUTPUT->footer();

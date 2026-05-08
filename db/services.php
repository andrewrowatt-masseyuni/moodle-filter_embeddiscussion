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
 * Web service definitions for filter_embeddiscussion.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'filter_embeddiscussion_get_thread' => [
        'classname' => 'filter_embeddiscussion\external\get_thread',
        'description' => 'Load an embedded discussion thread by id.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'filter_embeddiscussion_create_post' => [
        'classname' => 'filter_embeddiscussion\external\create_post',
        'description' => 'Create a post or reply in an embedded discussion thread.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'filter_embeddiscussion_edit_post' => [
        'classname' => 'filter_embeddiscussion\external\edit_post',
        'description' => 'Edit an existing post.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'filter_embeddiscussion_delete_post' => [
        'classname' => 'filter_embeddiscussion\external\delete_post',
        'description' => 'Delete a post.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'filter_embeddiscussion_vote_post' => [
        'classname' => 'filter_embeddiscussion\external\vote_post',
        'description' => 'Up- or down-vote a post.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'filter_embeddiscussion_get_dashboard' => [
        'classname' => 'filter_embeddiscussion\external\get_dashboard',
        'description' => 'List embedded discussion posts in a course (latest first) with unread markers.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];

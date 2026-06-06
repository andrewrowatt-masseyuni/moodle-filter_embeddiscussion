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
 * Behat data generator for filter_embeddiscussion.
 *
 * @package    filter_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Maps "Given the following 'filter_embeddiscussion > X' exist:" tables to
 * the phpunit data generator above.
 */
class behat_filter_embeddiscussion_generator extends behat_generator_base {
    /**
     * Entities Behat can create for this component.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'threads' => [
                'singular' => 'thread',
                'datagenerator' => 'thread',
                'required' => ['name', 'course'],
                'switchids' => ['course' => 'courseid'],
                // The 'activity' value is passed through as an idnumber/name when present.
            ],
            'posts' => [
                'singular' => 'post',
                'datagenerator' => 'post',
                'required' => ['thread', 'user', 'content'],
                'switchids' => ['user' => 'userid'],
            ],
            'reactions' => [
                'singular' => 'reaction',
                'datagenerator' => 'reaction',
                'required' => ['thread', 'user', 'postcontent', 'emoji'],
                'switchids' => ['user' => 'userid'],
            ],
        ];
    }
}

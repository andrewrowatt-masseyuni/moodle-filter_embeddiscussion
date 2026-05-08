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

use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * Shared external_description helpers.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {
    /**
     * Description of a single post entry.
     *
     * @return external_single_structure
     */
    public static function post_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Post id'),
            'parentid' => new external_value(PARAM_INT, 'Parent post id (0 for top-level)'),
            'content' => new external_value(PARAM_RAW, 'Sanitised HTML content'),
            'deleted' => new external_value(PARAM_BOOL, 'Whether the post is deleted'),
            'edited' => new external_value(PARAM_BOOL, 'Whether the post has been edited'),
            'timecreated' => new external_value(PARAM_INT, 'Unix timestamp when created'),
            'timecreatediso' => new external_value(PARAM_TEXT, 'Localised date/time'),
            'timecreatedrelative' => new external_value(PARAM_TEXT, 'Relative time string'),
            'authorname' => new external_value(PARAM_TEXT, 'Display name of author'),
            'authorhandle' => new external_value(PARAM_TEXT, 'Anonymous handle, if any'),
            'authorrole' => new external_value(PARAM_TEXT, 'Author role label, if non-student'),
            'isanonymous' => new external_value(PARAM_BOOL, 'True if anonymised for viewer'),
            'profileurl' => new external_value(PARAM_URL, 'Author profile URL', VALUE_OPTIONAL),
            'avatar' => new external_value(PARAM_RAW, 'Avatar HTML (img tag)'),
            'votes_up' => new external_value(PARAM_INT, 'Up vote count'),
            'votes_down' => new external_value(PARAM_INT, 'Down vote count'),
            'votes_my' => new external_value(PARAM_INT, "Viewer's own vote: -1, 0 or 1"),
            'canedit' => new external_value(PARAM_BOOL, 'Viewer can edit this post'),
            'candelete' => new external_value(PARAM_BOOL, 'Viewer can delete this post'),
            'canreply' => new external_value(PARAM_BOOL, 'Viewer can reply'),
        ]);
    }

    /**
     * Description of a single dashboard post entry. Read-only subset of
     * post_structure with a navigation URL added; no votes/edit/delete.
     *
     * @return external_single_structure
     */
    public static function dashboard_post_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Post id'),
            'threadid' => new external_value(PARAM_INT, 'Thread id'),
            'threadname' => new external_value(PARAM_TEXT, 'Thread page title'),
            'content' => new external_value(PARAM_RAW, 'Sanitised HTML content'),
            'deleted' => new external_value(PARAM_BOOL, 'Whether the post is deleted'),
            'edited' => new external_value(PARAM_BOOL, 'Whether the post has been edited'),
            'timecreated' => new external_value(PARAM_INT, 'Unix timestamp when created'),
            'timecreatediso' => new external_value(PARAM_TEXT, 'Localised date/time'),
            'timecreatedrelative' => new external_value(PARAM_TEXT, 'Relative time string'),
            'isunread' => new external_value(PARAM_BOOL, 'True when the post is newer than last course access'),
            'authorname' => new external_value(PARAM_TEXT, 'Display name of author'),
            'authorhandle' => new external_value(PARAM_TEXT, 'Anonymous handle, if any'),
            'authorrole' => new external_value(PARAM_TEXT, 'Author role label, if non-student'),
            'isanonymous' => new external_value(PARAM_BOOL, 'True if anonymised for viewer'),
            'profileurl' => new external_value(PARAM_URL, 'Author profile URL', VALUE_OPTIONAL),
            'avatar' => new external_value(PARAM_RAW, 'Avatar HTML (img tag)'),
            'posturl' => new external_value(PARAM_RAW, 'URL pointing at the post in its host page'),
        ]);
    }

    /**
     * Description of the dashboard payload.
     *
     * @return external_single_structure
     */
    public static function dashboard_structure(): external_single_structure {
        return new external_single_structure([
            'lastaccess' => new external_value(PARAM_INT, 'Unix timestamp of last course access'),
            'lastaccessiso' => new external_value(PARAM_TEXT, 'Localised date/time of last access'),
            'lastaccessrelative' => new external_value(PARAM_TEXT, 'Relative time of last access'),
            'hasitems' => new external_value(PARAM_BOOL, 'True if there is at least one visible post'),
            'neverbefore' => new external_value(PARAM_BOOL, 'True if this is the first course visit'),
            'threadcount' => new external_value(PARAM_INT, 'Number of visible threads with posts'),
            'postcount' => new external_value(PARAM_INT, 'Total number of visible posts'),
            'threads' => new external_multiple_structure(new external_single_structure([
                'threadid' => new external_value(PARAM_INT, 'Thread id'),
                'name' => new external_value(PARAM_TEXT, 'Thread page title'),
                'pageurl' => new external_value(PARAM_RAW, 'Host page URL', VALUE_OPTIONAL),
                'postcount' => new external_value(PARAM_INT, 'Visible posts in this thread'),
                'posts' => new external_multiple_structure(self::dashboard_post_structure()),
            ])),
            'posts' => new external_multiple_structure(self::dashboard_post_structure()),
        ]);
    }

    /**
     * Description of a thread payload.
     *
     * @return external_single_structure
     */
    public static function thread_structure(): external_single_structure {
        return new external_single_structure([
            'threadid' => new external_value(PARAM_INT, 'Thread id'),
            'name' => new external_value(PARAM_TEXT, 'Thread idnumber'),
            'anonymous' => new external_value(PARAM_BOOL, 'Anonymous mode enabled'),
            'currentuserisanonymous' => new external_value(
                PARAM_BOOL,
                'True if the viewer would be shown anonymously to other students'
            ),
            'locked' => new external_value(PARAM_BOOL, 'Locked'),
            'canpost' => new external_value(PARAM_BOOL, 'Viewer can post'),
            'canmanageposts' => new external_value(PARAM_BOOL, 'Viewer can moderate posts'),
            'postcount' => new external_value(PARAM_INT, 'Number of posts'),
            'currentuserid' => new external_value(PARAM_INT, 'Current user id'),
            'currentuseravatar' => new external_value(PARAM_RAW, 'Current user avatar HTML (img tag)'),
            'currentuserprofileurl' => new external_value(PARAM_URL, 'Current user profile URL', VALUE_OPTIONAL),
            'posts' => new external_multiple_structure(self::post_structure()),
        ]);
    }
}

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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use filter_embeddiscussion\manager;

/**
 * Toggle an emoji reaction on a post.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class react_post extends external_api {
    /**
     * Parameters definition.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'postid' => new external_value(PARAM_INT, 'Post id'),
            'emoji' => new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode'),
        ]);
    }

    /**
     * Toggle a reaction and return the post's updated reaction state.
     *
     * @param int $postid
     * @param string $emoji
     * @return array
     */
    public static function execute(int $postid, string $emoji): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'postid' => $postid,
            'emoji' => $emoji,
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
        require_capability('filter/embeddiscussion:createpost', $context);

        if ($post->deleted) {
            throw new \moodle_exception('error_invalidthread', 'filter_embeddiscussion');
        }

        $result = manager::toggle_reaction($params['postid'], $USER->id, $params['emoji']);

        \filter_embeddiscussion\event\post_reacted::create_for_post(
            $post,
            $thread,
            $context,
            $params['emoji'],
            $result['action']
        )->trigger();

        $reactions = manager::get_reactions([$params['postid']], $USER->id);
        $itemreactions = $reactions[$params['postid']];
        $counts = [];
        foreach ($itemreactions['counts'] as $emojicode => $count) {
            $counts[] = ['emoji' => $emojicode, 'count' => $count];
        }

        return [
            'postid' => (int)$post->id,
            'action' => $result['action'],
            'counts' => $counts,
            'userreactions' => array_values($itemreactions['userreactions']),
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
            'action' => new external_value(PARAM_ALPHA, 'Action taken: added or removed'),
            'counts' => new external_multiple_structure(
                new external_single_structure([
                    'emoji' => new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode'),
                    'count' => new external_value(PARAM_INT, 'Reaction count'),
                ])
            ),
            'userreactions' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Emoji shortcode the viewer has reacted with')
            ),
        ]);
    }
}

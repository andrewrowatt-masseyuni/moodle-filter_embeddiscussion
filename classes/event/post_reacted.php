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

namespace filter_embeddiscussion\event;

/**
 * Event fired when a post is reacted to.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class post_reacted extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'filter_embeddiscussion_post';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:post_reacted', 'filter_embeddiscussion');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        $emoji = $this->other['emoji'] ?? '';
        $action = $this->other['action'] ?? '';
        return "The user with id '{$this->userid}' {$action} the '{$emoji}' reaction on post '{$this->objectid}'.";
    }

    /**
     * Factory.
     *
     * @param \stdClass $post
     * @param \stdClass $thread
     * @param \context $context
     * @param string $emoji emoji shortcode
     * @param string $action 'added' or 'removed'
     * @return self
     */
    public static function create_for_post(
        \stdClass $post,
        \stdClass $thread,
        \context $context,
        string $emoji,
        string $action
    ): self {
        return self::create([
            'objectid' => $post->id,
            'context' => $context,
            'other' => [
                'threadid' => (int)$thread->id,
                'emoji' => $emoji,
                'action' => $action,
            ],
        ]);
    }
}

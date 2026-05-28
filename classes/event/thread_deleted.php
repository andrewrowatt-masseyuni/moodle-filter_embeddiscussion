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
 * Event fired when a thread is deleted.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class thread_deleted extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'filter_embeddiscussion_thread';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:thread_deleted', 'filter_embeddiscussion');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' deleted embedded discussion thread '{$this->objectid}'.";
    }

    /**
     * Convenience factory.
     *
     * @param \stdClass $thread
     * @param \context $context
     * @return self
     */
    public static function create_for_thread(\stdClass $thread, \context $context): self {
        return self::create([
            'objectid' => $thread->id,
            'context' => $context,
            'other' => ['idnumber' => $thread->idnumber],
        ]);
    }
}

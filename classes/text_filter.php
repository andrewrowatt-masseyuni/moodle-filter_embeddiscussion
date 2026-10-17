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

namespace filter_embeddiscussion;

use local_embeddiscussion\output\embed;
use local_embeddiscussion\threads;
use local_embeddiscussion\token;

/**
 * Replaces embedded discussion tokens with the discussion.
 *
 * A token is an empty <div data-embeddiscussion="KEY"> naming a thread in local_embeddiscussion,
 * which tiny_embeddiscussion put there. The thread, its name and whether it is anonymous all live in
 * local_embeddiscussion; this filter only finds the thread the token names, in the place the text
 * belongs to, and puts a placeholder there for the discussion to load into.
 *
 * Nothing is ever made here. A token with no thread in this place - copied in from another activity,
 * say - shows a notice to someone who could fix it, and nothing to anyone else.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /**
     * Filter some text.
     *
     * @param string $text The text.
     * @param array $options Filter options.
     * @return string
     */
    public function filter($text, array $options = []) {
        // Cheap enough for every piece of text on every page; nothing below runs without a token.
        if (!is_string($text) || stripos($text, 'data-embeddiscussion') === false) {
            return $text;
        }

        // The plugin that holds the discussions has gone. The tokens carry nothing, so leaving them
        // is harmless, and without local_embeddiscussion there is nothing to render them with.
        if (!get_config('local_embeddiscussion', 'version') || !class_exists(token::class)) {
            return $text;
        }

        return token::replace($text, fn(string $key) => $this->replace($key));
    }

    /**
     * What one token becomes.
     *
     * @param string $key The token's key.
     * @return string HTML, or ''.
     */
    protected function replace(string $key): string {
        // Anywhere a thread cannot belong: nothing. A token there was copied in from somewhere else.
        $threads = $this->threads();
        if ($threads === null) {
            return '';
        }

        $row = $threads[$key] ?? null;
        if (!$row) {
            // Tokens are scoped to their activity, or a section's to its course, so one pasted in
            // from elsewhere does not resolve. Worth telling someone who can fix it; everyone else
            // sees nothing.
            return has_capability('local/embeddiscussion:embed', $this->context) ? embed::render_not_found() : '';
        }

        return embed::render_thread($row);
    }

    /**
     * The threads text in this context can show, keyed by key, or null where no thread can belong.
     *
     * An activity's text shows that activity's threads. Text in a course context is a section
     * summary - or the course summary - and shows any of the course's section threads: a filter is
     * told the context but not which section, so a section's key is scoped to its course.
     * local_embeddiscussion keeps each section's copy of a thread its own.
     *
     * @return array<string, \stdClass>|null
     */
    protected function threads(): ?array {
        if ($this->context instanceof \context_module) {
            $courseid = (int)$this->context->get_course_context()->instanceid;
            return threads::for_cm($courseid, (int)$this->context->instanceid);
        }

        if ($this->context instanceof \context_course && (int)$this->context->instanceid !== (int)SITEID) {
            return threads::for_sections((int)$this->context->instanceid);
        }

        return null;
    }
}

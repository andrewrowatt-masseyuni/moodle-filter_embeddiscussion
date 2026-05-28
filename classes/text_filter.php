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

/**
 * embeddiscussion filter
 *
 * Replaces tokens of the form {discussiondashboard},
 * {discussion[:Thread name]}, {anondiscussion[:Thread name]}
 * and {anonymousdiscussion[:Thread name]}
 * with a skeleton container that the JS module populates asynchronously.
 *
 * In Book chapter pages, the thread name is optional and defaults to the
 * current page name derived from $PAGE->title.
 *
 * Legacy syntaxes can also be recognised and rewritten to the canonical token
 * before processing when enabled via site settings:
 *   - [[filter_disqus]]  -> {discussion}
 *   - {comments}         -> {discussion}
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /** Pattern matches {discussiondashboard}, {discussion}, {discussion:...}, {anondiscussion:...}, {anonymousdiscussion:...}. */
    const PATTERN = '/\{discussiondashboard\}|\{(discussion|anondiscussion|anonymousdiscussion)(?::([^}]*))?\}/i';

    /** Pattern matches the legacy [[filter_disqus]] and {comments} tokens. */
    const LEGACY_PATTERN = '/\[\[filter_disqus\]\]|\{comments\}/i';

    /** @var bool Module/page resources requested. */
    protected static $requirementsdone = false;

    /**
     * Filter text replacing supported tokens with skeleton containers.
     *
     * @param string $text some HTML content to process.
     * @param array $options options passed to the filters
     * @return string the HTML content after the filtering has been applied.
     */
    public function filter($text, array $options = []) {
        global $PAGE, $OUTPUT;

        unset($options);

        if (!\is_string($text)) {
            return $text;
        }

        $disqusenabled = self::legacy_disqus_tokens_enabled();
        $commentsenabled = self::legacy_comments_tokens_enabled();

        // Rewrite any enabled legacy tokens to the canonical token first.
        if (($disqusenabled || $commentsenabled) && preg_match(self::LEGACY_PATTERN, $text)) {
            $text = self::convert_legacy_tokens($text, $disqusenabled, $commentsenabled);
        }

        if (\stripos($text, 'discussion') === false) {
            return $text;
        }

        if (!preg_match(self::PATTERN, $text)) {
            return $text;
        }

        $dashboardused = false;
        $self = $this;

        $text = preg_replace_callback(self::PATTERN, function ($matches) use ($self, $OUTPUT, &$dashboardused) {
            if (strcasecmp($matches[0], '{discussiondashboard}') === 0) {
                $rendered = $self->render_dashboard_placeholder($OUTPUT);
                if ($rendered !== null) {
                    $dashboardused = true;
                    return $rendered;
                }
                return $matches[0];
            }

            $tokentype = strtolower($matches[1] ?? 'discussion');
            $threadname = self::sanitise_thread_name($matches[2] ?? '');
            $anonymous = ($tokentype === 'anondiscussion' || $tokentype === 'anonymousdiscussion');

            $rendered = $self->render_thread_placeholder($threadname, $anonymous, $OUTPUT);
            return $rendered ?? $matches[0];
        }, $text);

        // Request the JS bootstrapper once per page.
        if (!self::$requirementsdone) {
            self::$requirementsdone = true;
            $PAGE->requires->js_call_amd('filter_embeddiscussion/discussion', 'init');
        }
        if ($dashboardused) {
            $PAGE->requires->js_call_amd('filter_embeddiscussion/dashboard', 'init');
        }

        return $text;
    }

     /**
      * Rewrite enabled legacy [[filter_disqus]] / {comments} tokens in text to
      * the canonical {discussion} token.
      *
      * @param string $text the text being filtered
      * @param bool|null $disqusenabled whether [[filter_disqus]] tokens should be converted;
      *                                 null to read site configuration
      * @param bool|null $commentsenabled whether {comments} tokens should be converted;
      *                                   null to read site configuration
      * @return string the text with any legacy tokens rewritten
      */
    public static function convert_legacy_tokens(
        string $text,
        ?bool $disqusenabled = null,
        ?bool $commentsenabled = null
    ): string {
        if ($disqusenabled === null) {
            $disqusenabled = self::legacy_disqus_tokens_enabled();
        }
        if ($commentsenabled === null) {
            $commentsenabled = self::legacy_comments_tokens_enabled();
        }
        if (!$disqusenabled && !$commentsenabled) {
            return $text;
        }

        if ($disqusenabled) {
            $text = preg_replace(
                '/\[\[filter_disqus\]\]/i',
                '{discussion}',
                $text
            );
        }

        if ($commentsenabled) {
            $text = preg_replace(
                '/\{comments\}/i',
                '{discussion}',
                $text
            );
        }

        return $text;
    }

    /**
     * Whether rewriting of [[filter_disqus]] legacy tokens is enabled.
     *
     * @return bool
     */
    protected static function legacy_disqus_tokens_enabled(): bool {
        return !empty(get_config('filter_embeddiscussion', 'legacyfilterdisqus'));
    }

    /**
     * Whether rewriting of {comments} legacy tokens is enabled.
     *
     * @return bool
     */
    protected static function legacy_comments_tokens_enabled(): bool {
        return !empty(get_config('filter_embeddiscussion', 'legacycomments'));
    }

    /**
     * Render a course-feed placeholder ({discussiondashboard}), or null if
     * no enclosing course can be
     * determined and the token should be left untouched.
     *
     * @param object $output the page output renderer
     * @return string|null rendered HTML, or null to keep the original token text
     */
    protected function render_dashboard_placeholder($output): ?string {
        $courseid = $this->derive_current_courseid();
        if ($courseid <= 0) {
            return null;
        }
        return $output->render_from_template('filter_embeddiscussion/dashboard_placeholder', [
            'uid' => 'embeddisc_dashboard_' . $courseid,
            'courseid' => $courseid,
        ]);
    }

     /**
      * Render a discussion placeholder.
      *
      * In Book chapter pages, an omitted thread name falls back to $PAGE->title.
      * In all other contexts, an omitted thread name falls back to
      * "context-<contextid>".
      *
      * @param string $name explicit thread name from the token body
      * @param bool $anonymous whether anonymous mode should be applied
      * @param object $output the page output renderer
      * @return string|null rendered HTML, empty string to remove token, or null
      *                     to keep the original token text
      */
    protected function render_thread_placeholder(
        string $name,
        bool $anonymous,
        $output
    ): ?string {
        $threadname = self::sanitise_thread_name($name);
        if ($threadname === '') {
            $threadname = self::sanitise_thread_name($this->derive_default_thread_name());
            if ($threadname === '') {
                return null;
            }
        }

        // Resolve the thread server-side so the browser only learns the thread id.
        // Anonymous mode is token-authored and never trusted from the client.
        try {
            $thread = manager::get_or_create_thread($threadname, $this->context, $threadname);
            $thread = manager::sync_settings_from_token($thread, [
                'anonymous' => $anonymous,
            ]);
        } catch (\required_capability_exception $e) {
            // The thread does not exist yet and this user cannot initialise one.
            // Surface a friendly notice rather than leaving the raw token visible.
            return $output->render_from_template('filter_embeddiscussion/threaduninitialised', []);
        } catch (\Throwable $e) {
            return null;
        }

        return $output->render_from_template('filter_embeddiscussion/placeholder', [
            'uid' => manager::get_thread_uid($thread->id, $this->context->id),
            'threadid' => (int)$thread->id,
            'contextid' => $this->context->id,
        ]);
    }

    /**
     * Derive the fallback thread name when the token omits an explicit name.
     *
     * In Book contexts this uses $PAGE->title. In all other contexts this uses
     * a stable context-scoped key "context-<contextid>".
     *
     * @return string
     */
    protected function derive_default_thread_name(): string {
        global $PAGE;

        [, , $cm] = get_context_info_array($this->context->id);

        if (($cm->modname ?? '') === 'book' && $PAGE->url->get_path() === '/mod/book/view.php' && ($PAGE->title ?? '') !== '') {
            return $PAGE->title;
        }

        return 'context-' . (int)$this->context->id;
    }

    /**
     * Discover the course id associated with the filtered content. Falls back
     * to the current $PAGE course if the filter context is above CONTEXT_COURSE.
     *
     * @return int 0 if no course context is available
     */
    protected function derive_current_courseid(): int {
        global $PAGE;

        $coursectx = $this->context->get_course_context(false);
        if ($coursectx) {
            return (int)$coursectx->instanceid;
        }
        if (isset($PAGE) && is_object($PAGE) && isset($PAGE->course) && (int)$PAGE->course->id !== SITEID) {
            return (int)$PAGE->course->id;
        }
        return 0;
    }

    /**
     * Remove characters that would prematurely terminate a token.
     *
     * @param string $name the thread name
     * @return string the sanitised thread name
     */
    protected static function sanitise_thread_name(string $name): string {
        // The canonical token ends at the first '}' so strip literal closing braces.
        return trim(str_replace('}', '', $name));
    }
}

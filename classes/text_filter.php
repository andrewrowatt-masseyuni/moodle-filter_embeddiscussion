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
 * Replaces tokens of the form {discussion[:Thread name]},
 * {anondiscussion[:Thread name]} and {anonymousdiscussion[:Thread name]}
 * with a skeleton container that the JS module populates asynchronously.
 *
 * In Book chapter pages, the thread name is optional and defaults to the
 * current page name derived from $PAGE->title.
 *
 * Legacy syntaxes can also be recognised and rewritten to the canonical token
 * before processing when enabled via site settings:
 *   - [[filter_disqus]]  -> {discussion:<page name>}
 *   - {comments}         -> {discussion:<page name>}
 * where <page name> is the current $PAGE->title with any trailing
 * " | <site fullname>" or " | <site shortname>" segment stripped off.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /** Pattern matches {discussion}, {discussion:...}, {anondiscussion:...}, {anonymousdiscussion:...}. */
    const PATTERN = '/\{(discussion|anondiscussion|anonymousdiscussion)(?::([^}]*))?\}/i';

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

        // Capture the host page's URL so threads can be linked back to where they live.
        // Skip if the page never called set_url(): the magic getter would otherwise emit a
        // DEBUG_DEVELOPER notice and fall back to a guessed $FULLME we don't want to store.
        $pageurl = null;
        try {
            if (isset($PAGE) && is_object($PAGE) && $PAGE->has_set_url()) {
                $pageurl = $PAGE->url->out(false);
            }
        } catch (\Throwable $e) {
            $pageurl = null;
        }

        $dashboardused = false;
        $self = $this;

        $text = preg_replace_callback(self::PATTERN, function ($matches) use ($self, $OUTPUT, $pageurl, &$dashboardused) {
            $tokentype = strtolower($matches[1] ?? 'discussion');
            $body = self::sanitise_thread_name($matches[2] ?? '');
            $anonymous = ($tokentype === 'anondiscussion' || $tokentype === 'anonymousdiscussion');

            if ($tokentype === 'discussion' && self::is_course_feed_token($body)) {
                $rendered = $self->render_dashboard_placeholder($OUTPUT);
                if ($rendered !== null) {
                    $dashboardused = true;
                    return $rendered;
                }
                return $matches[0];
            }

            $rendered = $self->render_thread_placeholder($body, $anonymous, $OUTPUT, $pageurl);
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
     * the canonical {discussion:...} token, deriving the thread name from
     * $PAGE->title.
     *
     * If the page title is unavailable (for example, when the filter runs in a
     * context without a fully-initialised page), the legacy tokens are left
     * untouched so that the original text is preserved.
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

        $threadname = self::sanitise_thread_name(self::derive_current_page_name());
        if ($threadname === '') {
            return $text;
        }

        if ($disqusenabled) {
            $text = preg_replace(
                '/\[\[filter_disqus\]\]/i',
                '{discussion:' . $threadname . '}',
                $text
            );
        }

        if ($commentsenabled) {
            $text = preg_replace(
                '/\{comments\}/i',
                '{discussion:' . $threadname . '}',
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
     * Render a course-feed placeholder ({discussion:dashboard} or
     * {discussion:latestposts}), or null if no enclosing course can be
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
     * In Book chapter pages the thread name can be omitted and falls back to
     * derive_current_page_name(). In all other locations, an omitted name is
     * treated as unsupported and either renders a guidance notice (for editors)
     * or nothing (for non-editors).
     *
     * @param string $name explicit thread name from the token body
     * @param bool $anonymous whether anonymous mode should be applied
     * @param object $output the page output renderer
     * @param string|null $pageurl URL of the host page, for back-linking
     * @return string|null rendered HTML, empty string to remove token, or null
     *                     to keep the original token text
     */
    protected function render_thread_placeholder(
        string $name,
        bool $anonymous,
        $output,
        ?string $pageurl
    ): ?string {
        $threadname = self::sanitise_thread_name($name);
        if ($threadname === '') {
            if ($this->can_embed_discussions_here()) {
                $threadname = self::sanitise_thread_name(self::derive_current_page_name());
                if ($threadname === '') {
                    return null;
                }
            } else {
                if ($this->can_display_unsupported_notice()) {
                    return $output->render_from_template('filter_embeddiscussion/cannotbeembeddedhere', []);
                }
                return '';
            }
        }

        // Resolve the thread server-side so the browser only learns the thread id.
        // Anonymous mode is token-authored and never trusted from the client.
        try {
            $thread = manager::get_or_create_thread($threadname, $this->context, $pageurl, $threadname);
            $thread = manager::sync_settings_from_token($thread, [
                'anonymous' => $anonymous,
            ]);
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
     * True when the explicit token body is a recognised course-feed alias.
     *
     * @param string $body raw token body after the leading ':'
     * @return bool
     */
    protected static function is_course_feed_token(string $body): bool {
        $value = trim($body);
        return strcasecmp($value, 'dashboard') === 0 || strcasecmp($value, 'latestposts') === 0;
    }

    /**
     * Whether embedded discussions can be rendered in the current page context.
     *
     * @return bool true only for Book chapter view pages
     */
    protected function can_embed_discussions_here(): bool {
        global $PAGE;

        if (!isset($PAGE) || !is_object($PAGE)) {
            return false;
        }

        // Moodle page uses magic properties; isset() can be unreliable here.
        try {
            $pagecontext = $PAGE->context;
        } catch (\Throwable $e) {
            return false;
        }

        if (!is_object($pagecontext) || empty($pagecontext->id)) {
            return false;
        }

        [, , $cm] = get_context_info_array($pagecontext->id);

        if (!isset($cm) || !is_object($cm)) {
            return false;
        }

        if (($cm->modname ?? '') !== 'book') {
            return false;
        }

        // Moodle page uses magic properties; isset() can be unreliable here.
        try {
            $url = $PAGE->url;
        } catch (\Throwable $e) {
            return false;
        }

        return $url->get_path() === '/mod/book/view.php';
    }

    /**
     * Whether the current user should see unsupported-location guidance.
     *
     * @return bool true when user can edit the enclosing course
     */
    protected function can_display_unsupported_notice(): bool {
        global $PAGE;

        $coursectx = $this->context->get_course_context(false);
        if (!$coursectx && isset($PAGE) && is_object($PAGE)) {
            try {
                $pagecontext = $PAGE->context;
            } catch (\Throwable $e) {
                $pagecontext = null;
            }
            if (is_object($pagecontext)) {
                $coursectx = $pagecontext->get_course_context(false);
            }
        }
        if (!$coursectx) {
            return false;
        }

        return has_capability('moodle/course:manageactivities', $coursectx);
    }

    /**
     * Derive a page title from the current page, with the trailing site name
     * segment stripped. Returns '' if no usable page title is available.
     *
     * @return string the page-derived title
     */
    public static function derive_current_page_name(): string {
        global $PAGE, $SITE;

        $pagetitle = '';
        $sitenames = [];

        if (isset($PAGE) && is_object($PAGE)) {
            $pagetitle = (string) ($PAGE->title ?? '');
        }
        if (isset($SITE) && is_object($SITE)) {
            $sitenames[] = (string) ($SITE->fullname ?? '');
            $sitenames[] = (string) ($SITE->shortname ?? '');
        }

        return self::derive_page_name($pagetitle, $sitenames);
    }

    /**
     * Strip the trailing site fullname or shortname segment from a page title.
     *
     * Moodle pages are titled "<page name> | <site name>" (see
     * moodle_page::TITLE_SEPARATOR), so this removes the trailing
     * " | <site fullname>" or " | <site shortname>" segment if present and
     * returns the leading portion. If neither matches, the trimmed title is
     * returned unchanged.
     *
     * @param string $pagetitle the raw $PAGE->title value
     * @param array $sitenames candidate site name strings (fullname, shortname)
     * @return string the page name with any site name suffix removed
     */
    public static function derive_page_name(string $pagetitle, array $sitenames): string {
        $pagetitle = trim($pagetitle);
        if ($pagetitle === '') {
            return '';
        }

        $separator = ' | ';
        foreach ($sitenames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $suffix = $separator . $name;
            $suffixlen = strlen($suffix);
            if (strlen($pagetitle) > $suffixlen && substr($pagetitle, -$suffixlen) === $suffix) {
                return trim(substr($pagetitle, 0, -$suffixlen));
            }
        }

        return $pagetitle;
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

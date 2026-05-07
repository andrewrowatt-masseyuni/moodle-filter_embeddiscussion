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
 * Replaces tokens of the form {embeddeddiscussion:Name of thread[,keyword...]}
 * with a skeleton container that the JS module populates asynchronously.
 *
 * The thread name is optional. If the body contains no name (only keywords, or
 * nothing at all), the thread name defaults to the current page name — the same
 * derivation as for {comments}. All of these resolve to the page name:
 *   - {embeddiscussion}
 *   - {embeddiscussion,anon}
 *   - {embeddiscussion,locked}
 *   - {embeddiscussion:anon}
 *   - {embeddiscussion:locked,anon}
 *
 * Optional trailing keywords (case-insensitive, any order):
 *   - lock | locked     - the thread is locked (no new posts or edits).
 *   - anon | anonymous  - student posts are shown with anonymous handles.
 *
 * Legacy syntaxes (drop-in replacement for filter_disqus and the {comments}
 * block) can also be recognised and rewritten to the canonical token before
 * processing when enabled via site settings:
 *   - [[filter_disqus]]                 -> {embeddiscussion:<page name>}
 *   - [[filter_disqus:<url_segment>]]   -> {embeddiscussion:<page name> (<url_segment>)}
 *   - {comments}                        -> {embeddiscussion:<page name>}
 * where <page name> is the current $PAGE->title with any trailing
 * " | <site fullname>" or " | <site shortname>" segment stripped off.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /**
     * Pattern matches both {embeddeddiscussion..} and {embeddiscussion..} with an
     * optional body introduced by ':' (explicit name) or ',' (keywords only, name
     * defaults to the page title).
     */
    const PATTERN = '/\{embedd(?:eddi|i)scussion([:,][^}]*)?\}/i';

    /** Pattern matches the legacy [[filter_disqus]] / [[filter_disqus:segment]] / {comments} tokens. */
    const LEGACY_PATTERN = '/\[\[filter_disqus(?::([^\]]*))?\]\]|\{comments\}/i';

    /** @var bool Module/page resources requested. */
    protected static $requirementsdone = false;

    /**
     * Filter text replacing the token with a skeleton container.
     *
     * @param string $text some HTML content to process.
     * @param array $options options passed to the filters
     * @return string the HTML content after the filtering has been applied.
     */
    public function filter($text, array $options = []) {
        global $PAGE, $OUTPUT;

        if (!\is_string($text)) {
            return $text;
        }

        $disqusenabled = self::legacy_disqus_tokens_enabled();
        $commentsenabled = self::legacy_comments_tokens_enabled();

        // Rewrite any enabled legacy tokens to the canonical token first.
        if (($disqusenabled || $commentsenabled) && preg_match(self::LEGACY_PATTERN, $text)) {
            $text = self::convert_legacy_tokens($text, $disqusenabled, $commentsenabled);
        }

        if (\stripos($text, 'iscussion') === false) {
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
            $captured = $matches[1] ?? '';
            // A leading ':' introduces an explicit name; a leading ',' starts the keyword
            // list with no name. Strip a leading ':' so parse_token_body sees only the body;
            // a leading ',' is left in place so parse_token_body returns an empty name and
            // we fall back to the current page title below.
            $hascolon = ($captured !== '' && $captured[0] === ':');
            $body = $hascolon ? substr($captured, 1) : $captured;

            if ($hascolon && self::is_course_feed_token($body)) {
                $rendered = $self->render_dashboard_placeholder($OUTPUT);
                if ($rendered !== null) {
                    $dashboardused = true;
                    return $rendered;
                }
                return $matches[0];
            }

            $rendered = $self->render_thread_placeholder($body, $hascolon, $OUTPUT, $pageurl);
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
     * Parse the body of an embeddiscussion token into a name and trailing keywords.
     *
     * The body is split on commas. Trailing parts that match a recognised keyword
     * (lock/locked/anon/anonymous, case-insensitive) or are empty are stripped
     * from the right; the remaining parts are rejoined with ", " to form the name.
     * Spaces around delimiters are trimmed; keyword order is unimportant. A body
     * consisting entirely of keywords (e.g. "anon", "locked,anon") yields an
     * empty name — the caller can then fall back to a default such as the page
     * title.
     *
     * @param string $body the text between "embeddeddiscussion:" and "}"
     * @return array{name: string, anonymous: bool, locked: bool}
     */
    public static function parse_token_body(string $body): array {
        $anonymous = false;
        $locked = false;

        $parts = array_map('trim', explode(',', $body));

        // Strip trailing empties and recognised keywords from the right.
        while (!empty($parts)) {
            $tail = end($parts);
            if ($tail === '') {
                array_pop($parts);
                continue;
            }
            $key = strtolower($tail);
            if ($key === 'lock' || $key === 'locked') {
                $locked = true;
                array_pop($parts);
                continue;
            }
            if ($key === 'anon' || $key === 'anonymous') {
                $anonymous = true;
                array_pop($parts);
                continue;
            }
            break;
        }

        $name = trim(implode(', ', $parts));
        return ['name' => $name, 'anonymous' => $anonymous, 'locked' => $locked];
    }

    /**
     * Rewrite enabled legacy filter_disqus / {comments} tokens in text to the
     * canonical {embeddiscussion:...} token, deriving the thread name from
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

        $pagename = self::derive_current_page_name();
        if ($pagename === '') {
            return $text;
        }

        if ($disqusenabled) {
            $text = preg_replace_callback(
                '/\[\[filter_disqus(?::([^\]]*))?\]\]/i',
                function ($matches) use ($pagename) {
                    $segment = isset($matches[1]) ? trim($matches[1]) : '';
                    $threadname = $segment !== '' ? $pagename . ' (' . $segment . ')' : $pagename;
                    return '{embeddiscussion:' . $threadname . '}';
                },
                $text
            );
        }

        if ($commentsenabled) {
            $text = preg_replace_callback(
                '/\{comments\}/i',
                function () use ($pagename) {
                    return '{embeddiscussion:' . $pagename . '}';
                },
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
     * Render a course-feed placeholder ({embeddiscussion:dashboard} or
     * {embeddiscussion:latestposts}), or null if no enclosing course can be
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
     * Render a {embeddiscussion[:Name][,keywords]} thread placeholder. Returns
     * null when the body parses to an empty name with no keywords (a malformed
     * token to preserve verbatim) or when the thread cannot be resolved.
     *
     * @param string $body raw token body (without the leading ':' if any)
     * @param bool $hascolon true if the original token used the explicit-name
     *                       ':' separator rather than the keyword-only ',' form
     * @param object $output the page output renderer
     * @param string|null $pageurl URL of the host page, for back-linking
     * @return string|null rendered HTML, or null to keep the original token text
     */
    protected function render_thread_placeholder(
        string $body,
        bool $hascolon,
        $output,
        ?string $pageurl
    ): ?string {
        $parsed = self::parse_token_body($body);
        if ($parsed['name'] === '') {
            $haskeyword = $parsed['anonymous'] || $parsed['locked'];
            if ($hascolon && !$haskeyword) {
                // Explicit empty name with no keywords (e.g. "{embeddiscussion:}") —
                // preserve the broken token rather than silently changing it.
                return null;
            }
            $parsed['name'] = self::derive_current_page_name();
            if ($parsed['name'] === '') {
                return null;
            }
        }
        // Resolve the thread server-side so the browser only learns the thread id.
        // anonymous/locked are token-authored settings — never trust them from the client.
        try {
            $thread = manager::get_or_create_thread($parsed['name'], $this->context, $pageurl);
            $thread = manager::sync_settings_from_token($thread, [
                'anonymous' => $parsed['anonymous'],
                'locked' => $parsed['locked'],
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
     * Derive a thread name from the current page title, with the trailing site
     * name segment stripped and characters that would break the canonical token
     * removed. Returns '' if no usable page title is available.
     *
     * @return string the sanitised page-derived thread name
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

        return self::sanitise_thread_name(self::derive_page_name($pagetitle, $sitenames));
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
     * Remove characters that would prematurely terminate the canonical token or
     * be misinterpreted by parse_token_body() when a thread name is composed
     * from a page title.
     *
     * @param string $name the thread name being built from page metadata
     * @return string the sanitised thread name
     */
    protected static function sanitise_thread_name(string $name): string {
        // The canonical token ends at the first '}' so strip any literal closing brace.
        // Commas would otherwise cause parse_token_body() to look for trailing keywords.
        return trim(str_replace(['}', ','], ['', ' '], $name));
    }
}

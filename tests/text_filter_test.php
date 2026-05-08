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
 * Tests for the text filter.
 *
 * @package    filter_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \filter_embeddiscussion\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /**
     * Configure legacy token conversion settings for this test.
     *
     * @param bool $disqusenabled whether [[filter_disqus]] conversion is enabled
     * @param bool $commentsenabled whether {comments} conversion is enabled
     */
    protected function configure_legacy_token_handling(bool $disqusenabled, bool $commentsenabled): void {
        set_config('legacyfilterdisqus', $disqusenabled ? 1 : 0, 'filter_embeddiscussion');
        set_config('legacycomments', $commentsenabled ? 1 : 0, 'filter_embeddiscussion');
    }

    public function test_filter_no_token_passes_through(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $input = '<p>Hello world</p>';
        $this->assertSame($input, $filter->filter($input));
    }

    public function test_filter_replaces_token_with_skeleton(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {embeddeddiscussion:My thread} after');
        $thread = manager::find_thread('My thread', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame(0, (int)$thread->anonymous);
        $this->assertSame(0, (int)$thread->locked);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
        $this->assertStringNotContainsString('data-thread-name', $output);
        $this->assertStringNotContainsString('data-anonymous', $output);
        $this->assertStringNotContainsString('data-locked', $output);
        $this->assertStringContainsString('Before', $output);
        $this->assertStringContainsString('after', $output);
    }

    public function test_filter_supports_alternate_spelling(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddiscussion:Alt}');
        $thread = manager::find_thread('Alt', $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_stores_pagetitle_for_explicit_idnumber(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Lesson 2 | Acceptance test site', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $filter->filter('{embeddiscussion:lesson-2}');
        $thread = manager::find_thread('lesson-2', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Lesson 2', (string)$thread->pagetitle);
    }

    public function test_filter_handles_multiple_tokens(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddeddiscussion:A} mid {embeddeddiscussion:B}');
        $this->assertSame(2, substr_count($output, 'data-region="filter-embeddiscussion"'));
        $threada = manager::find_thread('A', $context->id);
        $threadb = manager::find_thread('B', $context->id);
        $this->assertNotNull($threada);
        $this->assertNotNull($threadb);
        $this->assertStringContainsString('data-threadid="' . (int)$threada->id . '"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$threadb->id . '"', $output);
    }

    public function test_filter_renders_latestposts_placeholder_in_course_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $context = \context_course::instance($course->id);
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddeddiscussion:latestposts}');
        $this->assertStringContainsString('data-region="filter-embeddiscussion-dashboard"', $output);
        $this->assertStringContainsString('data-courseid="' . (int)$course->id . '"', $output);
    }

    public function test_filter_ignores_empty_name(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $input = '{embeddeddiscussion:}';
        $this->assertSame($input, $filter->filter($input));
    }

    public function test_filter_defaults_thread_name_to_page_name(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {embeddiscussion} after');
        $thread = manager::find_thread('course-course-1', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Course: Course 1', (string)$thread->pagetitle);
        $this->assertSame(0, (int)$thread->anonymous);
        $this->assertSame(0, (int)$thread->locked);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_defaults_name_with_keyword_only_token(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddiscussion,anonymous,locked}');
        $thread = manager::find_thread('course-course-1', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Course: Course 1', (string)$thread->pagetitle);
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertSame(1, (int)$thread->locked);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_defaults_name_works_with_alternate_spelling(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddeddiscussion}');
        $thread = manager::find_thread('course-course-1', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Course: Course 1', (string)$thread->pagetitle);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_leaves_nameless_token_when_page_title_unavailable(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_title('', false);
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $input = '{embeddiscussion,anon}';
        $this->assertSame($input, $filter->filter($input));
    }

    public function test_filter_defaults_name_with_colon_single_keyword_token(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddiscussion:anon}');
        $thread = manager::find_thread('course-course-1', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Course: Course 1', (string)$thread->pagetitle);
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertSame(0, (int)$thread->locked);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_defaults_name_with_colon_multiple_keywords_token(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddiscussion:locked,anon}');
        $thread = manager::find_thread('course-course-1', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Course: Course 1', (string)$thread->pagetitle);
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertSame(1, (int)$thread->locked);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_persists_anonymous_and_locked_settings_server_side(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{embeddeddiscussion:Demo,anonymous,locked}');
        $thread = manager::find_thread('Demo', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertSame(1, (int)$thread->locked);
        // The browser must only learn the thread id; flags are authoritative on the server.
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
        $this->assertStringNotContainsString('data-anonymous', $output);
        $this->assertStringNotContainsString('data-locked', $output);
    }

    /**
     * Verify keyword/name parsing from the bracketed token body.
     *
     * @dataProvider parse_token_body_provider
     * @param string $body the text inside the braces (after the prefix)
     * @param array $expected expected ['name' => string, 'anonymous' => bool, 'locked' => bool]
     */
    public function test_parse_token_body(string $body, array $expected): void {
        $this->assertSame($expected, text_filter::parse_token_body($body));
    }

    /**
     * Verify that the trailing site name is stripped from a page title.
     *
     * @dataProvider derive_page_name_provider
     * @param string $pagetitle the simulated $PAGE->title value
     * @param array $sitenames candidate site name strings (fullname, shortname)
     * @param string $expected the expected derived page name
     */
    public function test_derive_page_name(string $pagetitle, array $sitenames, string $expected): void {
        $this->assertSame($expected, text_filter::derive_page_name($pagetitle, $sitenames));
    }

    /**
     * Cases for derive_page_name covering site-suffix stripping behaviour.
     *
     * @return array
     */
    public static function derive_page_name_provider(): array {
        return [
            'fullname suffix stripped' => [
                'Celebrating Cultures | Interesting cities | Mount Orange',
                ['Mount Orange', 'mountorange'],
                'Celebrating Cultures | Interesting cities',
            ],
            'shortname suffix stripped when fullname does not match' => [
                'Celebrating Cultures | Interesting cities | mountorange',
                ['Mount Orange', 'mountorange'],
                'Celebrating Cultures | Interesting cities',
            ],
            'no separator means no strip' => [
                'Celebrating Cultures',
                ['Mount Orange'],
                'Celebrating Cultures',
            ],
            'no match keeps title as-is' => [
                'Celebrating Cultures | Some Other Site',
                ['Mount Orange', 'mountorange'],
                'Celebrating Cultures | Some Other Site',
            ],
            'empty title returns empty' => [
                '',
                ['Mount Orange'],
                '',
            ],
            'whitespace-only title returns empty' => [
                '   ',
                ['Mount Orange'],
                '',
            ],
            'empty site names returns trimmed title' => [
                '  Just a page  ',
                ['', '   '],
                'Just a page',
            ],
            'trailing space around separator handled by trim' => [
                'Page name | Mount Orange   ',
                ['Mount Orange'],
                'Page name',
            ],
        ];
    }

    public function test_convert_legacy_tokens_basic_disqus(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $output = text_filter::convert_legacy_tokens('Before [[filter_disqus]] after');
        $this->assertSame('Before {embeddiscussion:Course: Course 1} after', $output);
    }

    public function test_convert_legacy_tokens_disqus_with_segment(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $output = text_filter::convert_legacy_tokens('[[filter_disqus:book-23]]');
        $this->assertSame('{embeddiscussion:Course: Course 1 (book-23)}', $output);
    }

    public function test_convert_legacy_tokens_comments(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, true);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $output = text_filter::convert_legacy_tokens('Comments here: {comments}');
        $this->assertSame('Comments here: {embeddiscussion:Course: Course 1}', $output);
    }

    public function test_convert_legacy_tokens_strips_site_suffix(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Celebrating Cultures | Interesting cities | Mount Orange', false);
        $SITE->fullname = 'Mount Orange';
        $SITE->shortname = 'mountorange';
        $output = text_filter::convert_legacy_tokens('[[filter_disqus]]');
        $this->assertSame('{embeddiscussion:Celebrating Cultures | Interesting cities}', $output);
    }

    public function test_convert_legacy_tokens_leaves_text_when_title_empty(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, true);
        $PAGE->set_title('', false);
        $input = 'Untouched [[filter_disqus]] {comments}';
        $this->assertSame($input, text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_leaves_text_when_both_options_disabled(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $input = 'Untouched [[filter_disqus]] {comments}';
        $this->assertSame($input, text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_handles_disqus_only_when_enabled(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $input = 'A [[filter_disqus]] B {comments}';
        $this->assertSame('A {embeddiscussion:Course: Course 1} B {comments}', text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_handles_comments_only_when_enabled(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, true);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $input = 'A [[filter_disqus]] B {comments}';
        $this->assertSame('A [[filter_disqus]] B {embeddiscussion:Course: Course 1}', text_filter::convert_legacy_tokens($input));
    }

    public function test_filter_renders_legacy_disqus_token(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('Before [[filter_disqus]] after');
        $thread = manager::find_thread('Course: Course 1', $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_renders_legacy_disqus_segment_token(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('[[filter_disqus:book-23]]');
        $thread = manager::find_thread('Course: Course 1 (book-23)', $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_renders_legacy_comments_token(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, true);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('{comments}');
        $thread = manager::find_thread('Course: Course 1', $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_leaves_legacy_tokens_untouched_by_default(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $input = 'Before [[filter_disqus]] and {comments} after';
        $output = $filter->filter($input);
        $this->assertSame($input, $output);
    }

    /**
     * Cases for parse_token_body covering keyword combinations and edge cases.
     *
     * @return array
     */
    public static function parse_token_body_provider(): array {
        return [
            'plain name' => [
                'Evaluating Premises - Māramatanga - Understanding',
                ['name' => 'Evaluating Premises - Māramatanga - Understanding', 'anonymous' => false, 'locked' => false],
            ],
            'anon only' => [
                'Evaluating Premises - Māramatanga - Understanding,anonymous',
                ['name' => 'Evaluating Premises - Māramatanga - Understanding', 'anonymous' => true, 'locked' => false],
            ],
            'anon then locked' => [
                'Evaluating Premises - Māramatanga - Understanding,anonymous,locked',
                ['name' => 'Evaluating Premises - Māramatanga - Understanding', 'anonymous' => true, 'locked' => true],
            ],
            'locked then anon' => [
                'Evaluating Premises - Māramatanga - Understanding,locked,anonymous',
                ['name' => 'Evaluating Premises - Māramatanga - Understanding', 'anonymous' => true, 'locked' => true],
            ],
            'name with commas plus keywords' => [
                'Evaluating, understanding, and reviewing premises,anonymous,locked',
                ['name' => 'Evaluating, understanding, and reviewing premises', 'anonymous' => true, 'locked' => true],
            ],
            'name with commas plus short keywords with extra spaces' => [
                'Evaluating, understanding, and reviewing premises, anon  ,  lock',
                ['name' => 'Evaluating, understanding, and reviewing premises', 'anonymous' => true, 'locked' => true],
            ],
            'trailing comma trimmed' => [
                'Evaluating, understanding, and reviewing premises,',
                ['name' => 'Evaluating, understanding, and reviewing premises', 'anonymous' => false, 'locked' => false],
            ],
            'mixed case keywords' => [
                'Demo,LOCKED,Anon',
                ['name' => 'Demo', 'anonymous' => true, 'locked' => true],
            ],
            'short forms only' => [
                'Demo,lock,anon',
                ['name' => 'Demo', 'anonymous' => true, 'locked' => true],
            ],
            'unknown trailing word stays in name' => [
                'Demo, unknown',
                ['name' => 'Demo, unknown', 'anonymous' => false, 'locked' => false],
            ],
            'empty body has empty name' => [
                '',
                ['name' => '', 'anonymous' => false, 'locked' => false],
            ],
            'leading comma keyword has empty name' => [
                ',anon',
                ['name' => '', 'anonymous' => true, 'locked' => false],
            ],
            'leading comma both keywords has empty name' => [
                ',anonymous,locked',
                ['name' => '', 'anonymous' => true, 'locked' => true],
            ],
            'single keyword anon has empty name' => [
                'anon',
                ['name' => '', 'anonymous' => true, 'locked' => false],
            ],
            'single keyword locked has empty name' => [
                'locked',
                ['name' => '', 'anonymous' => false, 'locked' => true],
            ],
            'single keyword full word anonymous has empty name' => [
                'anonymous',
                ['name' => '', 'anonymous' => true, 'locked' => false],
            ],
        ];
    }
}

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
 * PHPUnit tests for filter_embeddiscussion text filtering.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
     * Create a filter instance that treats the current page as a Book chapter.
     *
     * @param \context $context
     * @return text_filter
     */
    protected function create_book_filter(\context $context): text_filter {
        return new class ($context, []) extends text_filter {
            /**
             * Always allow default thread names for tests that emulate Book pages.
             *
             * @return bool
             */
            protected function can_embed_discussions_here(): bool {
                return true;
            }
        };
    }

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

    /**
     * Return the persisted thread name across schema variants.
     *
     * @param \stdClass $thread
     * @return string
     */
    protected function get_thread_name(\stdClass $thread): string {
        return (string)($thread->threadname ?? ($thread->pagetitle ?? ''));
    }

    public function test_filter_no_token_passes_through(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $input = '<p>Hello world</p>';
        $this->assertSame($input, $filter->filter($input));
    }

    public function test_filter_replaces_discussion_token_with_skeleton(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('Before {discussion:My thread} after');
        $thread = manager::find_thread('My thread', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame(0, (int)$thread->anonymous);
        $this->assertSame('My thread', $this->get_thread_name($thread));
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
        $this->assertStringNotContainsString('data-thread-name', $output);
        $this->assertStringNotContainsString('data-anonymous', $output);
        $this->assertStringContainsString('Before', $output);
        $this->assertStringContainsString('after', $output);
    }

    public function test_filter_supports_anondiscussion_token(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('{anondiscussion:Alt}');
        $thread = manager::find_thread('Alt', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_supports_anonymousdiscussion_alias(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('{anonymousdiscussion:Alias}');
        $thread = manager::find_thread('Alias', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_stores_threadname_for_explicit_name(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $filter->filter('{discussion:lesson-2}');
        $thread = manager::find_thread('lesson-2', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('lesson-2', $this->get_thread_name($thread));
    }

    public function test_filter_handles_multiple_tokens(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('{discussion:A} mid {anondiscussion:B}');
        $this->assertSame(2, substr_count($output, 'data-region="filter-embeddiscussion"'));
        $threada = manager::find_thread('A', $context->id);
        $threadb = manager::find_thread('B', $context->id);
        $this->assertNotNull($threada);
        $this->assertNotNull($threadb);
        $this->assertSame(0, (int)$threada->anonymous);
        $this->assertSame(1, (int)$threadb->anonymous);
        $this->assertStringContainsString('data-threadid="' . (int)$threada->id . '"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$threadb->id . '"', $output);
    }

    public function test_filter_renders_latestposts_placeholder_in_course_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $context = \context_course::instance($course->id);
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('{discussion:latestposts}');
        $this->assertStringContainsString('data-region="filter-embeddiscussion-dashboard"', $output);
        $this->assertStringContainsString('data-courseid="' . (int)$course->id . '"', $output);
    }

    public function test_filter_defaults_thread_name_to_page_name_in_book_context(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('Before {discussion} after');
        $thread = manager::find_thread('Course: Course 1', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Course: Course 1', $this->get_thread_name($thread));
        $this->assertSame(0, (int)$thread->anonymous);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_defaults_anonymous_thread_name_to_page_name_in_book_context(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('{anondiscussion}');
        $thread = manager::find_thread('Course: Course 1', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('Course: Course 1', $this->get_thread_name($thread));
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_leaves_nameless_token_when_page_title_unavailable_in_book_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $PAGE->set_title('', false);
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $input = '{discussion}';
        $this->assertSame($input, $filter->filter($input));
    }

    public function test_filter_removes_nameless_token_outside_book_for_non_editor(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {discussion} after');

        $this->assertStringNotContainsString('data-region="filter-embeddiscussion-unsupported"', $output);
        $this->assertStringNotContainsString(get_string('cannotbeembeddedhere', 'filter_embeddiscussion'), $output);
        $this->assertSame('Before  after', $output);
        $this->assertNull(manager::find_thread('discussion', $context->id));
    }

    public function test_filter_shows_unsupported_notice_to_course_editor_when_name_missing_outside_book(): void {
        global $PAGE;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $this->setUser($user);

        $coursecontext = \context_course::instance($course->id);
        $PAGE->set_course($course);
        $PAGE->set_context($coursecontext);
        $filter = new text_filter($coursecontext, []);
        $output = $filter->filter('Before {discussion} after');

        $this->assertStringContainsString('data-region="filter-embeddiscussion-unsupported"', $output);
        $this->assertStringContainsString(get_string('cannotbeembeddedhere', 'filter_embeddiscussion'), $output);
        $this->assertNull(manager::find_thread('Course: Course 1', $coursecontext->id));
    }

    public function test_filter_renders_named_discussion_token_outside_book_context(): void {
        $this->resetAfterTest();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {discussion:My thread} after');

        $thread = manager::find_thread('My thread', $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
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
        $this->assertSame('Before {discussion:Course: Course 1} after', $output);
    }

    public function test_convert_legacy_tokens_does_not_convert_disqus_with_segment(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $input = '[[filter_disqus:book-23]]';
        $this->assertSame($input, text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_comments(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, true);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $output = text_filter::convert_legacy_tokens('Comments here: {comments}');
        $this->assertSame('Comments here: {discussion:Course: Course 1}', $output);
    }

    public function test_convert_legacy_tokens_strips_site_suffix(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Celebrating Cultures | Interesting cities | Mount Orange', false);
        $SITE->fullname = 'Mount Orange';
        $SITE->shortname = 'mountorange';
        $output = text_filter::convert_legacy_tokens('[[filter_disqus]]');
        $this->assertSame('{discussion:Celebrating Cultures | Interesting cities}', $output);
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
        $this->assertSame('A {discussion:Course: Course 1} B {comments}', text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_handles_comments_only_when_enabled(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, true);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $input = 'A [[filter_disqus]] B {comments}';
        $this->assertSame('A [[filter_disqus]] B {discussion:Course: Course 1}', text_filter::convert_legacy_tokens($input));
    }

    public function test_filter_renders_legacy_disqus_token(): void {
        global $PAGE, $SITE;
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $PAGE->set_title('Course: Course 1', false);
        $SITE->fullname = 'Acceptance test site';
        $SITE->shortname = 'Acceptance test site';
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('Before [[filter_disqus]] after');
        $thread = manager::find_thread('Course: Course 1', $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
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
        $filter = $this->create_book_filter($context);
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
        $filter = $this->create_book_filter($context);
        $input = 'Before [[filter_disqus]] and {comments} after';
        $output = $filter->filter($input);
        $this->assertSame($input, $output);
    }
}

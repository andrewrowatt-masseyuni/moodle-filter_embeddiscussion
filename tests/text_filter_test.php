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
     * Create a filter instance for the given context.
     *
     * @param \context $context
     * @return text_filter
     */
    protected function create_book_filter(\context $context): text_filter {
        return new text_filter($context, []);
    }

    /**
     * Prohibit filter/embeddiscussion:createthread for the student role in a context.
     *
     * Students are granted this capability by default (for rollover scenarios), so a
     * test that needs to exercise the "user lacks createthread" path must remove it
     * explicitly rather than relying on the archetype default.
     *
     * @param \context $context
     */
    protected function prohibit_createthread_for_students(\context $context): void {
        global $DB;
        $studentroleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability('filter/embeddiscussion:createthread', CAP_PROHIBIT, $studentroleid, $context->id, true);
        $context->mark_dirty();
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
     * Return the persisted thread name.
     *
     * @param \stdClass $thread
     * @return string
     */
    protected function get_thread_name(\stdClass $thread): string {
        return (string)($thread->threadname ?? '');
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
        $this->setAdminUser();
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
        $this->setAdminUser();
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
        $this->setAdminUser();
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
        $this->setAdminUser();
        $context = \context_system::instance();
        $filter = $this->create_book_filter($context);
        $filter->filter('{discussion:lesson-2}');
        $thread = manager::find_thread('lesson-2', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame('lesson-2', $this->get_thread_name($thread));
    }

    public function test_filter_handles_multiple_tokens(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
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

    public function test_filter_renders_discussiondashboard_placeholder_in_course_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $PAGE->set_course($course);
        $context = \context_course::instance($course->id);
        $filter = $this->create_book_filter($context);
        $output = $filter->filter('{discussiondashboard}');
        $this->assertStringContainsString('data-region="filter-embeddiscussion-dashboard"', $output);
        $this->assertStringContainsString('data-courseid="' . (int)$course->id . '"', $output);
    }

    public function test_filter_defaults_thread_name_to_book_and_chapter_in_book_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Book A',
        ]);
        $bookgen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 1', 'pagenum' => 1]);
        $context = \context_module::instance($book->cmid);

        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/book/view.php', ['id' => $book->cmid]));

        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {discussion} after');
        $threadname = get_string('bookchapterthreadname', 'filter_embeddiscussion', (object)[
            'book' => 'Book A',
            'chapter' => 'Chapter 1',
        ]);
        $thread = manager::find_thread($threadname, $context->id);
        $this->assertNotNull($thread);
        $this->assertSame($threadname, $this->get_thread_name($thread));
        $this->assertSame(0, (int)$thread->anonymous);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_defaults_anonymous_thread_name_to_book_and_chapter_in_book_context(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Book A',
        ]);
        $bookgen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 1', 'pagenum' => 1]);
        $context = \context_module::instance($book->cmid);

        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/book/view.php', ['id' => $book->cmid]));

        $filter = new text_filter($context, []);
        $output = $filter->filter('{anondiscussion}');
        $threadname = get_string('bookchapterthreadname', 'filter_embeddiscussion', (object)[
            'book' => 'Book A',
            'chapter' => 'Chapter 1',
        ]);
        $thread = manager::find_thread($threadname, $context->id);
        $this->assertNotNull($thread);
        $this->assertSame($threadname, $this->get_thread_name($thread));
        $this->assertSame(1, (int)$thread->anonymous);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_requires_name_in_book_without_resolvable_chapter(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        // A Book with no chapters cannot resolve a chapter title, so it is not a
        // Book chapter for naming purposes and an explicit thread name is required.
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Book A',
        ]);
        $context = \context_module::instance($book->cmid);

        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/book/view.php', ['id' => $book->cmid]));

        $filter = new text_filter($context, []);
        $output = $filter->filter('{discussion}');

        // No thread is created and the staff "name required" notice is shown.
        $this->assertNull(manager::find_thread('context-' . (int)$context->id, $context->id));
        $this->assertStringNotContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-region="filter-embeddiscussion-namerequired"', $output);
        $this->assertStringContainsString('A thread name is required', $output);
    }

    public function test_filter_shows_uninitialised_notice_when_user_lacks_createthread_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context = \context_course::instance($course->id);
        $this->prohibit_createthread_for_students($context);
        $this->setUser($student);

        // A named token whose thread does not exist yet: the student lacks
        // filter/embeddiscussion:createthread so cannot initialise it, and sees a
        // student-friendly notice instead of the raw token.
        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {discussion:Week 1} after');

        $this->assertNull(manager::find_thread('Week 1', $context->id));
        $this->assertStringNotContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-region="filter-embeddiscussion-uninitialised"', $output);
        $this->assertStringContainsString('advise your teaching team', $output);
        $this->assertStringNotContainsString('{discussion', $output);
    }

    public function test_filter_requires_name_outside_book_shows_student_message(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context = \context_course::instance($course->id);
        $this->setUser($student);

        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {discussion} after');

        // A student (no moodle/course:manageactivities) gets the generic notice
        // and no thread is created from the nameless token.
        $this->assertNull(manager::find_thread('context-' . (int)$context->id, $context->id));
        $this->assertStringNotContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-region="filter-embeddiscussion-uninitialised"', $output);
        $this->assertStringContainsString('advise your teaching team', $output);
    }

    public function test_filter_renders_existing_thread_for_student_without_createthread(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context = \context_course::instance($course->id);
        $this->prohibit_createthread_for_students($context);

        // An editor visits first and initialises the thread.
        $this->setAdminUser();
        $filter = new text_filter($context, []);
        $filter->filter('{discussion:Existing}');
        $thread = manager::find_thread('Existing', $context->id);
        $this->assertNotNull($thread);

        // The student now sees the rendered placeholder for the existing thread.
        $this->setUser($student);
        $studentfilter = new text_filter($context, []);
        $output = $studentfilter->filter('{discussion:Existing}');
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_requires_name_outside_book_shows_staff_message(): void {
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

        // An editing teacher (moodle/course:manageactivities) is told a name is
        // required; no thread is created from the nameless token.
        $this->assertNull(manager::find_thread('context-' . (int)$coursecontext->id, $coursecontext->id));
        $this->assertStringNotContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-region="filter-embeddiscussion-namerequired"', $output);
        $this->assertStringContainsString('A thread name is required', $output);
        $this->assertStringContainsString('Before', $output);
        $this->assertStringContainsString('after', $output);
    }

    public function test_filter_renders_named_discussion_token_outside_book_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $output = $filter->filter('Before {discussion:My thread} after');

        $thread = manager::find_thread('My thread', $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_convert_legacy_tokens_basic_disqus(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $output = text_filter::convert_legacy_tokens('Before [[filter_disqus]] after');
        $this->assertSame('Before {discussion} after', $output);
    }

    public function test_convert_legacy_tokens_does_not_convert_disqus_with_segment(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $input = '[[filter_disqus:book-23]]';
        $this->assertSame($input, text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_comments(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, true);
        $output = text_filter::convert_legacy_tokens('Comments here: {comments}');
        $this->assertSame('Comments here: {discussion}', $output);
    }

    public function test_convert_legacy_tokens_rewrites_without_page_title_dependency(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $output = text_filter::convert_legacy_tokens('[[filter_disqus]]');
        $this->assertSame('{discussion}', $output);
    }

    public function test_convert_legacy_tokens_converts_when_title_empty(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, true);
        $input = 'Untouched [[filter_disqus]] {comments}';
        $this->assertSame('Untouched {discussion} {discussion}', text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_leaves_text_when_both_options_disabled(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, false);
        $input = 'Untouched [[filter_disqus]] {comments}';
        $this->assertSame($input, text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_handles_disqus_only_when_enabled(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(true, false);
        $input = 'A [[filter_disqus]] B {comments}';
        $this->assertSame('A {discussion} B {comments}', text_filter::convert_legacy_tokens($input));
    }

    public function test_convert_legacy_tokens_handles_comments_only_when_enabled(): void {
        $this->resetAfterTest();
        $this->configure_legacy_token_handling(false, true);
        $input = 'A [[filter_disqus]] B {comments}';
        $this->assertSame('A [[filter_disqus]] B {discussion}', text_filter::convert_legacy_tokens($input));
    }

    public function test_filter_renders_legacy_disqus_token(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->configure_legacy_token_handling(true, false);

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Book A',
        ]);
        $bookgen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 1', 'pagenum' => 1]);
        $context = \context_module::instance($book->cmid);

        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/book/view.php', ['id' => $book->cmid]));
        $PAGE->set_title('Course: Course 1', false);

        $filter = new text_filter($context, []);
        $output = $filter->filter('Before [[filter_disqus]] after');
        $threadname = get_string('bookchapterthreadname', 'filter_embeddiscussion', (object)[
            'book' => 'Book A',
            'chapter' => 'Chapter 1',
        ]);
        $thread = manager::find_thread($threadname, $context->id);
        $this->assertNotNull($thread);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $output);
        $this->assertStringContainsString('data-threadid="' . (int)$thread->id . '"', $output);
    }

    public function test_filter_renders_legacy_comments_token(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->configure_legacy_token_handling(false, true);

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Book A',
        ]);
        $bookgen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 1', 'pagenum' => 1]);
        $context = \context_module::instance($book->cmid);

        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/book/view.php', ['id' => $book->cmid]));
        $PAGE->set_title('Course: Course 1', false);

        $filter = new text_filter($context, []);
        $output = $filter->filter('{comments}');
        $threadname = get_string('bookchapterthreadname', 'filter_embeddiscussion', (object)[
            'book' => 'Book A',
            'chapter' => 'Chapter 1',
        ]);
        $thread = manager::find_thread($threadname, $context->id);
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

    public function test_filter_records_zero_itemid_outside_book_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $context = \context_system::instance();
        $filter = new text_filter($context, []);
        $filter->filter('{discussion:Plain thread}');

        $thread = manager::find_thread('Plain thread', $context->id);
        $this->assertNotNull($thread);
        $this->assertSame(0, (int)$thread->itemid);
    }

    public function test_filter_records_first_book_chapter_itemid_without_chapterid(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Book A',
        ]);
        $bookgen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $chapter1 = $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 1', 'pagenum' => 1]);
        $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 2', 'pagenum' => 2]);

        $context = \context_module::instance($book->cmid);
        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/book/view.php', ['id' => $book->cmid]));
        $PAGE->set_title('Chapter 1', false);

        $filter = new text_filter($context, []);
        $filter->filter('{discussion}');

        // With no chapterid request param, itemid falls back to the first chapter.
        $threadname = get_string('bookchapterthreadname', 'filter_embeddiscussion', (object)[
            'book' => 'Book A',
            'chapter' => 'Chapter 1',
        ]);
        $thread = manager::find_thread($threadname, $context->id);
        $this->assertNotNull($thread);
        $this->assertSame((int)$chapter1->id, (int)$thread->itemid);
    }

    public function test_filter_records_chapterid_param_as_book_itemid(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Book A',
        ]);
        $bookgen = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 1', 'pagenum' => 1]);
        $chapter2 = $bookgen->create_chapter(['bookid' => $book->id, 'title' => 'Chapter 2', 'pagenum' => 2]);

        $context = \context_module::instance($book->cmid);
        $PAGE->set_course($course);
        $PAGE->set_context($context);
        $PAGE->set_url(new \moodle_url('/mod/book/view.php', ['id' => $book->cmid, 'chapterid' => $chapter2->id]));
        $PAGE->set_title('Chapter 2', false);

        $_POST['chapterid'] = (string)$chapter2->id;
        try {
            $filter = new text_filter($context, []);
            $filter->filter('{discussion}');
        } finally {
            unset($_POST['chapterid']);
        }

        // The request chapterid wins over the first-chapter fallback.
        $threadname = get_string('bookchapterthreadname', 'filter_embeddiscussion', (object)[
            'book' => 'Book A',
            'chapter' => 'Chapter 2',
        ]);
        $thread = manager::find_thread($threadname, $context->id);
        $this->assertNotNull($thread);
        $this->assertSame((int)$chapter2->id, (int)$thread->itemid);
    }
}

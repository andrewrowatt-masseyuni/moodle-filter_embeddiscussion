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

use local_embeddiscussion\threads;
use local_embeddiscussion\token;

/**
 * Tests for the embedded discussion filter.
 *
 * @package    filter_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \filter_embeddiscussion\text_filter
 */
final class text_filter_test extends \advanced_testcase {
    /**
     * Reset the per-request thread cache, which is keyed on ids PHPUnit reuses.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        threads::reset_cache();
    }

    /**
     * The generator.
     *
     * @return \local_embeddiscussion_generator
     */
    protected function generator(): \local_embeddiscussion_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_embeddiscussion');
    }

    /**
     * Filter some text in a context.
     *
     * @param string $text The text.
     * @param \context $context The context.
     * @return string
     */
    protected function filter(string $text, \context $context): string {
        return (new text_filter($context, []))->filter($text);
    }

    /**
     * An activity's token becomes a placeholder naming its thread by id, and nothing of the token
     * is left - so a second pass, as mod_lesson makes, has nothing to replace.
     */
    public function test_activity_token(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $thread = $this->generator()->create_thread(['cmid' => $page->cmid]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        // As TinyMCE saves it: padded, attributes in its own order.
        $text = '<p>Before</p><div data-embeddiscussion="' . $thread->embedkey .
            '" class="embeddiscussion-embed">&nbsp;</div><p>After</p>';
        $filtered = $this->filter($text, $context);

        $this->assertStringContainsString('<p>Before</p>', $filtered);
        $this->assertStringContainsString('<p>After</p>', $filtered);
        $this->assertStringContainsString('data-region="filter-embeddiscussion"', $filtered);
        $this->assertStringContainsString('data-threadid="' . $thread->id . '"', $filtered);
        $this->assertStringContainsString('embeddisc-skeleton', $filtered);
        $this->assertStringNotContainsString('data-embeddiscussion', $filtered);
        $this->assertStringNotContainsString($thread->embedkey, $filtered);
        $this->assertSame($filtered, $this->filter($filtered, $context));
    }

    /**
     * Text with no token is returned as it is.
     */
    public function test_no_token(): void {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $this->assertSame('<p>Nothing here</p>', $this->filter('<p>Nothing here</p>', $context));
    }

    /**
     * A token copied in from another activity does not resolve: a notice for someone who could fix
     * it, nothing for anyone else.
     */
    public function test_token_from_another_activity(): void {
        $course = $this->getDataGenerator()->create_course();
        $page1 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $page2 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $thread = $this->generator()->create_thread(['cmid' => $page1->cmid]);
        $context = \context_module::instance($page2->cmid);
        $text = '<p>Text</p>' . token::html($thread->embedkey);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $filtered = $this->filter($text, $context);
        $this->assertStringContainsString('embeddisc-notfound', $filtered);
        $this->assertStringNotContainsString('data-threadid', $filtered);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->assertSame('<p>Text</p>', $this->filter($text, $context));
    }

    /**
     * Text in a course context - a section summary - shows the course's section threads, and no
     * draft: a draft is not on any page until its activity adopts it.
     */
    public function test_section_token(): void {
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $section = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 1]);
        $draft = $this->generator()->create_thread(['courseid' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $this->assertStringContainsString(
            'data-threadid="' . $section->id . '"',
            $this->filter(token::html($section->embedkey), $context)
        );
        $this->assertSame('', $this->filter(token::html($draft->embedkey), $context));
    }

    /**
     * The dashboard's token becomes the course's dashboard, wherever in the course it is.
     */
    public function test_dashboard_token(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        foreach ([\context_module::instance($page->cmid), \context_course::instance($course->id)] as $context) {
            $filtered = $this->filter(token::html(token::DASHBOARD), $context);
            $this->assertStringContainsString('data-region="filter-embeddiscussion-dashboard"', $filtered);
            $this->assertStringContainsString('data-courseid="' . $course->id . '"', $filtered);
        }
    }

    /**
     * Anywhere no thread can belong - the front page, a user's profile - a token becomes nothing,
     * and so does the dashboard's, which needs a course.
     */
    public function test_tokens_outside_a_course(): void {
        $this->setAdminUser();
        $thread = $this->generator()->create_thread(['courseid' => $this->getDataGenerator()->create_course()->id]);

        $elsewhere = [\context_course::instance(SITEID), \context_system::instance(), \context_user::instance(get_admin()->id)];
        foreach ($elsewhere as $ctx) {
            $this->assertSame('<p>x</p>', $this->filter('<p>x</p>' . token::html($thread->embedkey), $ctx));
            $this->assertSame('<p>x</p>', $this->filter('<p>x</p>' . token::html(token::DASHBOARD), $ctx));
        }
    }

    /**
     * Each thread's placeholder loads the discussion module once, however many there are.
     */
    public function test_requires_the_discussion_module_once(): void {
        global $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $one = $this->generator()->create_thread(['cmid' => $page->cmid]);
        $two = $this->generator()->create_thread(['cmid' => $page->cmid]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $this->filter(token::html($one->embedkey) . token::html($two->embedkey), $context);
        $this->filter(token::html($one->embedkey), $context);

        $amdjscode = (new \ReflectionProperty($PAGE->requires, 'amdjscode'))->getValue($PAGE->requires);
        $calls = array_filter($amdjscode, fn($code) => str_contains($code, "require(['local_embeddiscussion/discussion']"));
        $this->assertCount(1, $calls);
    }
}

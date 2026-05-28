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
 * Tests that threads and posts are removed when their host context is deleted.
 *
 * @package    filter_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \filter_embeddiscussion\manager::delete_threads_for_contexts
 */
final class context_deletion_test extends \advanced_testcase {
    /**
     * Count rows tied to a thread across every table the plugin owns.
     *
     * @param int $threadid
     * @return array{thread:int,posts:int,votes:int,handles:int}
     */
    protected function thread_footprint(int $threadid): array {
        global $DB;
        return [
            'thread' => $DB->count_records('filter_embeddiscussion_thread', ['id' => $threadid]),
            'posts' => $DB->count_records('filter_embeddiscussion_post', ['threadid' => $threadid]),
            'votes' => $DB->count_records_select(
                'filter_embeddiscussion_vote',
                'postid IN (SELECT id FROM {filter_embeddiscussion_post} WHERE threadid = :tid)',
                ['tid' => $threadid]
            ),
            'handles' => $DB->count_records('filter_embeddiscussion_handle', ['threadid' => $threadid]),
        ];
    }

    /**
     * Filter a sink's events down to instances of one class.
     *
     * @param \core\event\base[] $events
     * @param string $class
     * @return \core\event\base[]
     */
    protected function events_of(array $events, string $class): array {
        return array_values(array_filter($events, static fn($e) => $e instanceof $class));
    }

    public function test_delete_threads_for_contexts_removes_all_thread_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('filter_embeddiscussion');
        $course = $gen->create_course();
        $context = \context_course::instance($course->id);
        $student1 = $gen->create_and_enrol($course, 'student');
        $student2 = $gen->create_and_enrol($course, 'student');

        $thread = $plugingen->create_thread([
            'idnumber' => 'ctx-thread',
            'courseid' => $course->id,
            'threadname' => 'Ctx thread',
            'anonymous' => 1,
        ]);
        $plugingen->create_post(['thread' => 'ctx-thread', 'userid' => $student1->id, 'content' => 'First post']);
        $plugingen->create_post(['thread' => 'ctx-thread', 'userid' => $student2->id, 'content' => 'Second post']);
        $plugingen->create_vote([
            'thread' => 'ctx-thread',
            'userid' => $student2->id,
            'postcontent' => 'First post',
            'direction' => 1,
        ]);

        // Seeded as expected: thread, two posts, one vote, two anonymous handles.
        $before = $this->thread_footprint((int)$thread->id);
        $this->assertSame(['thread' => 1, 'posts' => 2, 'votes' => 1, 'handles' => 2], $before);

        manager::delete_threads_for_contexts([(int)$context->id]);

        $after = $this->thread_footprint((int)$thread->id);
        $this->assertSame(['thread' => 0, 'posts' => 0, 'votes' => 0, 'handles' => 0], $after);
    }

    public function test_delete_threads_for_contexts_emits_events(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $plugingen = $gen->get_plugin_generator('filter_embeddiscussion');
        $course = $gen->create_course();
        $context = \context_course::instance($course->id);
        $student = $gen->create_and_enrol($course, 'student');

        $thread = $plugingen->create_thread([
            'idnumber' => 'evt-thread',
            'courseid' => $course->id,
            'threadname' => 'Evt thread',
        ]);
        $plugingen->create_post(['thread' => 'evt-thread', 'userid' => $student->id, 'content' => 'Post one']);
        $plugingen->create_post(['thread' => 'evt-thread', 'userid' => $student->id, 'content' => 'Post two']);

        $sink = $this->redirectEvents();
        manager::delete_threads_for_contexts([(int)$context->id]);
        $events = $sink->get_events();
        $sink->close();

        $postdeleted = $this->events_of($events, \filter_embeddiscussion\event\post_deleted::class);
        $threaddeleted = $this->events_of($events, \filter_embeddiscussion\event\thread_deleted::class);

        $this->assertCount(2, $postdeleted, 'One post_deleted event expected per post.');
        $this->assertCount(1, $threaddeleted, 'One thread_deleted event expected per thread.');

        $threadevent = $threaddeleted[0];
        $this->assertSame((int)$thread->id, (int)$threadevent->objectid);
        $this->assertSame((int)$context->id, (int)$threadevent->contextid);
    }

    public function test_pre_course_module_delete_removes_module_threads_only(): void {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $coursecontext = \context_course::instance($course->id);
        $label = $gen->create_module('label', ['course' => $course->id]);
        $modcontext = \context_module::instance($label->cmid);

        $modthread = manager::get_or_create_thread('mod-thread', $modcontext, 'Module thread');
        $coursethread = manager::get_or_create_thread('course-thread', $coursecontext, 'Course thread');
        manager::create_post($modthread, $modcontext, 0, 'Module post', (int)$USER->id);
        manager::create_post($coursethread, $coursecontext, 0, 'Course post', (int)$USER->id);

        $sink = $this->redirectEvents();
        course_delete_module($label->cmid);
        $events = $sink->get_events();
        $sink->close();

        // The module thread and its post are gone; the sibling course thread survives.
        $this->assertFalse($DB->record_exists('filter_embeddiscussion_thread', ['id' => $modthread->id]));
        $this->assertSame(0, $DB->count_records('filter_embeddiscussion_post', ['threadid' => $modthread->id]));
        $this->assertTrue($DB->record_exists('filter_embeddiscussion_thread', ['id' => $coursethread->id]));
        $this->assertSame(1, $DB->count_records('filter_embeddiscussion_post', ['threadid' => $coursethread->id]));

        $threaddeleted = $this->events_of($events, \filter_embeddiscussion\event\thread_deleted::class);
        $this->assertCount(1, $threaddeleted);
        $this->assertSame((int)$modthread->id, (int)$threaddeleted[0]->objectid);
    }

    public function test_pre_block_delete_removes_block_threads(): void {
        global $DB, $CFG, $USER;
        require_once($CFG->dirroot . '/lib/blocklib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $coursecontext = \context_course::instance($course->id);
        $block = $gen->create_block('html', ['parentcontextid' => $coursecontext->id]);
        $blockcontext = \context_block::instance($block->id);

        $blockthread = manager::get_or_create_thread('block-thread', $blockcontext, 'Block thread');
        manager::create_post($blockthread, $blockcontext, 0, 'Block post', (int)$USER->id);

        $sink = $this->redirectEvents();
        blocks_delete_instance($block);
        $events = $sink->get_events();
        $sink->close();

        $this->assertFalse($DB->record_exists('filter_embeddiscussion_thread', ['id' => $blockthread->id]));
        $this->assertSame(0, $DB->count_records('filter_embeddiscussion_post', ['threadid' => $blockthread->id]));

        $threaddeleted = $this->events_of($events, \filter_embeddiscussion\event\thread_deleted::class);
        $this->assertCount(1, $threaddeleted);
        $this->assertSame((int)$blockthread->id, (int)$threaddeleted[0]->objectid);
    }

    public function test_pre_course_delete_removes_course_and_descendant_threads(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $coursecontext = \context_course::instance($course->id);
        $label = $gen->create_module('label', ['course' => $course->id]);
        $modcontext = \context_module::instance($label->cmid);

        // A second course whose thread must be left untouched.
        $othercourse = $gen->create_course();
        $othercontext = \context_course::instance($othercourse->id);

        $coursethread = manager::get_or_create_thread('course-thread', $coursecontext, 'Course thread');
        $modthread = manager::get_or_create_thread('mod-thread', $modcontext, 'Module thread');
        $survivor = manager::get_or_create_thread('survivor-thread', $othercontext, 'Survivor thread');
        manager::create_post($coursethread, $coursecontext, 0, 'Course post', (int)$USER->id);
        manager::create_post($modthread, $modcontext, 0, 'Module post', (int)$USER->id);
        manager::create_post($survivor, $othercontext, 0, 'Survivor post', (int)$USER->id);

        $sink = $this->redirectEvents();
        delete_course($course, false);
        $events = $sink->get_events();
        $sink->close();

        // Both the course-context thread and the descendant module-context thread go.
        $this->assertFalse($DB->record_exists('filter_embeddiscussion_thread', ['id' => $coursethread->id]));
        $this->assertFalse($DB->record_exists('filter_embeddiscussion_thread', ['id' => $modthread->id]));
        $this->assertSame(0, $DB->count_records('filter_embeddiscussion_post', ['threadid' => $coursethread->id]));
        $this->assertSame(0, $DB->count_records('filter_embeddiscussion_post', ['threadid' => $modthread->id]));

        // The unrelated course keeps its thread and post.
        $this->assertTrue($DB->record_exists('filter_embeddiscussion_thread', ['id' => $survivor->id]));
        $this->assertSame(1, $DB->count_records('filter_embeddiscussion_post', ['threadid' => $survivor->id]));

        $deletedids = array_map(
            static fn($e) => (int)$e->objectid,
            $this->events_of($events, \filter_embeddiscussion\event\thread_deleted::class)
        );
        $this->assertContains((int)$coursethread->id, $deletedids);
        $this->assertContains((int)$modthread->id, $deletedids);
        $this->assertNotContains((int)$survivor->id, $deletedids);
    }
}

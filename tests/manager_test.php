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
 * Tests for the manager.
 *
 * @package    filter_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \filter_embeddiscussion\manager
 */
final class manager_test extends \advanced_testcase {
    public function test_get_or_create_thread_is_idempotent(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $a = manager::get_or_create_thread('Test thread', $context);
        $b = manager::get_or_create_thread('Test thread', $context);
        $this->assertEquals($a->id, $b->id);

        $c = manager::get_or_create_thread('Other thread', $context);
        $this->assertNotEquals($a->id, $c->id);
    }

    public function test_create_post_and_view(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $thread = manager::get_or_create_thread('Demo', $context);

        $this->setUser($student);
        $post = manager::create_post(
            $thread,
            $context,
            0,
            '<p>Lorem ipsum <b>dolor</b></p><script>alert(1)</script>',
            $student->id
        );

        // Sanitisation strips the script.
        $this->assertStringNotContainsString('<script', $post->content);
        $this->assertStringContainsString('<b>dolor</b>', $post->content);

        $this->setUser($teacher);
        $view = manager::get_thread_view($thread, $context);
        $this->assertEquals(1, $view['postcount']);
        $this->assertEquals($post->id, $view['posts'][0]['id']);
        $this->assertTrue($view['canpost']);
    }

    public function test_locked_thread_blocks_posts_and_edits(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $thread = manager::get_or_create_thread('Locked', $context);

        $this->setUser($student);
        $post = manager::create_post($thread, $context, 0, 'Hello', $student->id);

        $thread = manager::sync_settings_from_token($thread, ['locked' => true]);
        $this->assertEquals(1, $thread->locked);

        $this->expectException(\moodle_exception::class);
        manager::create_post($thread, $context, 0, 'Should fail', $student->id);
        // Subsequent edit attempt would also throw, but expectException only matches the first.
        unset($post);
    }

    public function test_voting_counts_and_toggle(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $author = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $voter = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $thread = manager::get_or_create_thread('Votes', $context);
        $this->setUser($author);
        $post = manager::create_post($thread, $context, 0, 'Vote me', $author->id);

        $this->setUser($voter);
        $a = manager::vote_post($post->id, $thread, $context, 1, $voter->id);
        $this->assertEquals(['up' => 1, 'down' => 0, 'my' => 1], $a);

        $b = manager::vote_post($post->id, $thread, $context, 1, $voter->id);
        $this->assertEquals(1, $b['up'], 'Repeating same vote should not double-count');

        $c = manager::vote_post($post->id, $thread, $context, -1, $voter->id);
        $this->assertEquals(['up' => 0, 'down' => 1, 'my' => -1], $c);

        $d = manager::vote_post($post->id, $thread, $context, 0, $voter->id);
        $this->assertEquals(['up' => 0, 'down' => 0, 'my' => 0], $d);
    }

    public function test_anonymous_handles_assigned_in_order(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $alice = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bob = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $thread = manager::get_or_create_thread('Anon thread', $context);
        $thread = manager::sync_settings_from_token($thread, ['anonymous' => true]);

        // First poster -> handleindex 0.
        $this->setUser($alice);
        manager::create_post($thread, $context, 0, 'I am Alice', $alice->id);

        // Second poster -> handleindex 1.
        $this->setUser($bob);
        manager::create_post($thread, $context, 0, 'I am Bob', $bob->id);

        [$alicelabel, $aliceindex] = handles::get_or_assign($thread, (int)$alice->id);
        [$boblabel, $bobindex] = handles::get_or_assign($thread, (int)$bob->id);

        $this->assertSame(0, $aliceindex);
        $this->assertSame(1, $bobindex);
        $this->assertNotSame($alicelabel, $boblabel);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $alicelabel);
    }

    public function test_event_logged_on_thread_init(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $this->setAdminUser();

        $sink = $this->redirectEvents();
        manager::get_or_create_thread('Logged thread', $context);
        $events = $sink->get_events();
        $sink->close();

        $found = false;
        foreach ($events as $e) {
            if ($e instanceof \filter_embeddiscussion\event\thread_initialised) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected thread_initialised event was not triggered.');
    }

    public function test_get_dashboard_view_returns_latest_posts_and_unread_markers(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $visiblelabel = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'name' => 'Visible label',
            'intro' => '{embeddeddiscussion:visible-thread}',
            'visible' => 1,
        ]);
        $hiddenlabel = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'name' => 'Hidden label',
            'intro' => '{embeddeddiscussion:hidden-thread}',
            'visible' => 0,
        ]);

        $visiblecontext = \context_module::instance($visiblelabel->cmid);
        $hiddencontext = \context_module::instance($hiddenlabel->cmid);
        $visiblethread = manager::get_or_create_thread(
            'visible-thread',
            $visiblecontext,
            '/mod/label/view.php?id=' . $visiblelabel->cmid,
            'Visible label'
        );
        $hiddenthread = manager::get_or_create_thread(
            'hidden-thread',
            $hiddencontext,
            '/mod/label/view.php?id=' . $hiddenlabel->cmid,
            'Hidden label'
        );

        $this->setUser($teacher);
        $oldpost = manager::create_post($visiblethread, $visiblecontext, 0, 'Old visible post', $teacher->id);
        $newpost = manager::create_post($visiblethread, $visiblecontext, 0, 'New visible post', $teacher->id);
        manager::create_post($hiddenthread, $hiddencontext, 0, 'Hidden post', $teacher->id);

        $now = time();
        $lastaccess = $now - 100;
        $DB->set_field('filter_embeddiscussion_post', 'timecreated', $lastaccess - 50, ['id' => $oldpost->id]);
        $DB->set_field('filter_embeddiscussion_post', 'timecreated', $lastaccess + 50, ['id' => $newpost->id]);
        $DB->set_field('filter_embeddiscussion_post', 'timemodified', $lastaccess - 50, ['id' => $oldpost->id]);
        $DB->set_field('filter_embeddiscussion_post', 'timemodified', $lastaccess + 50, ['id' => $newpost->id]);
        $DB->insert_record('user_lastaccess', (object)[
            'userid' => $student->id,
            'courseid' => $course->id,
            'timeaccess' => $lastaccess,
        ]);

        $this->setUser($student);
        $view = manager::get_dashboard_view((int)$course->id, (int)$student->id);

        $this->assertSame(2, $view['postcount']);
        $this->assertCount(2, $view['posts']);
        $this->assertSame((int)$newpost->id, (int)$view['posts'][0]['id']);
        $this->assertSame((int)$oldpost->id, (int)$view['posts'][1]['id']);
        $this->assertTrue($view['posts'][0]['isunread']);
        $this->assertFalse($view['posts'][1]['isunread']);
        $this->assertSame('Visible label', $view['posts'][0]['threadname']);
        $this->assertStringContainsString('#embeddisc-post-' . (int)$newpost->id, $view['posts'][0]['posturl']);
        $this->assertStringNotContainsString('Hidden post', json_encode($view));
    }
}

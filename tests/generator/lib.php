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

/**
 * Data generator for filter_embeddiscussion.
 *
 * @package    filter_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Seeds threads, posts and reactions for filter_embeddiscussion tests.
 */
class filter_embeddiscussion_generator extends component_generator_base {
    /**
     * Create a thread.
     *
     * Required: idnumber (or legacy name). Optional: threadname, courseid
     * (course context), activity (idnumber or activity name to anchor at
     * module context), anonymous.
     *
     * @param array|stdClass $record
     * @return stdClass
     */
    public function create_thread($record): \stdClass {
        global $DB;

        $record = (array)$record;
        $idnumber = trim((string)($record['idnumber'] ?? $record['name'] ?? ''));
        if ($idnumber === '') {
            throw new coding_exception('filter_embeddiscussion thread requires an idnumber');
        }

        $threadname = trim((string)($record['threadname'] ?? ($record['name'] ?? '')));

        $context = $this->resolve_context($record);
        $thread = \filter_embeddiscussion\manager::get_or_create_thread(
            $idnumber,
            $context,
            ($threadname !== '') ? $threadname : null
        );

        $update = [];
        if (array_key_exists('anonymous', $record)) {
            $update['anonymous'] = (int)(bool)$record['anonymous'];
        }
        if ($update) {
            $update['id'] = $thread->id;
            $update['timemodified'] = time();
            $DB->update_record('filter_embeddiscussion_thread', (object)$update);
            $thread = $DB->get_record('filter_embeddiscussion_thread', ['id' => $thread->id]);
        }

        return $thread;
    }

    /**
     * Create a post in an existing thread.
     *
     * Required: thread (idnumber), userid, content. Optional: courseid (to disambiguate
     * threads with the same idnumber across courses), parentid.
     *
     * @param array|stdClass $record
     * @return stdClass
     */
    public function create_post($record): \stdClass {
        global $DB;

        $record = (array)$record;
        foreach (['thread', 'userid', 'content'] as $req) {
            if (empty($record[$req])) {
                throw new coding_exception("filter_embeddiscussion post requires '$req'");
            }
        }

        $threadrecord = $this->find_thread((string)$record['thread'], $record['courseid'] ?? null);
        $context = \context::instance_by_id((int)$threadrecord->contextid);
        $parentid = (int)($record['parentid'] ?? 0);

        $now = time();
        $post = (object)[
            'threadid' => (int)$threadrecord->id,
            'parentid' => $parentid,
            'userid' => (int)$record['userid'],
            'content' => \filter_embeddiscussion\manager::sanitise((string)$record['content']),
            'edited' => 0,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $post->id = $DB->insert_record('filter_embeddiscussion_post', $post);

        if ($threadrecord->anonymous) {
            \filter_embeddiscussion\handles::get_or_assign($threadrecord, (int)$record['userid']);
        }

        unset($context); // Not needed beyond validation.
        return $post;
    }

    /**
     * Add an emoji reaction to a post.
     *
     * Required: thread (idnumber), userid, postauthor (username) or postcontent
     * (substring match), emoji (shortcode). Adding the same emoji twice is a no-op.
     *
     * @param array|stdClass $record
     * @return void
     */
    public function create_reaction($record): void {
        global $DB;

        $record = (array)$record;
        foreach (['thread', 'userid', 'emoji'] as $req) {
            if (!isset($record[$req])) {
                throw new coding_exception("filter_embeddiscussion reaction requires '$req'");
            }
        }

        $threadrecord = $this->find_thread((string)$record['thread'], $record['courseid'] ?? null);
        $emoji = (string)$record['emoji'];
        $postid = $this->find_post_id($threadrecord->id, $record);

        $key = [
            'postid' => $postid,
            'userid' => (int)$record['userid'],
            'emoji' => $emoji,
        ];
        if ($DB->record_exists('filter_embeddiscussion_reaction', $key)) {
            return;
        }

        $DB->insert_record('filter_embeddiscussion_reaction', (object)($key + ['timecreated' => time()]));
    }

    /**
     * Resolve the context for a thread record. Uses the activity's module
     * context if provided, otherwise the course context.
     *
     * @param array $record
     * @return \context
     */
    private function resolve_context(array $record): \context {
        if (!empty($record['activity'])) {
            $cm = $this->find_cm((string)$record['activity'], $record['courseid'] ?? null);
            return \context_module::instance($cm->id);
        }
        if (empty($record['courseid'])) {
            throw new coding_exception('filter_embeddiscussion thread requires courseid or activity');
        }
        return \context_course::instance((int)$record['courseid']);
    }

    /**
     * Find a course module by activity idnumber or name.
     *
     * @param string $idnumberorname
     * @param int|null $courseid
     * @return \stdClass course module record
     */
    private function find_cm(string $idnumberorname, ?int $courseid = null): \stdClass {
        global $DB;
        $params = ['idnumber' => $idnumberorname];
        if ($courseid) {
            $params['course'] = (int)$courseid;
        }
        $cm = $DB->get_record('course_modules', $params);
        if ($cm) {
            return $cm;
        }
        // Fall back to looking up by activity name across labels, pages, etc.
        $sql = "SELECT cm.* FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE EXISTS (
                       SELECT 1 FROM {label} l WHERE l.id = cm.instance AND m.name = 'label' AND l.name = :n1
                       UNION
                       SELECT 1 FROM {page} p WHERE p.id = cm.instance AND m.name = 'page' AND p.name = :n2
                       UNION
                       SELECT 1 FROM {forum} f WHERE f.id = cm.instance AND m.name = 'forum' AND f.name = :n3
                  )";
        $params = ['n1' => $idnumberorname, 'n2' => $idnumberorname, 'n3' => $idnumberorname];
        if ($courseid) {
            $sql .= ' AND cm.course = :course';
            $params['course'] = (int)$courseid;
        }
        $rows = $DB->get_records_sql($sql, $params, 0, 1);
        if (!$rows) {
            throw new coding_exception("Activity '$idnumberorname' not found");
        }
        return reset($rows);
    }

    /**
     * Look up a thread by idnumber (and optionally course).
     *
     * @param string $idnumber
     * @param int|null $courseid
     * @return \stdClass
     */
    private function find_thread(string $idnumber, ?int $courseid = null): \stdClass {
        global $DB;
        $params = ['namehash' => sha1(trim($idnumber))];
        if ($courseid) {
            $params['courseid'] = (int)$courseid;
        }
        $thread = $DB->get_record('filter_embeddiscussion_thread', $params);
        if (!$thread) {
            throw new coding_exception("Thread '$idnumber' not found");
        }
        return $thread;
    }

    /**
     * Find a post id within a thread by content substring.
     *
     * @param int $threadid
     * @param array $record
     * @return int
     */
    private function find_post_id(int $threadid, array $record): int {
        global $DB;
        if (!empty($record['postid'])) {
            return (int)$record['postid'];
        }
        if (empty($record['postcontent'])) {
            throw new coding_exception('Reaction requires postid or postcontent');
        }
        $like = $DB->sql_like('content', ':needle');
        $sql = "SELECT id FROM {filter_embeddiscussion_post}
                 WHERE threadid = :threadid AND $like
              ORDER BY id ASC";
        $rows = $DB->get_records_sql($sql, [
            'threadid' => $threadid,
            'needle' => '%' . $DB->sql_like_escape($record['postcontent']) . '%',
        ], 0, 1);
        if (!$rows) {
            throw new coding_exception('No post matching content "' . $record['postcontent'] . '"');
        }
        return (int)reset($rows)->id;
    }
}

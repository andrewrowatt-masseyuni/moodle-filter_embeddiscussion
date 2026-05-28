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

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat step definitions for filter_embeddiscussion.
 *
 * @package    filter_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_filter_embeddiscussion extends behat_base {
    /**
     * Wait until the embedded discussion JS has rendered the root.
     *
     * @Given /^the embedded discussion is loaded$/
     */
    public function the_embedded_discussion_is_loaded(): void {
        $this->wait_for_pending_js();
        $this->ensure_element_exists("[data-region='embeddisc-root']", 'css_element');
    }

    /**
     * Wait until the dashboard JS has replaced the placeholder skeleton with
     * the live dashboard content.
     *
     * @Given /^the embedded discussion dashboard is loaded$/
     */
    public function the_embedded_discussion_dashboard_is_loaded(): void {
        $this->wait_for_pending_js();
        $this->ensure_element_exists(
            "[data-region='filter-embeddiscussion-dashboard'] .embeddisc-dashboard:not(.embeddisc-skeleton)",
            'css_element'
        );
    }

    /**
     * Backdate (or insert) a user's last-access timestamp for a course. Used
     * to simulate "the user last visited N seconds ago" for dashboard tests.
     * The cutoff must stay under LASTACCESS_UPDATE_SECS (60) so the visit that
     * triggers the test doesn't bump it forward.
     *
     * @Given /^user "(?P<username>[^"]+)" last accessed course "(?P<shortname>[^"]+)" "(?P<seconds>\d+)" seconds ago$/
     * @param string $username
     * @param string $shortname course shortname
     * @param string $seconds
     */
    public function user_last_accessed_course_seconds_ago(
        string $username,
        string $shortname,
        string $seconds
    ): void {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $courseid = $DB->get_field('course', 'id', ['shortname' => $shortname], MUST_EXIST);
        $time = time() - (int)$seconds;
        $existing = $DB->get_record('user_lastaccess', ['userid' => $userid, 'courseid' => $courseid]);
        if ($existing) {
            $DB->set_field('user_lastaccess', 'timeaccess', $time, ['id' => $existing->id]);
        } else {
            $DB->insert_record('user_lastaccess', (object)[
                'userid' => $userid,
                'courseid' => $courseid,
                'timeaccess' => $time,
            ]);
        }
    }

    /**
     * Compose and submit a top-level comment in the embedded discussion.
     *
     * Opens the collapsed composer, types the content into the Quill editor and
     * clicks the Comment button. The editor is a contenteditable region rather
     * than a form field, so the content is written directly into it.
     *
     * @When /^I post "(?P<content>[^"]*)" to the embedded discussion$/
     * @param string $content the comment text
     */
    public function i_post_to_the_embedded_discussion(string $content): void {
        // Open the composer; the Quill editor loads lazily once it is expanded.
        $this->execute('behat_general::i_click_on', [
            "[data-region='composer'] [data-action='open-composer']",
            'css_element',
        ]);
        $this->ensure_element_exists("[data-region='composer-expanded'] .ql-editor", 'css_element');

        // Quill exposes the contenteditable as .ql-editor; submitComposer() reads its
        // innerHTML directly, so writing the markup here is sufficient to post.
        $contentjs = json_encode('<p>' . $content . '</p>');
        $script = <<<JS
            (function() {
                var editor = document.querySelector('[data-region="composer-expanded"] .ql-editor');
                if (!editor) {
                    return 'no-editor';
                }
                editor.innerHTML = $contentjs;
                return 'ok';
            })();
JS;
        $result = $this->evaluate_script($script);
        if ($result !== 'ok') {
            throw new ExpectationException(
                "Could not type into the embedded discussion composer ($result)",
                $this->getSession()
            );
        }

        // Submit the comment and let the thread re-render.
        $this->execute('behat_general::i_click_on', [
            "[data-region='composer-expanded'] [data-action='submit-compose']",
            'css_element',
        ]);
        $this->wait_for_pending_js();
    }

    /**
     * Click an action button (by visible text) on the post that contains the given snippet.
     *
     * @When /^I click "(?P<label>[^"]*)" on the embedded discussion post containing "(?P<needle>[^"]*)"$/
     * @param string $label visible button text (Edit, Delete, Reply)
     * @param string $needle a substring of the post's visible content
     */
    public function i_click_action_on_post(string $label, string $needle): void {
        $needlejs = json_encode($needle);
        $labeljs = json_encode($label);
        $script = <<<JS
            (function() {
                var posts = document.querySelectorAll('[data-region="post"]');
                for (var i = 0; i < posts.length; i++) {
                    var content = posts[i].querySelector('[data-region="post-content"]');
                    if (!content) {
                        continue;
                    }
                    if (content.textContent.indexOf($needlejs) !== -1) {
                        var actions = posts[i].querySelectorAll('.embeddisc-post-actions [data-action]');
                        for (var j = 0; j < actions.length; j++) {
                            var span = actions[j].querySelector('span');
                            if (span && span.textContent.trim() === $labeljs.trim()) {
                                actions[j].click();
                                return 'clicked';
                            }
                        }
                        return 'no-button:' + $labeljs;
                    }
                }
                return 'no-post';
            })();
JS;
        $result = $this->evaluate_script($script);
        if ($result !== 'clicked') {
            throw new ExpectationException(
                "Could not click '$label' on a post containing '$needle' ($result)",
                $this->getSession()
            );
        }
        $this->wait_for_pending_js();
    }

    /**
     * Assert that an action button (Edit/Delete/Reply) is absent from the post containing the snippet.
     *
     * @Then /^I should not see the "(?P<label>[^"]*)" action on the embedded discussion post containing "(?P<needle>[^"]*)"$/
     * @param string $label visible button text (Edit, Delete, Reply)
     * @param string $needle a substring of the post's visible content
     */
    public function action_should_be_absent(string $label, string $needle): void {
        $needlejs = json_encode($needle);
        $labeljs = json_encode($label);
        $script = <<<JS
            (function() {
                var posts = document.querySelectorAll('[data-region="post"]');
                for (var i = 0; i < posts.length; i++) {
                    var content = posts[i].querySelector('[data-region="post-content"]');
                    if (!content) {
                        continue;
                    }
                    if (content.textContent.indexOf($needlejs) !== -1) {
                        var actions = posts[i].querySelectorAll('.embeddisc-post-actions [data-action]');
                        for (var j = 0; j < actions.length; j++) {
                            var span = actions[j].querySelector('span');
                            if (span && span.textContent.trim() === $labeljs.trim()) {
                                return 'found';
                            }
                        }
                        return 'absent';
                    }
                }
                return 'no-post';
            })();
JS;
        $result = $this->evaluate_script($script);
        if ($result === 'no-post') {
            throw new ExpectationException(
                "No post containing '$needle' was rendered",
                $this->getSession()
            );
        }
        if ($result !== 'absent') {
            throw new ExpectationException(
                "Expected '$label' action to be absent on a post containing '$needle', but it was present",
                $this->getSession()
            );
        }
    }

    /**
     * Click a vote button on the post containing the given snippet.
     *
     * @When /^I click the "(?P<dir>up|down)" vote button on the embedded discussion post containing "(?P<needle>[^"]*)"$/
     * @param string $dir up or down
     * @param string $needle post content substring
     */
    public function i_click_vote_button(string $dir, string $needle): void {
        $direction = $dir === 'up' ? 1 : -1;
        $needlejs = json_encode($needle);
        $script = <<<JS
            (function() {
                var posts = document.querySelectorAll('[data-region="post"]');
                for (var i = 0; i < posts.length; i++) {
                    var content = posts[i].querySelector('[data-region="post-content"]');
                    if (content && content.textContent.indexOf($needlejs) !== -1) {
                        var btn = posts[i].querySelector('[data-action="vote"][data-direction="$direction"]');
                        if (btn) {
                            btn.click();
                            return 'clicked';
                        }
                        return 'no-button';
                    }
                }
                return 'no-post';
            })();
JS;
        $result = $this->evaluate_script($script);
        if ($result !== 'clicked') {
            throw new ExpectationException(
                "Could not click $dir vote on a post containing '$needle' ($result)",
                $this->getSession()
            );
        }
        $this->wait_for_pending_js();
    }

    // phpcs:disable moodle.Files.LineLength.TooLong
    /**
     * Assert the up/down vote count for a post.
     *
     * @Then /^the "(?P<dir>up|down)" vote count on the embedded discussion post containing "(?P<needle>[^"]*)" should be "(?P<count>\d+)"$/
     * @param string $dir up or down
     * @param string $needle post content substring
     * @param string $count expected vote count
     */
    public function vote_count_should_be(string $dir, string $needle, string $count): void {
        $region = $dir === 'up' ? 'votes-up' : 'votes-down';
        $needlejs = json_encode($needle);
        $script = <<<JS
            (function() {
                var posts = document.querySelectorAll('[data-region="post"]');
                for (var i = 0; i < posts.length; i++) {
                    var content = posts[i].querySelector('[data-region="post-content"]');
                    if (content && content.textContent.indexOf($needlejs) !== -1) {
                        var span = posts[i].querySelector('[data-region="$region"]');
                        return span ? span.textContent.trim() : 'no-region';
                    }
                }
                return 'no-post';
            })();
JS;
        $result = (string)$this->evaluate_script($script);
        if ($result !== $count) {
            throw new ExpectationException(
                "Expected $dir vote count to be '$count' on post containing '$needle', got '$result'",
                $this->getSession()
            );
        }
    }

    /**
     * Check whether the up/down vote button is in the active state for the matching post.
     *
     * @Then /^the "(?P<dir>up|down)" vote on the embedded discussion post containing "(?P<needle>[^"]*)" should (?P<should>be|not be) marked as active$/
     * @param string $dir up or down
     * @param string $needle post content substring
     * @param string $should "be" or "not be"
     */
    public function vote_active_state(string $dir, string $needle, string $should): void {
        $direction = $dir === 'up' ? 1 : -1;
        $expected = ($should === 'be');
        $needlejs = json_encode($needle);
        $script = <<<JS
            (function() {
                var posts = document.querySelectorAll('[data-region="post"]');
                for (var i = 0; i < posts.length; i++) {
                    var content = posts[i].querySelector('[data-region="post-content"]');
                    if (content && content.textContent.indexOf($needlejs) !== -1) {
                        var btn = posts[i].querySelector('[data-action="vote"][data-direction="$direction"]');
                        return btn && btn.classList.contains('active') ? 'active' : 'inactive';
                    }
                }
                return 'no-post';
            })();
JS;
        $state = (string)$this->evaluate_script($script);
        $isactive = ($state === 'active');
        if ($isactive !== $expected) {
            throw new ExpectationException(
                "Expected $dir vote on post '$needle' to be '" . ($expected ? 'active' : 'inactive') . "', got '$state'",
                $this->getSession()
            );
        }
    }
    // phpcs:enable moodle.Files.LineLength.TooLong
}

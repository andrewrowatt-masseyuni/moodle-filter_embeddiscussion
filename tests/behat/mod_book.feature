@filter @filter_embeddiscussion
Feature: Testing mod_book in filter_embeddiscussion
  In order to host a separate conversation alongside each chapter
  As a teacher
  Each Book chapter with a {discussion} token should keep its own discussion

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the "embeddiscussion" filter is "on"
    And the following "activities" exist:
      | activity | course | name | idnumber |
      | book     | C1     | b1   | book1    |
    And the following "mod_book > chapters" exist:
      | book | title | content      |
      | b1   | c2    | {discussion} |
      | b1   | c1    | {discussion} |

  @javascript
  Scenario: A comment posted in one chapter is not visible in the other chapter
    Given I am on the "b1" "book activity" page logged in as "teacher1"
    And I change the window size to "large"
    And the embedded discussion is loaded
    # The book opens on chapter c1.
    When I post "c1-discussion" to the embedded discussion
    Then I should see "c1-discussion"

    # Move to chapter c2; its discussion is a separate thread.
    When I click on "Next" "link"
    And the embedded discussion is loaded
    Then I should not see "c1-discussion"
    When I post "c2-discussion" to the embedded discussion
    Then I should see "c2-discussion"

    # Back to chapter c1; only its own comment is shown.
    When I click on "Previous" "link"
    And the embedded discussion is loaded
    Then I should see "c1-discussion"
    And I should not see "c2-discussion"

    # Renaming chapter c2 to c3 changes the title the bare {discussion} token
    # derives its thread name from, so the comment posted under "c2" is detached
    # and no longer shown on the renamed chapter.
    When I turn editing mode on
    And I follow "Edit chapter \"2. c2\""
    And I set the field "Chapter title" to "c3"
    And I press "Save changes"
    And the embedded discussion is loaded
    Then I should not see "c2-discussion"

    # Pinning the chapter to an explicit thread name matching the title it had
    # when the comment was posted ("b1: c2") re-attaches the detached discussion.
    When I follow "Edit chapter \"2. c3\""
    And I set the field "Content" to "{discussion:b1: c2 | Acceptance test site}"
    And I press "Save changes"
    And the embedded discussion is loaded
    Then I should see "c2-discussion"

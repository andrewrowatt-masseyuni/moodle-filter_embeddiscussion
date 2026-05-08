@filter @filter_embeddiscussion
Feature: Anonymous and locked thread settings driven by filter token keywords
  In order to control the tone of an embedded discussion
  As a teacher
  I should be able to enable anonymous mode or lock a thread by adding keywords to the filter token

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the "embeddiscussion" filter is "on"
    And I change the window size to "large"

  @javascript
  Scenario: Anonymous keyword on the token shows the anonymity notice to a student
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                                          |
      | Discuss A | Chapter 1 | {embeddeddiscussion:Settings demo,anonymous}     |
    And the following "filter_embeddiscussion > threads" exist:
      | name          | course | activity |
      | Settings demo | C1     | book1    |
    And the following "filter_embeddiscussion > posts" exist:
      | thread        | user     | content                |
      | Settings demo | student1 | Hello from a student   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then I should see "Your posts will be anonymous to other students"

  @javascript
  Scenario: Locked keyword on the token shows the lock alert and disables the composer for students
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                                       |
      | Discuss A | Chapter 1 | {embeddeddiscussion:Settings demo,locked}     |
    And the following "filter_embeddiscussion > threads" exist:
      | name          | course | activity |
      | Settings demo | C1     | book1    |
    And the following "filter_embeddiscussion > posts" exist:
      | thread        | user     | content                |
      | Settings demo | student1 | Hello from a student   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then I should see "This discussion is locked. New posts and edits are disabled."
    And "[data-action='open-composer'][disabled]" "css_element" should exist

  @javascript
  Scenario: Both keywords in either order with extra spacing apply to the thread
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                                                        |
      | Discuss A | Chapter 1 | {embeddeddiscussion:Settings demo, locked , anonymous ,}       |
    And the following "filter_embeddiscussion > threads" exist:
      | name          | course | activity |
      | Settings demo | C1     | book1    |
    And the following "filter_embeddiscussion > posts" exist:
      | thread        | user     | content                |
      | Settings demo | student1 | Hello from a student   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then I should see "Your posts will be anonymous to other students"
    And I should see "This discussion is locked. New posts and edits are disabled."

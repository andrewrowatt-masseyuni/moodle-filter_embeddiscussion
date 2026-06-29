@filter @filter_embeddiscussion @unsupported_locations
Feature: Nameless tokens outside Book require an explicit thread name
  In order to avoid silently creating badly named discussions
  As a course participant
  I should be told a thread name is required when a nameless token is used outside a Book chapter

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category | format | numsections |
      | Course 1 | C1        | 0        | topics | 1           |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the "embeddiscussion" filter is "on"

  @javascript
  Scenario: Staff sees a thread-name-required message for a nameless token in a label
    Given the following "activities" exist:
      | activity | course | name               | intro                                                          | idnumber |
      | label    | C1     | Unsupported label  | Before {discussion} after                                      | label1   |
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    Then I should see "A thread name is required"
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should not exist

  @javascript
  Scenario: Student sees the uninitialised notice for a nameless token in a label
    Given the following "activities" exist:
      | activity | course | name               | intro                                                          | idnumber |
      | label    | C1     | Unsupported label  | Before {discussion} after                                      | label1   |
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "advise your teaching team"
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should not exist

  @javascript
  Scenario: Staff sees a thread-name-required message for a nameless token in a page body
    Given the following "activities" exist:
      | activity | course | name              | intro      | content                                                      | idnumber |
      | page     | C1     | Unsupported page  | Page desc  | Before {discussion} after                                    | page1    |
    When I am on the "Unsupported page" "page activity" page logged in as "teacher1"
    Then I should see "A thread name is required"
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should not exist

  @javascript
  Scenario: Student sees the uninitialised notice for a nameless token in a page body
    Given the following "activities" exist:
      | activity | course | name              | intro      | content                                                      | idnumber |
      | page     | C1     | Unsupported page  | Page desc  | Before {discussion} after                                    | page1    |
    When I am on the "Unsupported page" "page activity" page logged in as "student1"
    Then I should see "advise your teaching team"
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should not exist

  @javascript
  Scenario: Staff sees a thread-name-required message for a nameless token in a section summary
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I edit the section "1" and I fill the form with:
      | Description | Before {discussion} after                     |
    Then I should see "A thread name is required"
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should not exist

  @javascript
  Scenario: Student sees the uninitialised notice for a nameless token in a section summary
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I edit the section "1" and I fill the form with:
      | Description | Before {discussion} after                     |
    When I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "advise your teaching team"
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should not exist

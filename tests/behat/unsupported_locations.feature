@filter @filter_embeddiscussion @unsupported_locations
Feature: Nameless tokens outside Book embed discussions
  In order to discuss course content from common Moodle locations
  As a course participant
  I should see embedded discussions for nameless tokens outside Book chapters

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
  Scenario: Teacher sees a discussion for nameless tokens in a label
    Given the following "activities" exist:
      | activity | course | name               | intro                                                          | idnumber |
      | label    | C1     | Unsupported label  | Before {discussion} after                                      | label1   |
    When I log in as "teacher1"
    And I am on "Course 1" course homepage
    Then the embedded discussion is loaded
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion']" "css_element" should exist
    And I should not see "Discussions cannot be embedded here. Only Book chapters are currently supported"

  @javascript
  Scenario: Student sees a discussion for nameless tokens in a label
    Given the following "activities" exist:
      | activity | course | name               | intro                                                          | idnumber |
      | label    | C1     | Unsupported label  | Before {discussion} after                                      | label1   |
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then the embedded discussion is loaded
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion']" "css_element" should exist
    And I should not see "Discussions cannot be embedded here. Only Book chapters are currently supported"

  @javascript
  Scenario: Teacher sees a discussion for nameless tokens in a page body
    Given the following "activities" exist:
      | activity | course | name              | intro      | content                                                      | idnumber |
      | page     | C1     | Unsupported page  | Page desc  | Before {discussion} after                                    | page1    |
    When I am on the "Unsupported page" "page activity" page logged in as "teacher1"
    Then the embedded discussion is loaded
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion']" "css_element" should exist
    And I should not see "Discussions cannot be embedded here. Only Book chapters are currently supported"

  @javascript
  Scenario: Student sees a discussion for nameless tokens in a page body
    Given the following "activities" exist:
      | activity | course | name              | intro      | content                                                      | idnumber |
      | page     | C1     | Unsupported page  | Page desc  | Before {discussion} after                                    | page1    |
    When I am on the "Unsupported page" "page activity" page logged in as "student1"
    Then the embedded discussion is loaded
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion']" "css_element" should exist
    And I should not see "Discussions cannot be embedded here. Only Book chapters are currently supported"

  @javascript
  Scenario: Teacher sees a discussion for nameless tokens in a section summary
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I edit the section "1" and I fill the form with:
      | Description | Before {discussion} after                     |
    Then the embedded discussion is loaded
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion']" "css_element" should exist
    And I should not see "Discussions cannot be embedded here. Only Book chapters are currently supported"

  @javascript
  Scenario: Student sees a discussion for nameless tokens in a section summary
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I edit the section "1" and I fill the form with:
      | Description | Before {discussion} after                     |
    When I log out
    And I log in as "student1"
    And I am on "Course 1" course homepage
    Then the embedded discussion is loaded
    And I should not see "{discussion}"
    And "[data-region='filter-embeddiscussion']" "css_element" should exist
    And I should not see "Discussions cannot be embedded here. Only Book chapters are currently supported"

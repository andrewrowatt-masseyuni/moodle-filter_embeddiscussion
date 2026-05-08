@filter @filter_embeddiscussion
Feature: Discussion tokens with no thread name default to the page name in Book chapters
  In order to keep tokens short in Book chapters where the chapter title is a sensible thread name
  As a teacher
  I should be able to omit the thread name in Book chapters and still render a discussion

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

  @javascript
  Scenario: {discussion} renders a placeholder using the page name
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content         |
      | Discuss A | Chapter 1 | {discussion}    |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='0']" "css_element" should exist

  @javascript
  Scenario: {anondiscussion} defaults the name and applies anonymous handles
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content             |
      | Discuss A | Chapter 1 | {anondiscussion}    |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='1']" "css_element" should exist

  @javascript
  Scenario: {anonymousdiscussion} works as an alias of {anondiscussion}
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                  |
      | Discuss A | Chapter 1 | {anonymousdiscussion}    |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='1']" "css_element" should exist

  @javascript
  Scenario: Explicit thread names still work with {discussion:...}
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                     |
      | Discuss A | Chapter 1 | {discussion:Named thread}   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='0']" "css_element" should exist

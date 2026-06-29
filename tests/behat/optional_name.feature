@filter @filter_embeddiscussion
Feature: Discussion tokens with no thread name default to the book and chapter name in Book chapters
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
  Scenario: {discussion} renders a placeholder using the book and chapter name
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content         |
      | Discuss A | Chapter 1 | {discussion}    |
    # An editor visit initialises the thread (filter/embeddiscussion:createthread).
    And I am on the "Discuss A" "book activity" page logged in as "teacher1"
    And the embedded discussion is loaded
    And I log out
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
    # An editor visit initialises the thread and writes anonymous=1 from the token.
    And I am on the "Discuss A" "book activity" page logged in as "teacher1"
    And the embedded discussion is loaded
    And I log out
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
    # An editor visit initialises the thread and writes anonymous=1 from the token.
    And I am on the "Discuss A" "book activity" page logged in as "teacher1"
    And the embedded discussion is loaded
    And I log out
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
    And the following "filter_embeddiscussion > threads" exist:
      | name         | course | activity |
      | Named thread | C1     | book1    |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='0']" "css_element" should exist

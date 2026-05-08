@filter @filter_embeddiscussion
Feature: Embeddiscussion tokens with no thread name default to the page name
  In order to keep tokens short in Book chapters where the chapter title is a sensible thread name
  As a teacher
  I should be able to omit the thread name from the canonical token and have the
  filter render a thread named after the page, with optional anonymous/locked
  keywords supplied either after a comma or after a colon

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
  Scenario: {embeddiscussion} renders a placeholder using the page name
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content             |
      | Discuss A | Chapter 1 | {embeddiscussion}   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='0'][data-locked='0']" "css_element" should exist

  @javascript
  Scenario: {embeddiscussion,anon} defaults the name and applies anonymous handles
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                  |
      | Discuss A | Chapter 1 | {embeddiscussion,anon}   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='1'][data-locked='0']" "css_element" should exist

  @javascript
  Scenario: {embeddiscussion,locked} defaults the name and locks the thread
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                    |
      | Discuss A | Chapter 1 | {embeddiscussion,locked}   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='0'][data-locked='1']" "css_element" should exist

  @javascript
  Scenario: {embeddiscussion,anon,locked} defaults the name and applies both keywords
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                         |
      | Discuss A | Chapter 1 | {embeddiscussion,anon,locked}   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='1'][data-locked='1']" "css_element" should exist

  @javascript
  Scenario: {embeddiscussion:anon} defaults the name and applies anonymous handles
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                  |
      | Discuss A | Chapter 1 | {embeddiscussion:anon}   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='1'][data-locked='0']" "css_element" should exist

  @javascript
  Scenario: {embeddiscussion:locked,anon} defaults the name and applies both keywords
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                         |
      | Discuss A | Chapter 1 | {embeddiscussion:locked,anon}   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='1'][data-locked='1']" "css_element" should exist

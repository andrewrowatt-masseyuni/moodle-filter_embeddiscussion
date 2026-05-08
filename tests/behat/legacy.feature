@filter @filter_embeddiscussion
Feature: Legacy filter_disqus and {comments} tokens are accepted as drop-in replacements
  In order to migrate existing content from filter_disqus and the comments block
  As a teacher
  I should be able to leave the legacy [[filter_disqus]] and {comments} tokens in place and
  have the discussion filter render them with the current page name

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
    And the following config values are set as admin:
      | legacyfilterdisqus | 1 | filter_embeddiscussion |
      | legacycomments     | 1 | filter_embeddiscussion |
    And the "embeddiscussion" filter is "on"

  @javascript
  Scenario: [[filter_disqus]] renders a placeholder using the page name with the site name stripped
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content            |
      | Discuss A | Chapter 1 | [[filter_disqus]]  |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='0']" "css_element" should exist

  @javascript
  Scenario: [[filter_disqus:url_segment]] is ignored
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                     |
      | Discuss A | Chapter 1 | [[filter_disqus:book-23]]   |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should not exist
    And I should see "[[filter_disqus:book-23]]"

  @javascript
  Scenario: {comments} renders a placeholder using the page name with the site name stripped
    Given the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content     |
      | Discuss A | Chapter 1 | {comments}  |
    When I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root']" "css_element" should exist

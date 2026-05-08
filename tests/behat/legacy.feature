@filter @filter_embeddiscussion
Feature: Legacy filter_disqus and {comments} tokens are accepted as drop-in replacements
  In order to migrate existing content from filter_disqus and the comments block
  As a teacher
  I should be able to leave the legacy [[filter_disqus]], [[filter_disqus:url_segment]] and
  {comments} tokens in place and have the embeddiscussion filter render them with the
  current page name (with the trailing site name removed) as the thread name

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
      | activity | course | name      | intro              | idnumber |
      | label    | C1     | Discuss A | [[filter_disqus]]  | l1       |
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root'][data-anonymous='0'][data-locked='0']" "css_element" should exist

  @javascript
  Scenario: [[filter_disqus:url_segment]] renders a placeholder with the URL segment in parentheses
    Given the following "activities" exist:
      | activity | course | name      | intro                       | idnumber |
      | label    | C1     | Discuss A | [[filter_disqus:book-23]]   | l1       |
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root']" "css_element" should exist

  @javascript
  Scenario: {comments} renders a placeholder using the page name with the site name stripped
    Given the following "activities" exist:
      | activity | course | name      | intro       | idnumber |
      | label    | C1     | Discuss A | {comments}  | l1       |
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then "[data-region='filter-embeddiscussion'][data-threadid]" "css_element" should exist
    And "[data-region='embeddisc-root']" "css_element" should exist

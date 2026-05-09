@filter @filter_embeddiscussion
Feature: Embedded discussion dashboard
  In order to catch up on conversations across a course
  As a student
  I should see new posts since my last visit listed on a dashboard

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
    And the following "activities" exist:
      | activity | course | name               | idnumber    |
      | book     | C1     | Visible chat book  | visiblebook |
      | book     | C1     | Activity feed book | dashbook    |
    And the following "mod_book > chapters" exist:
      | book               | title              | content                            |
      | Visible chat book  | Visible chapter    | {discussion:Visible chat}          |
      | Activity feed book | Dashboard chapter  | {discussion:latestposts}           |
    # Backdate the student's last visit so posts created next pass the cutoff
    # but the dashboard page-load doesn't bump the timestamp forward (the
    # debounce is 60 seconds in user_accesstime_log).
    And user "student1" last accessed course "C1" "30" seconds ago
    And the following "filter_embeddiscussion > threads" exist:
      | name         | course | activity |
      | Visible chat | C1     | visiblebook |
    And the following "filter_embeddiscussion > posts" exist:
      | thread       | user     | content                            |
      | Visible chat | teacher1 | Welcome aboard, feel free to chat. |
    And I change the window size to "large"

  @javascript
  Scenario: Dashboard lists posts created since the student's last visit
    Given I log in as "student1"
    And I am on the "Activity feed book" "book activity" page
    And the embedded discussion dashboard is loaded
    Then I should see "Visible chat"
    And I should see "Welcome aboard"

  @javascript
  Scenario: Dashboard hides posts in modules the student cannot see
    Given the following "activities" exist:
      | activity | course | name              | idnumber   | visible |
      | book     | C1     | Hidden chat book  | hiddenbook | 0       |
    And the following "mod_book > chapters" exist:
      | book             | title           | content                            |
      | Hidden chat book | Hidden chapter  | {discussion:Hidden chat}           |
    And the following "filter_embeddiscussion > threads" exist:
      | name        | course | activity |
      | Hidden chat | C1     | hiddenbook |
    And the following "filter_embeddiscussion > posts" exist:
      | thread      | user     | content                          |
      | Hidden chat | teacher1 | This message is in a hidden mod. |
    And I log in as "student1"
    And I am on the "Activity feed book" "book activity" page
    And the embedded discussion dashboard is loaded
    Then I should see "Visible chat"
    And I should not see "Hidden chat"
    And I should not see "This message is in a hidden mod"

  @javascript
  Scenario: Dashboard links section summary posts back to the course homepage
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I edit the section "1" and I fill the form with:
      | Description | Summary intro {discussion:Section updates} |
    And the following "filter_embeddiscussion > threads" exist:
      | name            | course | activity |
      | Section updates | C1     |          |
    And the following "filter_embeddiscussion > posts" exist:
      | thread          | user     | content                               |
      | Section updates | teacher1 | Section discussion dashboard message  |
    And I am on "Course 1" course homepage
    And the embedded discussion is loaded
    And I log out
    And I log in as "student1"
    And I am on the "Activity feed book" "book activity" page
    And the embedded discussion dashboard is loaded
    Then I should see "Section discussion dashboard message"
    When I follow "Section discussion dashboard message"
    And the embedded discussion is loaded
    Then the url should match "/course/view\.php\?id=[0-9]+"
    And I should see "Summary intro"
    And I should see "Section discussion dashboard message"

  @javascript
  Scenario: Dashboard links label posts back to the course homepage
    Given the following "activities" exist:
      | activity | course | name                | idnumber  | intro                                  |
      | label    | C1     | Section news label  | labeldash | Label intro {discussion:Label updates} |
    And the following "filter_embeddiscussion > threads" exist:
      | name          | course | activity  |
      | Label updates | C1     | labeldash |
    And the following "filter_embeddiscussion > posts" exist:
      | thread        | user     | content                             |
      | Label updates | teacher1 | Label discussion dashboard message  |
    And I log in as "teacher1"
    And I am on "Course 1" course homepage
    And the embedded discussion is loaded
    And I log out
    And I log in as "student1"
    And I am on the "Activity feed book" "book activity" page
    And the embedded discussion dashboard is loaded
    Then I should see "Label discussion dashboard message"
    When I follow "Label discussion dashboard message"
    And the embedded discussion is loaded
    Then the url should match "/course/view\.php\?id=[0-9]+"
    And I should see "Label intro"
    And I should see "Label discussion dashboard message"

  @javascript
  Scenario: Dashboard links page posts back to the page activity
    Given the following "activities" exist:
      | activity | course | name               | idnumber | intro     | content                              |
      | page     | C1     | Section news page  | pagedash | Page desc | Page body {discussion:Page updates}  |
    And the following "filter_embeddiscussion > threads" exist:
      | name         | course | activity |
      | Page updates | C1     | pagedash |
    And the following "filter_embeddiscussion > posts" exist:
      | thread       | user     | content                            |
      | Page updates | teacher1 | Page discussion dashboard message  |
    And I log in as "teacher1"
    And I am on the "Section news page" "page activity" page
    And the embedded discussion is loaded
    And I log out
    And I log in as "student1"
    And I am on the "Activity feed book" "book activity" page
    And the embedded discussion dashboard is loaded
    Then I should see "Page discussion dashboard message"
    When I follow "Page discussion dashboard message"
    And the embedded discussion is loaded
    Then the url should match "/mod/page/view\.php\?id=[0-9]+"
    And I should see "Page body"
    And I should see "Page discussion dashboard message"

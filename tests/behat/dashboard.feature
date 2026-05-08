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
      | activity | course | name          | intro                              | idnumber |
      | label    | C1     | Visible chat  | {embeddeddiscussion:Visible chat}  | l1       |
      | label    | C1     | Activity feed | {embeddiscussion:latestposts}      | dash     |
    # Backdate the student's last visit so posts created next pass the cutoff
    # but the dashboard page-load doesn't bump the timestamp forward (the
    # debounce is 60 seconds in user_accesstime_log).
    And user "student1" last accessed course "C1" "30" seconds ago
    And the following "filter_embeddiscussion > threads" exist:
      | name         | course | activity |
      | Visible chat | C1     | l1       |
    And the following "filter_embeddiscussion > posts" exist:
      | thread       | user     | content                            |
      | Visible chat | teacher1 | Welcome aboard, feel free to chat. |
    And I change the window size to "large"

  @javascript
  Scenario: Dashboard lists posts created since the student's last visit
    Given I log in as "student1"
    And I am on "Course 1" course homepage
    And the embedded discussion dashboard is loaded
    Then I should see "Visible chat"
    And I should see "Welcome aboard"

  @javascript
  Scenario: Dashboard hides posts in modules the student cannot see
    Given the following "activities" exist:
      | activity | course | name         | intro                            | idnumber | visible |
      | label    | C1     | Hidden chat  | {embeddeddiscussion:Hidden chat} | l2       | 0       |
    And the following "filter_embeddiscussion > threads" exist:
      | name        | course | activity |
      | Hidden chat | C1     | l2       |
    And the following "filter_embeddiscussion > posts" exist:
      | thread      | user     | content                          |
      | Hidden chat | teacher1 | This message is in a hidden mod. |
    And I log in as "student1"
    And I am on "Course 1" course homepage
    And the embedded discussion dashboard is loaded
    Then I should see "Visible chat"
    And I should not see "Hidden chat"
    And I should not see "This message is in a hidden mod"

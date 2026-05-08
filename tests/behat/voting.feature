@filter @filter_embeddiscussion
Feature: Voting on embedded discussion posts
  In order to surface useful contributions
  As a participant
  I should be able to vote up or down a post and remove my vote

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Teacher   | One      |
      | student1 | Student   | One      |
      | student2 | Student   | Two      |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the "embeddiscussion" filter is "on"
    And the following "activities" exist:
      | activity | course | name      | idnumber |
      | book     | C1     | Discuss A | book1    |
    And the following "mod_book > chapters" exist:
      | book      | title     | content                              |
      | Discuss A | Chapter 1 | {embeddeddiscussion:Voting demo}     |
    And the following "filter_embeddiscussion > threads" exist:
      | name        | course | activity |
      | Voting demo | C1     | book1    |
    And the following "filter_embeddiscussion > posts" exist:
      | thread      | user     | content                                |
      | Voting demo | student1 | First post by student one for voting   |

    And I change the window size to "large"

  @javascript
  Scenario: Student up-votes a post and the count increases
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then the "up" vote count on the embedded discussion post containing "First post by student one for voting" should be "0"
    When I click the "up" vote button on the embedded discussion post containing "First post by student one for voting"
    Then the "up" vote count on the embedded discussion post containing "First post by student one for voting" should be "1"
    And the "up" vote on the embedded discussion post containing "First post by student one for voting" should be marked as active

  @javascript
  Scenario: Clicking the same up vote a second time clears the vote
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I click the "up" vote button on the embedded discussion post containing "First post by student one for voting"
    And I click the "up" vote button on the embedded discussion post containing "First post by student one for voting"
    Then the "up" vote count on the embedded discussion post containing "First post by student one for voting" should be "0"
    And the "up" vote on the embedded discussion post containing "First post by student one for voting" should not be marked as active

  @javascript
  Scenario: Switching from up to down vote moves the count between buckets
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I click the "up" vote button on the embedded discussion post containing "First post by student one for voting"
    Then the "up" vote count on the embedded discussion post containing "First post by student one for voting" should be "1"
    And the "down" vote count on the embedded discussion post containing "First post by student one for voting" should be "0"
    When I click the "down" vote button on the embedded discussion post containing "First post by student one for voting"
    Then the "up" vote count on the embedded discussion post containing "First post by student one for voting" should be "0"
    And the "down" vote count on the embedded discussion post containing "First post by student one for voting" should be "1"
    And the "down" vote on the embedded discussion post containing "First post by student one for voting" should be marked as active

  @javascript
  Scenario: Two different students each contribute one vote in opposite directions
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I click the "up" vote button on the embedded discussion post containing "First post by student one for voting"
    And I log out
    And I log in as "teacher1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then the "up" vote count on the embedded discussion post containing "First post by student one for voting" should be "1"
    When I click the "down" vote button on the embedded discussion post containing "First post by student one for voting"
    Then the "up" vote count on the embedded discussion post containing "First post by student one for voting" should be "1"
    And the "down" vote count on the embedded discussion post containing "First post by student one for voting" should be "1"

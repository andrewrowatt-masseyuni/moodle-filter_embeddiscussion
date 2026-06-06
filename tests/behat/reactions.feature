@filter @filter_embeddiscussion
Feature: Reacting to embedded discussion posts
  In order to give lightweight feedback on contributions
  As a participant
  I should be able to add and remove emoji reactions on a post

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
      | book      | title     | content                   |
      | Discuss A | Chapter 1 | {discussion:Reactions demo} |
    And the following "filter_embeddiscussion > threads" exist:
      | name           | course | activity |
      | Reactions demo | C1     | book1    |
    And the following "filter_embeddiscussion > posts" exist:
      | thread         | user     | content                                  |
      | Reactions demo | student1 | First post by student one for reactions  |

    And I change the window size to "large"

  @javascript
  Scenario: Student reacts to a post and the count increases
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then the reaction count on the embedded discussion post containing "First post by student one for reactions" should be "0"
    When I open the reactions picker on the embedded discussion post containing "First post by student one for reactions"
    And I react with the "thumbsup" emoji on the embedded discussion post containing "First post by student one for reactions"
    Then the reaction count on the embedded discussion post containing "First post by student one for reactions" should be "1"
    And the "thumbsup" reaction on the embedded discussion post containing "First post by student one for reactions" should be marked as mine

  @javascript
  Scenario: Reacting with the same emoji a second time removes it
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I react with the "thumbsup" emoji on the embedded discussion post containing "First post by student one for reactions"
    And I react with the "thumbsup" emoji on the embedded discussion post containing "First post by student one for reactions"
    Then the reaction count on the embedded discussion post containing "First post by student one for reactions" should be "0"
    And the "thumbsup" reaction on the embedded discussion post containing "First post by student one for reactions" should not be marked as mine

  @javascript
  Scenario: One user can stack multiple different reactions on a post
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I react with the "thumbsup" emoji on the embedded discussion post containing "First post by student one for reactions"
    And I react with the "heart" emoji on the embedded discussion post containing "First post by student one for reactions"
    Then the reaction count on the embedded discussion post containing "First post by student one for reactions" should be "2"
    And the "thumbsup" reaction on the embedded discussion post containing "First post by student one for reactions" should be marked as mine
    And the "heart" reaction on the embedded discussion post containing "First post by student one for reactions" should be marked as mine

  @javascript
  Scenario: Reactions from different users aggregate on the same post
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I react with the "thumbsup" emoji on the embedded discussion post containing "First post by student one for reactions"
    And I log out
    And I log in as "teacher1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then the reaction count on the embedded discussion post containing "First post by student one for reactions" should be "1"
    When I react with the "heart" emoji on the embedded discussion post containing "First post by student one for reactions"
    Then the reaction count on the embedded discussion post containing "First post by student one for reactions" should be "2"
    And the "heart" reaction on the embedded discussion post containing "First post by student one for reactions" should be marked as mine
    And the "thumbsup" reaction on the embedded discussion post containing "First post by student one for reactions" should not be marked as mine

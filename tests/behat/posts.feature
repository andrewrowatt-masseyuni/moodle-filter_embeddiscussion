@filter @filter_embeddiscussion
Feature: Editing and deleting embedded discussion posts
  In order to manage their contributions
  As a student or teacher
  I should be able to edit and delete posts

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
      | book      | title     | content                            |
      | Discuss A | Chapter 1 | {discussion:Posts demo}            |
    And the following "filter_embeddiscussion > threads" exist:
      | name       | course | activity |
      | Posts demo | C1     | book1    |
    And the following "filter_embeddiscussion > posts" exist:
      | thread     | user     | content                                  |
      | Posts demo | student1 | First post by student one to talk about  |
      | Posts demo | student2 | Reply text from the second student here  |

    And I change the window size to "large"

  @javascript
  Scenario: Author sees Edit and Delete buttons on their own post
    Given I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then I should see "First post by student one"
    And I should see "Reply text from the second student"

  @javascript
  Scenario: Student deletes their own post and sees the deleted placeholder
    Given I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I click "Delete" on the embedded discussion post containing "First post by student one"
    And I click on "Delete" "button" in the "Delete" "dialogue"
    And I wait until the page is ready
    Then I should see "Post deleted."
    And I should not see "First post by student one"

  @javascript
  Scenario: Another student does not see Edit or Delete on someone else's post
    Given I log in as "student2"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    Then I should not see the "Delete" action on the embedded discussion post containing "First post by student one"
    And I should not see the "Edit" action on the embedded discussion post containing "First post by student one"

  @javascript
  Scenario: Teacher can delete any student's post
    Given I log in as "teacher1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I click "Delete" on the embedded discussion post containing "Reply text from the second student"
    And I click on "Delete" "button" in the "Delete" "dialogue"
    And I wait until the page is ready
    Then I should not see "Reply text from the second student"

  @javascript
  Scenario: Edit affordance shows the inline editor for the author and Cancel restores the post
    Given I log in as "student1"
    And I am on the "Discuss A" "book activity" page
    And the embedded discussion is loaded
    When I click "Edit" on the embedded discussion post containing "First post by student one"
    Then "[data-action='cancel-edit']" "css_element" should exist
    And "[data-action='submit-edit']" "css_element" should exist
    When I click on "[data-action='cancel-edit']" "css_element"
    Then "[data-action='cancel-edit']" "css_element" should not exist
    And I should see "First post by student one"

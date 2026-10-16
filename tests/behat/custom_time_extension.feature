@quiz_livequizmonitor @mod @mod_quiz @quiz @report @extend
Feature: Custom time extension in live quiz monitor
  In order to give a student exactly the extra time they need
  As a teacher
  I need to enter a custom number of minutes in the extend time modal

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
      | student1 | Sam       | Student  | student1@test.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name   | course | timelimit |
      | quiz     | Quiz 1 | C1     | 600       |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype       | name | questiontext |
      | Test questions   | shortanswer | SA1  | What is 2+2? |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And I log in as "teacher1"

  @javascript
  Scenario: Individual extend modal opens from row action menu
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Extend time" "link"
    Then I should see "Extend quiz time"
    And I should see "Presets"

  @javascript
  Scenario: Bulk extend button opens modal when students are in progress
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on "Extend time" "button"
    Then I should see "Extend quiz time"
    And I should see "Presets"

  @javascript
  Scenario: A preset button fills the custom input with its value
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Extend time" "link"

    When I click on "+30m" "button"
    Then the field "Custom minutes" matches value "30"
    And I should see "Confirm — add 30 min"

  @javascript
  Scenario: An out-of-range custom value disables the confirm button
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Extend time" "link"

    When I set the field "Custom minutes" to "181"
    Then I should see "Enter a whole number of minutes, from 1 to 180."
    And "[data-action='save'][disabled]" "css_element" should exist

  @javascript
  Scenario: A non-numeric custom value disables the confirm button and shows a hint
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Extend time" "link"

    When I set the field "Custom minutes" to "abc"
    Then I should see "Enter a whole number of minutes, from 1 to 180."
    And "[data-action='save'][disabled]" "css_element" should exist

  @javascript
  Scenario: Confirming a custom, non-preset extension applies it
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Extend time" "link"

    When I set the field "Custom minutes" to "45"
    Then "[data-action='save'][disabled]" "css_element" should not exist
    And I should see "Confirm — add 45 min"

    When I click on "Confirm — add 45 min" "button"
    Then I should see "Added 45 minutes"

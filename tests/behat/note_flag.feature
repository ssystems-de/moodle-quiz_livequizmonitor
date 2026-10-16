@quiz_livequizmonitor @mod @mod_quiz @quiz @report @notes
Feature: Note flag icon in live quiz monitor
  In order to see at a glance which students have a supervision note
  As a teacher
  I need an icon next to a student's name whenever a note exists for them

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
  Scenario: No note flag is shown before a note exists
    Given I am on the live monitor report for "Quiz 1"
    Then I should see "Sam Student"
    And ".livequizmonitor-note-flag" "css_element" should not exist

  @javascript
  Scenario: A note flag appears next to the student's name after a note is saved
    Given I am on the live monitor report for "Quiz 1"
    And ".livequizmonitor-note-flag" "css_element" should not exist
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Add note" "link"
    And I set the field "Add a supervision note for this student." to "Requested bathroom break"
    When I click on "Save" "button"
    Then "[data-field='fullname'] .livequizmonitor-note-flag" "css_element" should exist
    And "[title='This student has a note']" "css_element" should exist

  @javascript
  Scenario: The note flag disappears once the note is cleared
    Given I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Add note" "link"
    And I set the field "Add a supervision note for this student." to "Requested bathroom break"
    And I click on "Save" "button"
    And "[data-field='fullname'] .livequizmonitor-note-flag" "css_element" should exist

    When I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Edit note" "link"
    And I set the field "Add a supervision note for this student." to ""
    And I click on "Save" "button"
    Then ".livequizmonitor-note-flag" "css_element" should not exist

@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Filter by user override in live quiz monitor
  In order to quickly find students with individual extensions
  As a teacher
  I need to filter the live quiz monitor by user override

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
      | student1 | Student   | ONE      | student1@test.com |
      | student2 | Student   | TWO      | student2@test.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "activities" exist:
      | activity | name   | course | timelimit |
      | quiz     | Quiz 1 | C1     | 0         |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype       | name | questiontext |
      | Test questions   | shortanswer | SA1  | What is 2+2? |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And user "student1" has a time limit override on quiz "Quiz 1"

  @javascript
  Scenario: Teacher can filter students down to only those with a user override
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then "With user override (1)" "button" should exist
    And I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should see "Student TWO" in the "[data-region='student-table']" "css_element"

    When I click on "With user override (1)" "button"
    Then I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should not see "Student TWO" in the "[data-region='student-table']" "css_element"

    # Toggling the same button off restores the full list.
    When I click on "With user override (1)" "button"
    Then I should see "Student TWO" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Clear filters resets the user override toggle
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    When I click on "With user override (1)" "button"
    And I should not see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I click on "Reset all filters" "button"

    Then I should see "Student TWO" in the "[data-region='student-table']" "css_element"
    And "With user override (1)" "button" should exist

  @javascript
  Scenario: Teacher without manageoverrides or viewoverrides capability does not see the filter
    Given the following "permission overrides" exist:
      | capability                | permission | role           | contextlevel | reference |
      | mod/quiz:manageoverrides  | Prevent    | editingteacher | Course       | C1        |
      | mod/quiz:viewoverrides    | Prevent    | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then "With user override" "button" should not exist

  @javascript
  Scenario: Time-related override shows a badge on the timer, attempts-only override does not
    Given user "student2" has an attempts override on quiz "Quiz 1"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    # Both students have SOME override, so both get the name-column badge.
    Then "[data-field='fullname'] .livequizmonitor-override-flag" "css_element" should exist in the "Student ONE" "table_row"
    And "[data-field='fullname'] .livequizmonitor-override-flag" "css_element" should exist in the "Student TWO" "table_row"

    # Only the time-related override (student1) gets the timer-column badge.
    And "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should exist in the "Student ONE" "table_row"
    And "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should not exist in the "Student TWO" "table_row"

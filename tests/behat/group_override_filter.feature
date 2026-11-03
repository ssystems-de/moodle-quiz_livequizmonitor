@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Filter by group override in live quiz monitor
  In order to quickly find students affected by a group extension
  As a teacher
  I need to filter the live quiz monitor by group override

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
      | student1 | Student   | ONE      | student1@test.com |
      | student2 | Student   | TWO      | student2@test.com |
      | student3 | Student   | THREE    | student3@test.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | GA       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student3 | GA    |
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
    And group "Group A" has a time limit override on quiz "Quiz 1"

  @javascript
  Scenario: Teacher can filter students down to only those with a group override
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then the live monitor "overrides" filter should show "2" for "Group"
    And I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

    When I select "Group" in the live monitor "overrides" filter
    Then I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should not see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Clear filters resets the group override toggle
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    When I select "Group" in the live monitor "overrides" filter
    And I should not see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I click on "Reset all filters" "button"

    Then I should see "Student TWO" in the "[data-region='student-table']" "css_element"
    And the live monitor "overrides" filter should show "2" for "Group"

  @javascript
  Scenario: A user override only takes precedence for the settings it overrides
    Given user "student1" has an attempts override on quiz "Quiz 1"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    # student1's user override sets only attempts, so Group A's time limit still applies:
    # student1 counts towards both totals and shows both badges plus the timer badge.
    Then the live monitor "overrides" filter should show "2" for "Group"
    And the live monitor "overrides" filter should show "1" for "User"
    And "[data-field='fullname'] i[data-override-badge='user']" "css_element" should exist in the "Student ONE" "table_row"
    And "[data-field='fullname'] i[data-override-badge='group']" "css_element" should exist in the "Student ONE" "table_row"
    And "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should exist in the "Student ONE" "table_row"

    # student3 (group only) shows the group-override badge only.
    And "[data-field='fullname'] i[data-override-badge='group']" "css_element" should exist in the "Student THREE" "table_row"
    And "[data-field='fullname'] i[data-override-badge='user']" "css_element" should not exist in the "Student THREE" "table_row"

  @javascript
  Scenario: A user override replaces a group override that sets the same settings
    Given the following "mod_quiz > user overrides" exist:
      | quiz   | user     | timelimit |
      | Quiz 1 | student1 | 7200      |
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    # Group A only overrides the time limit, which student1's user override also sets,
    # so the group override has no effect on student1.
    Then the live monitor "overrides" filter should show "1" for "Group"
    And the live monitor "overrides" filter should show "1" for "User"
    And "[data-field='fullname'] i[data-override-badge='user']" "css_element" should exist in the "Student ONE" "table_row"
    And "[data-field='fullname'] i[data-override-badge='group']" "css_element" should not exist in the "Student ONE" "table_row"
    And "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should exist in the "Student ONE" "table_row"

  @javascript
  Scenario: Time-related badge appears when only one of a student's groups overrides time
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group B | C1     | GB       |
      | Group C | C1     | GC       |
    And the following "group members" exist:
      | user     | group |
      | student2 | GB    |
      | student2 | GC    |
    # Group B's override (no time settings) is created first, so it is read first.
    And the following "mod_quiz > group overrides" exist:
      | quiz   | group | attempts |
      | Quiz 1 | GB    | 2        |
    And the following "mod_quiz > group overrides" exist:
      | quiz   | group | timelimit |
      | Quiz 1 | GC    | 3600      |
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then the live monitor "overrides" filter should show "3" for "Group"
    And "[data-field='fullname'] i[data-override-badge='group']" "css_element" should exist in the "Student TWO" "table_row"
    And "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should exist in the "Student TWO" "table_row"

  @javascript
  Scenario: Time-related badge appears for both user and group time-related overrides
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    # Both group members get the timer badge from the group's time-related override.
    Then "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should exist in the "Student ONE" "table_row"
    And "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should exist in the "Student THREE" "table_row"
    And "[data-field='timeremaining'] .livequizmonitor-override-flag-timer" "css_element" should not exist in the "Student TWO" "table_row"

  @javascript
  Scenario: The user-override and group-override toggles are mutually exclusive with each other
    Given user "student2" has an attempts override on quiz "Quiz 1"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    When I select "User" in the live monitor "overrides" filter
    Then "User" should be selected in the live monitor "overrides" filter
    And "Group" should not be selected in the live monitor "overrides" filter

    # Clicking the group-override button cancels the user-override one, not adds to it.
    When I select "Group" in the live monitor "overrides" filter
    Then "Group" should be selected in the live monitor "overrides" filter
    And "User" should not be selected in the live monitor "overrides" filter
    And I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should not see "Student TWO" in the "[data-region='student-table']" "css_element"

    # They do NOT cancel the status chips, which remain an independent (AND) dimension.
    When I select "In progress" in the live monitor "status" filter
    Then "In progress" should be selected in the live monitor "status" filter
    And "Group" should be selected in the live monitor "overrides" filter

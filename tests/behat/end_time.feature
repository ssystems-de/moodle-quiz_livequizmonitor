@quiz_livequizmonitor @mod @mod_quiz @quiz @report @javascript
Feature: Show each student's end time in Live Quiz Monitor
  In order to know when each student must stop
  As a teacher
  I need to see the end time of every student's attempt

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
      | student1 | Sam       | Student  | student1@test.com |
      | student2 | Pat       | Pupil    | student2@test.com |
      | student3 | Nat       | Novice   | student3@test.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype       | name | questiontext |
      | Test questions   | shortanswer | SA1  | What is 2+2? |

  Scenario: End time is the start time plus the time limit, with the date when the day changes
    Given the following "activities" exist:
      | activity | name   | course | timelimit |
      | quiz     | Quiz 1 | C1     | 3600      |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And student "student1" has answered question 1 in quiz "Quiz 1"
    And the attempt by "student1" on quiz "Quiz 1" started at "2026-01-15 10:00"
    And student "student2" has answered question 1 in quiz "Quiz 1"
    And the attempt by "student2" on quiz "Quiz 1" started at "2026-01-15 23:30"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    Then I should see "End time" in the ".livequizmonitor-table thead" "css_element"
    And I should see "11:00" in the "Sam Student" "table_row"
    And I should see "16-Jan-2026 00:30" in the "Pat Pupil" "table_row"

  Scenario: A grace period shows the end time as a range, and a completed attempt shows when it was due to close
    Given the following "activities" exist:
      | activity | name   | course | timelimit | overduehandling | graceperiod |
      | quiz     | Quiz 1 | C1     | 4500      | graceperiod     | 300         |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And student "student1" has answered question 1 in quiz "Quiz 1"
    And the attempt by "student1" on quiz "Quiz 1" started at "2026-01-15 11:00"
    And student "student2" has completed quiz "Quiz 1"
    And the attempt by "student2" on quiz "Quiz 1" started at "2026-01-15 11:00"
    And the attempt by "student2" on quiz "Quiz 1" finished at "2026-01-15 11:42"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    Then I should see "12:15 ~ 12:20" in the "Sam Student" "table_row"
    # Pat submitted at 11:42, but the attempt was due to close at 12:15.
    And I should see "12:15 ~ 12:20" in the "Pat Pupil" "table_row"
    And I should not see "11:42" in the "Pat Pupil" "table_row"

  Scenario: Sort by end time, with students who have no end time last
    Given the following "activities" exist:
      | activity | name   | course | timelimit |
      | quiz     | Quiz 1 | C1     | 3600      |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And student "student1" has answered question 1 in quiz "Quiz 1"
    And the attempt by "student1" on quiz "Quiz 1" started at "2026-01-15 10:30"
    And student "student2" has answered question 1 in quiz "Quiz 1"
    And the attempt by "student2" on quiz "Quiz 1" started at "2026-01-15 10:00"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"
    When I click on "th[data-sort-column='endtime'] [data-action='sort-column']" "css_element"
    Then the following students should appear in order:
      | Pat Pupil   |
      | Sam Student |
      | Nat Novice  |
    When I click on "th[data-sort-column='endtime'] [data-action='sort-column']" "css_element"
    Then the following students should appear in order:
      | Sam Student |
      | Pat Pupil   |
      | Nat Novice  |

  Scenario: The End time column can be hidden and shown again
    Given the following "activities" exist:
      | activity | name   | course | timelimit |
      | quiz     | Quiz 1 | C1     | 3600      |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And student "student1" has answered question 1 in quiz "Quiz 1"
    And the attempt by "student1" on quiz "Quiz 1" started at "2026-01-15 10:00"
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"
    And I should see "11:00" in the "Sam Student" "table_row"
    When I click on ".livequizmonitor-th[data-column='endtime'] [data-action='toggle-column']" "css_element"
    Then I should not see "11:00" in the "Sam Student" "table_row"
    When I reload the page
    Then I should not see "11:00" in the "Sam Student" "table_row"
    When I click on ".livequizmonitor-th[data-column='endtime'] [data-action='toggle-column']" "css_element"
    Then I should see "11:00" in the "Sam Student" "table_row"

@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Live quiz monitor report
  In order to monitor quiz participation live
  As a teacher
  I need to open the Live Monitor report and see student statuses

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email           |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
      | student1 | Sam       | Student  | student1@test.com |
      | student2 | Alex      | Other    | student2@test.com |
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

  Scenario: Teacher opens Live Monitor report
    Given I am on the live monitor report for "Quiz 1"
    Then I should see "Live monitor"
    And I should see "Sam Student"
    And I should see "Not started"
    And I should see "In progress"
    And I should see "Completed"
    And "div.livequizmonitor-progress .progress-bar" "css_element" should exist

  @javascript
  Scenario: Search narrows the student list
    Given I am on the live monitor report for "Quiz 1"
    When I set the field "Search students…" to "Sam"
    Then I should see "Sam Student"
    But I should not see "Alex Other"

  @javascript
  Scenario: Clear filters restores the full student list
    Given I am on the live monitor report for "Quiz 1"
    When I set the field "Search students…" to "Sam"
    And I click on "Reset all filters" "button"
    Then I should see "Sam Student"
    And I should see "Alex Other"

  @javascript
  Scenario: Status filter shows only in-progress students
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on "In progress (1)" "button"
    Then I should see "Sam Student"
    But I should not see "Alex Other"

  @javascript @extend
  Scenario: Individual extend modal opens from row action menu
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    And I click on "Extend time" "link"
    Then I should see "Extend quiz time"
    And I should see "Add time"

  @javascript @extend
  Scenario: Bulk extend button opens modal when students are in progress
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on "Extend time" "button"
    Then I should see "Extend quiz time"
    And I should see "Add time"

  @javascript @notes
  Scenario: Teacher adds and edits a student note
    Given I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element" in the "Sam Student" "table_row"
    And I click on "Add note" "link" in the "Sam Student" "table_row"
    And I set the field "Add a supervision note for this student." to "Requested bathroom break"
    And I click on "Save" "button"
    When I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element" in the "Sam Student" "table_row"
    Then "Edit note" "link" should exist
    When I click on "Edit note" "link" in the "Sam Student" "table_row"
    Then the field "Add a supervision note for this student." matches value "Requested bathroom break"

  @javascript @onesession
  Scenario: B1 - No unblock UI when onesession is off
    Given I am on the live monitor report for "Quiz 1"
    When I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    Then "Unblock user" "link" should not exist

  @javascript @onesession
  Scenario: B2 - Unblock menu visible but disabled on non-blocked row
    Given onesession concurrent session rule is enabled for quiz "Quiz 1"
    And I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element"
    Then "Unblock user" "link" should exist
    And ".livequizmonitor-row-actions [data-action='unblock-student'].disabled" "css_element" should exist

  @javascript @onesession
  Scenario: B3 - Red flag and enabled unblock after block event
    Given onesession concurrent session rule is enabled for quiz "Quiz 1"
    And I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And the student "student1" is blocked by onesession on quiz "Quiz 1"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    Then ".livequizmonitor-blocked-flag" "css_element" should exist

  @javascript @onesession
  Scenario: B4 - Unblock confirmation modal opens
    Given onesession concurrent session rule is enabled for quiz "Quiz 1"
    And I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And the student "student1" is blocked by onesession on quiz "Quiz 1"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element" in the "Sam Student" "table_row"
    And I click on "Unblock user" "link" in the "Sam Student" "table_row"
    Then I should see "Unblock Sam Student"
    And I should see "Allow this student to continue the quiz attempt on another device or browser."

  @javascript @onesession
  Scenario: B5 - Post-confirm flag gone and unblock disabled
    Given onesession concurrent session rule is enabled for quiz "Quiz 1"
    And I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I press "Start attempt"
    And the student "student1" is blocked by onesession on quiz "Quiz 1"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I click on ".livequizmonitor-row-actions .dropdown-toggle" "css_element" in the "Sam Student" "table_row"
    And I click on "Unblock user" "link" in the "Sam Student" "table_row"
    And I click on "Unblock" "button" in the ".modal-dialog" "css_element"
    And I wait "6" seconds
    Then ".livequizmonitor-blocked-flag" "css_element" should not exist
    And ".livequizmonitor-row-actions [data-action='unblock-student'].disabled" "css_element" should exist

  @javascript @cohortsync
  Scenario: Newly enrolled student appears without reload
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student3 | Chris     | Cohort   | student3@test.com  |
    When I am on the live monitor report for "Quiz 1"
    Then I should see "Sam Student"
    And I should not see "Chris Cohort"
    When the following "course enrolments" exist:
      | user     | course | role    |
      | student3 | C1     | student |
    And I wait "6" seconds
    Then I should see "Chris Cohort"

  @javascript @cohortsync
  Scenario: Unenrolled student row disappears without reload
    Given I am on the live monitor report for "Quiz 1"
    Then I should see "Alex Other"
    When I unenrol user "student2" from course "C1"
    And I wait "6" seconds
    Then I should not see "Alex Other"

  @javascript @cohortsync
  Scenario: Status update still works without page reload
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"
    And I wait "6" seconds
    Then I should see "In progress"
    And I should see "Sam Student"

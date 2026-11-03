@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Idle students in live quiz monitor
  In order to monitor quiz participation live
  As a teacher
  I need to see when students become idle

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email             |
      | teacher1 | Terry     | Teacher  | teacher1@test.com |
      | student1 | Student   | ONE      | student1@test.com |
      | student2 | Student   | TWO      | student2@test.com |
      | student3 | Student   | THREE    | student3@test.com |
      | student4 | Student   | FOUR     | student4@test.com |
      | student5 | Student   | FIVE     | student5@test.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
      | student5 | C1     | student        |
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
    And I log in as "teacher1"

  @javascript
  Scenario: Idle and in-progress students are counted correctly on page load
    # Set student1 attempt to "In progress".
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"

    # Set student2 attempt to "Idle".
    And I am on the "Quiz 1" "quiz activity" page logged in as "student2"
    And I press "Attempt quiz"
    And the student "student2" has been idle for 6 minutes on quiz "Quiz 1"

    # Log in as teacher and view the Live Quiz Monitor.
    And I log in as "teacher1"
    When I am on the live monitor report for "Quiz 1"

    # Check summary tiles.
    Then I should see "1" in the "[data-summary='inprogress']" "css_element"
    And I should see "1" in the "[data-summary='idle']" "css_element"

    # Check filter buttons.
    And the live monitor "status" filter should show "1" for "In progress"
    And the live monitor "status" filter should show "1" for "Idle"

    # Check Status in student rows.
    And I should see "In progress" in the "Student ONE" "table_row"
    And I should see "Idle" in the "Student TWO" "table_row"

  @javascript
  Scenario: Student going idle live updates tiles and enables extend time, without a reload
    # Set student1 attempt to "In progress".
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"

    # Log in as teacher and view the Live Quiz Monitor.
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    # Check status is "In progress" in student row.
    And I should see "In progress" in the "Student ONE" "table_row"

    # Rewind the attempt time to make the student "idle".
    When the student "student1" has been idle for 6 minutes on quiz "Quiz 1"

    # ALlow the browser's poll() function to run and fetch new state from server.
    And I wait "6" seconds

    # Check the monitor has updated as expected.
    Then I should see "Idle" in the "Student ONE" "table_row"
    And I should see "1" in the "[data-summary='idle']" "css_element"
    And I should see "0" in the "[data-summary='inprogress']" "css_element"
    And the live monitor "status" filter should show "1" for "Idle"
    And the live monitor "status" filter should show "0" for "In progress"

    # Ensure the Action items are still active and not disabled.
    When I click on "Actions" "button" in the "Student ONE" "table_row"
    Then "Extend time" "link" should exist in the "Student ONE" "table_row"
    And "a.menu-action[data-action='extend-individual'].disabled" "css_element" should not exist in the "Student ONE" "table_row"

  @javascript
  Scenario: Student rows are sorted in-progress, idle, not-started, then completed
    Given I am on the "Quiz 1" "quiz activity" page logged in as "student1"
    And I press "Attempt quiz"

    # Student TWO goes idle FIRST, Student THREE goes idle SECOND — if sorting
    # only reflected insertion order rather than fullname, Student TWO would
    # wrongly end up before Student THREE.
    And I am on the "Quiz 1" "quiz activity" page logged in as "student2"
    And I press "Attempt quiz"
    And the student "student2" has been idle for 6 minutes on quiz "Quiz 1"
    And I am on the "Quiz 1" "quiz activity" page logged in as "student3"
    And I press "Attempt quiz"
    And the student "student3" has been idle for 6 minutes on quiz "Quiz 1"

    And user "student5" has started an attempt at quiz "Quiz 1"
    And user "student5" has finished an attempt at quiz "Quiz 1"

    # student4 never attempts, so stays not-started.

    When I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    # In-progress first.
    Then "Student ONE" "table_row" should appear before "Student THREE" "table_row"
    And "Student ONE" "table_row" should appear before "Student TWO" "table_row"

    # Idle bucket sorted by fullname: THREE before TWO, despite TWO going idle first.
    And "Student THREE" "table_row" should appear before "Student TWO" "table_row"

    # Idle before not-started, not-started before completed.
    And "Student TWO" "table_row" should appear before "Student FOUR" "table_row"
    And "Student FOUR" "table_row" should appear before "Student FIVE" "table_row"

@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Filter by no override in live quiz monitor
  In order to quickly find students who are on the standard quiz settings
  As a teacher
  I need to filter the live quiz monitor to students with no overrides

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
      | student2 | GA    |
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
    And group "Group A" has a time limit override on quiz "Quiz 1"

  @javascript
  Scenario: Teacher can filter students down to only those with no overrides
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then "None (1)" "button" should exist
    And I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

    When I click on "None (1)" "button"
    Then I should not see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should not see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

    # Toggling the same button off restores the full list.
    When I click on "None (1)" "button"
    Then I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should see "Student TWO" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Clear filters resets the no override toggle
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    When I click on "None (1)" "button"
    And I should not see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I click on "Reset all filters" "button"

    Then I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And the "aria-pressed" attribute of "None (1)" "button" should contain "false"

  @javascript
  Scenario: All is the default override filter and is selected again by Reset all filters
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then the "aria-pressed" attribute of "[data-flag='alloverride']" "css_element" should contain "true"
    And I should see "All (3)" in the "[data-filter-group='overrides']" "css_element"

    When I click on "User (1)" "button"
    Then the "aria-pressed" attribute of "[data-flag='alloverride']" "css_element" should contain "false"
    And I should not see "Student THREE" in the "[data-region='student-table']" "css_element"

    # Clicking All clears the other override toggles.
    When I click on "[data-flag='alloverride']" "css_element"
    Then the "aria-pressed" attribute of "[data-flag='alloverride']" "css_element" should contain "true"
    And the "aria-pressed" attribute of "User (1)" "button" should contain "false"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

    When I click on "None (1)" "button"
    And I click on "Reset all filters" "button"
    Then the "aria-pressed" attribute of "[data-flag='alloverride']" "css_element" should contain "true"
    And the "aria-pressed" attribute of "None (1)" "button" should contain "false"

  @javascript
  Scenario: The no-override toggle is mutually exclusive with the other override toggles
    Given I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    When I click on "User (1)" "button"
    And I click on "None (1)" "button"
    Then the "aria-pressed" attribute of "None (1)" "button" should contain "true"
    And the "aria-pressed" attribute of "User (1)" "button" should contain "false"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"
    And I should not see "Student ONE" in the "[data-region='student-table']" "css_element"

    When I click on "Group (1)" "button"
    Then the "aria-pressed" attribute of "Group (1)" "button" should contain "true"
    And the "aria-pressed" attribute of "None (1)" "button" should contain "false"
    And I should see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should not see "Student THREE" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Teacher without manageoverrides or viewoverrides capability does not see the no override filter
    Given the following "permission overrides" exist:
      | capability                | permission | role           | contextlevel | reference |
      | mod/quiz:manageoverrides  | Prevent    | editingteacher | Course       | C1        |
      | mod/quiz:viewoverrides    | Prevent    | editingteacher | Course       | C1        |
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then "[data-filter-group='overrides']" "css_element" should not exist

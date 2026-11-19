@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Other plugins add filters to the Live Quiz Monitor
  In order to find the students another plugin flags
  As a teacher
  I need filters from other plugins in the filter toolbar of the live monitor

  # The fixture callbacks (\quiz_livequizmonitor\test\behat_hook_callbacks) number the students by
  # name: Name A = 1, Name B = 2, Name C = 3.
  # "Fixture filters": First student = A, Odd position = A and C, No match = nobody.
  # "Second fixture": Even position = B.
  # After "the live quiz monitor extension fixture data changes": Odd position = everybody,
  # No match is no longer declared, and the fixture column shows "Changed N" instead of "Value N".

  Background:
    Given the following "users" exist:
      | username  | firstname | lastname | email              |
      | teacher1  | Terry     | Teacher  | teacher1@test.com  |
      | student-a | Name      | A        | email-2a@test.com  |
      | student-b | Name      | B        | email-3b@test.com  |
      | student-c | Name      | C        | email-1c@test.com  |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user      | course | role           |
      | teacher1  | C1     | editingteacher |
      | student-a | C1     | student        |
      | student-b | C1     | student        |
      | student-c | C1     | student        |
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
    And the live quiz monitor extension fixture is enabled
    And I log in as "teacher1"

  @javascript
  Scenario: Plugin filter groups and counts are shown on load and after a refresh
    When I am on the live monitor report for "Quiz 1"
    Then "[data-extension-group='quiz_livequizmonitor']" "css_element" should exist
    And I should see "Fixture filters" in the "[data-extension-group='quiz_livequizmonitor']" "css_element"
    And I should see "First student (1)" in the "[data-extension-group='quiz_livequizmonitor']" "css_element"
    And I should see "Odd position (2)" in the "[data-extension-group='quiz_livequizmonitor']" "css_element"
    And I should see "No match (0)" in the "[data-extension-group='quiz_livequizmonitor']" "css_element"
    And I should see "All (3)" in the "[data-extension-group='quiz_livequizmonitor']" "css_element"
    And I should see "Second fixture" in the "[data-extension-group='local_fixturetwo']" "css_element"
    And I should see "Even position (1)" in the "[data-extension-group='local_fixturetwo']" "css_element"
    And I wait "6" seconds
    And I should see "Odd position (2)" in the "[data-extension-group='quiz_livequizmonitor']" "css_element"
    And I should see "Even position (1)" in the "[data-extension-group='local_fixturetwo']" "css_element"

  @javascript
  Scenario: Selecting a plugin filter shows only the matching rows
    Given I am on the live monitor report for "Quiz 1"
    When I click on "Odd position (2)" "button"
    Then "[data-filter-id='quiz_livequizmonitor-odd'][aria-pressed='true']" "css_element" should exist
    And I should see "Name A" in the "[data-region='student-table']" "css_element"
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"
    And I should see "Name C" in the "[data-region='student-table']" "css_element"
    And I wait "6" seconds
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"
    And I should see "Name C" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Clicking the filter again or the group's All entry clears it
    Given I am on the live monitor report for "Quiz 1"
    When I click on "Odd position (2)" "button"
    And I click on "Odd position (2)" "button"
    Then I should see "Name B" in the "[data-region='student-table']" "css_element"
    When I click on "Odd position (2)" "button"
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"
    And I click on "[data-action='filter-extension-all'][data-filter-component='quiz_livequizmonitor']" "css_element"
    Then I should see "Name B" in the "[data-region='student-table']" "css_element"
    And "[data-filter-id='quiz_livequizmonitor-odd'][aria-pressed='false']" "css_element" should exist

  @javascript
  Scenario: A plugin filter without matches shows the no students message
    Given I am on the live monitor report for "Quiz 1"
    When I click on "No match (0)" "button"
    Then I should see "No students match the current filters."
    And I should not see "Name A" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Counts and rows follow changed plugin data after a refresh
    Given I am on the live monitor report for "Quiz 1"
    And I click on "Odd position (2)" "button"
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"
    When the live quiz monitor extension fixture data changes
    And I wait "6" seconds
    Then I should see "Odd position (3)" in the "[data-extension-group='quiz_livequizmonitor']" "css_element"
    And I should see "Name B" in the "[data-region='student-table']" "css_element"
    And "[data-filter-id='quiz_livequizmonitor-odd'][aria-pressed='true']" "css_element" should exist

  @javascript
  Scenario: A selected filter that is no longer declared stops restricting the rows
    Given I am on the live monitor report for "Quiz 1"
    And I click on "No match (0)" "button"
    And I should see "No students match the current filters."
    When the live quiz monitor extension fixture data changes
    And I wait "6" seconds
    Then I should not see "No students match the current filters."
    And I should see "Name A" in the "[data-region='student-table']" "css_element"
    And "[data-filter-id='quiz_livequizmonitor-none'][aria-pressed='false']" "css_element" should exist

  @javascript
  Scenario: Selecting another filter of the same group replaces the first one
    Given I am on the live monitor report for "Quiz 1"
    When I click on "First student (1)" "button"
    And I should not see "Name C" in the "[data-region='student-table']" "css_element"
    And I click on "Odd position (2)" "button"
    Then "[data-filter-id='quiz_livequizmonitor-first'][aria-pressed='false']" "css_element" should exist
    And "[data-filter-id='quiz_livequizmonitor-odd'][aria-pressed='true']" "css_element" should exist
    And I should see "Name A" in the "[data-region='student-table']" "css_element"
    And I should see "Name C" in the "[data-region='student-table']" "css_element"
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Filters of two groups combine with AND
    Given I am on the live monitor report for "Quiz 1"
    When I click on "Odd position (2)" "button"
    And I click on "Even position (1)" "button"
    Then I should see "No students match the current filters."

  @javascript
  Scenario: A plugin filter combines with a status filter
    Given user "student-a" has started an attempt at quiz "Quiz 1"
    And user "student-b" has started an attempt at quiz "Quiz 1"
    And I am on the live monitor report for "Quiz 1"
    When I click on "In progress (2)" "button"
    And I click on "Odd position (2)" "button"
    Then I should see "Name A" in the "[data-region='student-table']" "css_element"
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"
    And I should not see "Name C" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: A plugin filter combines with an override filter
    Given user "student-a" has a time limit override on quiz "Quiz 1"
    And I am on the live monitor report for "Quiz 1"
    When I click on "User (1)" "button"
    And I click on "Odd position (2)" "button"
    Then I should see "Name A" in the "[data-region='student-table']" "css_element"
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"
    And I should not see "Name C" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: A plugin filter combines with the search
    Given I am on the live monitor report for "Quiz 1"
    When I click on "Odd position (2)" "button"
    And I set the field "Search students…" to "Name C"
    Then I should see "Name C" in the "[data-region='student-table']" "css_element"
    And I should not see "Name A" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: Reset all filters clears the plugin filters, a group's All only its own group
    Given I am on the live monitor report for "Quiz 1"
    When I click on "Odd position (2)" "button"
    And I click on "Even position (1)" "button"
    And I click on "[data-action='filter-extension-all'][data-filter-component='local_fixturetwo']" "css_element"
    Then "[data-filter-id='quiz_livequizmonitor-odd'][aria-pressed='true']" "css_element" should exist
    And "[data-filter-id='local_fixturetwo-even'][aria-pressed='false']" "css_element" should exist
    And I should see "Name C" in the "[data-region='student-table']" "css_element"
    When I click on "Reset all filters" "button"
    Then "[data-filter-id='quiz_livequizmonitor-odd'][aria-pressed='false']" "css_element" should exist
    And I should see "Name B" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: The search finds values of plugin columns, also when the column is hidden
    Given I am on the live monitor report for "Quiz 1"
    When I set the field "Search students…" to "value 2"
    Then I should see "Name B" in the "[data-region='student-table']" "css_element"
    And I should not see "Name A" in the "[data-region='student-table']" "css_element"
    When I click on ".livequizmonitor-th[data-column='quiz_livequizmonitor-fixture'] [data-action='toggle-column']" "css_element"
    And I set the field "Search students…" to "value 3"
    Then I should see "Name C" in the "[data-region='student-table']" "css_element"
    And I should not see "Name B" in the "[data-region='student-table']" "css_element"

  @javascript
  Scenario: The search follows changed values of plugin columns after a refresh
    Given I am on the live monitor report for "Quiz 1"
    And I set the field "Search students…" to "value 2"
    And I should see "Name B" in the "[data-region='student-table']" "css_element"
    When the live quiz monitor extension fixture data changes
    And I wait "6" seconds
    Then I should see "No students match the current filters."
    When I set the field "Search students…" to "changed 2"
    Then I should see "Name B" in the "[data-region='student-table']" "css_element"
    And I should not see "Name A" in the "[data-region='student-table']" "css_element"

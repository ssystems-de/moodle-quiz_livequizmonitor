@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Other plugins extend the Live Quiz Monitor
  In order to see information from other plugins at a glance
  As a teacher
  I need columns, row highlights and row actions added by other plugins in the live monitor

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
  Scenario: Extension column is shown on load and after a refresh
    When I am on the live monitor report for "Quiz 1"
    Then "th[data-column='quiz_livequizmonitor-fixture']" "css_element" should exist
    And I should see "Fixture value"
    And I should see "Value 1" in the "Name A" "table_row"
    And I should see "Value 3" in the "Name C" "table_row"
    And I wait "6" seconds
    And I should see "Value 1" in the "Name A" "table_row"
    And I should see "Value 2" in the "Name B" "table_row"

  @javascript
  Scenario: Extension column can be sorted
    Given I am on the live monitor report for "Quiz 1"
    When I click on "th[data-sort-column='quiz_livequizmonitor-fixture'] [data-action='sort-column']" "css_element"
    Then the following students should appear in order:
      | Name A |
      | Name B |
      | Name C |
    When I click on "th[data-sort-column='quiz_livequizmonitor-fixture'] [data-action='sort-column']" "css_element"
    Then the following students should appear in order:
      | Name C |
      | Name B |
      | Name A |

  @javascript
  Scenario: Extension column can be hidden and stays hidden after a reload
    Given I am on the live monitor report for "Quiz 1"
    And I should see "Value 1"
    When I click on ".livequizmonitor-th[data-column='quiz_livequizmonitor-fixture'] [data-action='toggle-column']" "css_element"
    Then I should not see "Value 1"
    And I reload the page
    And I should not see "Value 1"
    And ".livequizmonitor-th-collapsed[data-column='quiz_livequizmonitor-fixture']" "css_element" should exist

  @javascript
  Scenario: Rows are highlighted on load and after a refresh
    When I am on the live monitor report for "Quiz 1"
    Then "tr[data-highlight='warning'][data-userid]" "css_element" should exist
    And I should see "Name A" in the "tr[data-highlight='warning']" "css_element"
    And I should not see "Name B" in the "tr[data-highlight='warning']" "css_element"
    And I wait "6" seconds
    And I should see "Name A" in the "tr[data-highlight='warning']" "css_element"

  @javascript
  Scenario: Row action from an extension is shown in the actions menu
    Given I am on the live monitor report for "Quiz 1"
    When I click on "[data-region='row-actions'] button" "css_element" in the "Name A" "table_row"
    Then "Fixture action" "link" should exist in the "Name A" "table_row"
    And "a[target='_blank'][href*='/user/profile.php']" "css_element" should exist in the "Name A" "table_row"
    And "Fixture action" "link" should not exist in the "Name B" "table_row"
    And I wait "6" seconds
    And "Fixture action" "link" should exist in the "Name A" "table_row"

  @javascript
  Scenario: Plugin scripts are notified about refreshes
    When I am on the live monitor report for "Quiz 1"
    And I wait "6" seconds
    Then "body[data-lqm-fixture-refreshed='1']" "css_element" should exist

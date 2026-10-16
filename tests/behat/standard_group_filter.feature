@quiz_livequizmonitor @mod @mod_quiz @quiz @report
Feature: Filter by standard group in live quiz monitor
  In order to quickly find students in a particular group
  As a teacher
  I need to filter the live quiz monitor using the standard group menu

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
      | Group B | C1     | GB       |
    And the following "group members" exist:
      | user     | group |
      | student1 | GA    |
      | student3 | GA    |
      | student2 | GB    |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype       | name | questiontext |
      | Test questions   | shortanswer | SA1  | What is 2+2? |

  @javascript
  Scenario: No group menu is shown when the quiz has no group mode
    Given the following "activities" exist:
      | activity | name   | course | timelimit | groupmode |
      | quiz     | Quiz 1 | C1     | 0         | 0         |
    And quiz "Quiz 1" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 1"

    Then "[data-region='filter-toolbar'] [data-region='group-menu']" "css_element" should not exist
    And I should not see "Separate groups"
    And I should not see "Visible groups"

  @javascript
  Scenario: Teacher can filter students down to one group using the standard group menu
    Given the following "activities" exist:
      | activity | name   | course | timelimit | groupmode |
      | quiz     | Quiz 2 | C1     | 0         | 1         |
    And quiz "Quiz 2" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 2"

    Then "[data-region='filter-toolbar'] [data-region='group-menu']" "css_element" should exist
    And I should see "Separate groups"
    And I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

    When I select "Group A" from the "Separate groups" singleselect
    Then I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should not see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

    # The page reloads with the group carried in the URL, so it survives a refresh.
    And the url should match "group="

  @javascript
  Scenario: Selecting "All participants" restores the full student list
    Given the following "activities" exist:
      | activity | name   | course | timelimit | groupmode |
      | quiz     | Quiz 2 | C1     | 0         | 1         |
    And quiz "Quiz 2" contains the following questions:
      | question | page |
      | SA1      | 1    |
    And I log in as "teacher1"
    And I am on the live monitor report for "Quiz 2"

    When I select "Group A" from the "Separate groups" singleselect
    Then I should not see "Student TWO" in the "[data-region='student-table']" "css_element"

    When I select "All participants" from the "Separate groups" singleselect
    Then I should see "Student ONE" in the "[data-region='student-table']" "css_element"
    And I should see "Student TWO" in the "[data-region='student-table']" "css_element"
    And I should see "Student THREE" in the "[data-region='student-table']" "css_element"

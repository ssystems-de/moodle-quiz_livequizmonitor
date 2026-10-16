@mod @mod_quiz @quiz @quiz_livequizmonitor
Feature: Respect access restrictions in Live Quiz Monitor
  In order to monitor only eligible participants during live exams
  As a teacher
  I need the live monitor to respect group-level activity access restrictions

  Background:
    Given the following "courses" exist:
      | fullname | shortname | category |
      | Course-1 | C1        | 0        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | student3 | Student   | Three    | student3@example.com |
      | student4 | Student   | Four     | student4@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | student3 | C1     | student        |
      | student4 | C1     | student        |
    And the following "groups" exist:
      | name     | course | idnumber |
      | Group-12 | C1     | G12      |
      | Group-34 | C1     | G34      |
    And the following "group members" exist:
      | user     | group |
      | teacher1 | G12   |
      | student1 | G12   |
      | student2 | G12   |
      | teacher1 | G34   |
      | student3 | G34   |
      | student4 | G34   |
    And the following "question categories" exist:
      | contextlevel | reference | name        |
      | Course       | C1        | Category-1  |
    And the following "questions" exist:
      | questioncategory | qtype     | name       | questiontext  |
      | Category-1       | truefalse | Question-1 | Is this true? |
    And the following "activities" exist:
      | activity | name   | course | idnumber |
      | quiz     | Quiz-1 | C1     | Q1    |
    And quiz "Quiz-1" contains the following questions:
      | question   | page |
      | Question-1 | 1    |

  @javascript
  Scenario: Confirm monitor for unrestricted activity shows all students
    When I log in as "teacher1"
    And I am on the live monitor report for "Quiz-1"
    Then I should see "Student One"
    And I should see "Student Two"
    And I should see "Student Three"
    And I should see "Student Four"

  @javascript
  Scenario: Confirm group access restrictions filter students
    # Use a custom step to insert directly into the DB and so avoid problems
    # with plugins adding further required fields to the quiz settings form.
    Given the quiz "Quiz-1" is restricted to group "G12"
    And I log in as "teacher1"

    # Confirm Live Monitor immediately filters out non-group members
    When I am on the live monitor report for "Quiz-1"
    Then I should see "Student One"
    And I should see "Student Two"
    And I should not see "Student Three"
    And I should not see "Student Four"

  @javascript
  Scenario: Group membership changes update the monitor automatically without reload
    Given the quiz "Quiz-1" is restricted to group "G12"
    And I log in as "teacher1"

    When I am on the live monitor report for "Quiz-1"
    Then I should see "Student One"
    And I should see "Student Two"
    And I should not see "Student Three"
    And I should not see "Student Four"

    # Add Student Three to the restricted group and confirm they appear in the monitor.
    When user "student3" is added to group "Group-12"
    And I wait "6" seconds
    Then I should see "Student Three"

    # Remove Student One from the restricted group and confirm they disappear from the monitor.
    When user "student1" is removed from group "Group-12"
    And I wait "6" seconds
    Then I should not see "Student One"

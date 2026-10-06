<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Behat step definitions for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @category  test
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL used, this file may be required by behat before including /config.php.
require_once(__DIR__ . '/../../../../../../lib/behat/behat_base.php');

use Moodle\BehatExtension\Exception\SkippedException;
use Behat\Gherkin\Node\TableNode;

/**
 * Live quiz monitor Behat steps.
 *
 * @package   quiz_livequizmonitor
 * @category  test
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_quiz_livequizmonitor extends behat_base {
    /**
     * Open the live monitor report for a quiz by activity name.
     *
     * @When /^I am on the live monitor report for "(?P<quizname>[^"]*)"$/
     * @param string $quizname Quiz activity name.
     */
    public function i_am_on_the_live_monitor_report_for(string $quizname): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $url = new \moodle_url('/mod/quiz/report.php', [
            'id' => $cm->id,
            'mode' => 'livequizmonitor',
        ]);
        $this->getSession()->visit($this->locate_path($url->out_as_local_url(false)));
    }

    /**
     * Require quizaccess_onesession or skip scenario.
     */
    private function require_onesession_plugin(): void {
        if (!\quiz_livequizmonitor\local\manager\onesession_manager::is_plugin_installed()) {
            throw new SkippedException('quizaccess_onesession is not installed');
        }
    }

    /**
     * Enable onesession concurrent session rule for a quiz.
     *
     * @Given /^onesession concurrent session rule is enabled for quiz "(?P<quizname>[^"]*)"$/
     * @param string $quizname Quiz activity name.
     */
    public function onesession_concurrent_session_rule_is_enabled_for_quiz(string $quizname): void {
        global $DB;

        $this->require_onesession_plugin();

        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $record = $DB->get_record('quizaccess_onesession', ['quizid' => $quiz->id]);
        if ($record) {
            $record->enabled = 1;
            $DB->update_record('quizaccess_onesession', $record);
        } else {
            $DB->insert_record('quizaccess_onesession', (object) [
                'quizid' => $quiz->id,
                'enabled' => 1,
            ]);
        }
    }

    /**
     * Seed attempt_blocked event for the student's current in-progress attempt.
     *
     * @Given /^the student "(?P<username>[^"]*)" is blocked by onesession on quiz "(?P<quizname>[^"]*)"$/
     * @param string $username Student username.
     * @param string $quizname Quiz activity name.
     */
    public function the_student_is_blocked_by_onesession_on_quiz(string $username, string $quizname): void {
        global $DB;

        $this->require_onesession_plugin();

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $attempt = $DB->get_record('quiz_attempts', [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'state' => \mod_quiz\quiz_attempt::IN_PROGRESS,
        ], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);

        // Calling quizaccess_onesession\event\attempt_blocked->trigger() here
        // doesn't add a database row so we fake it.
        $data = (object) [
            'eventname' => '\quizaccess_onesession\event\attempt_blocked',
            'component' => 'quizaccess_onesession',
            'action' => 'blocked',
            'target' => 'attempt',
            'objecttable' => 'quiz_attempts',
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'crud' => 'r',
            'edulevel' => 2,
            'contextid' => $context->id,
            'contextlevel' => 70,
            'contextinstanceid' => $context->instanceid,
            'userid' => $attempt->userid,
            'courseid' => $cm->course,
            'timecreated' => time(),
            'other' => json_encode(['quizid' => $quiz->id]),
        ];
        $DB->insert_record('logstore_standard_log', $data);
    }

    /**
     * Unenrol a user from a course by shortname (manual enrol plugin).
     *
     * @When /^I unenrol user "(?P<username>[^"]*)" from course "(?P<shortname>[^"]*)"$/
     * @param string $username Student username.
     * @param string $shortname Course shortname.
     */
    public function unenrol_user_from_course(string $username, string $shortname): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['shortname' => $shortname], '*', MUST_EXIST);
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $plugin->unenrol_user($instance, $user->id);
    }

    /**
     * Answer a question in a student's quiz attempt.
     *
     * @Given /^student "(?P<username>[^"]*)" has answered question (?P<questionnumber>\d+) in quiz "(?P<quizname>[^"]*)"$/
     * @param string $username Student username.
     * @param int $questionnumber Question number.
     * @param string $quizname Quiz name.
     */
    public function student_has_answered_question(
        string $username,
        int $questionnumber,
        string $quizname
    ): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        $attempt = $this->create_quiz_attempt($quiz, $cm, $user);
        $quba = $attempt->get_question_usage();
        $responses = [$questionnumber => 'answer'];

        $generator = testing_util::get_data_generator()->get_plugin_generator('core_question');
        $postdata = $generator->get_simulated_post_data_for_questions_in_usage(
            $quba,
            $responses,
            false // Simulate "Save" without clicking "Check".
        );
        // Process answers, with "false" to indicate "not overdue".
        $attempt->process_submitted_actions(time(), false, $postdata);
    }

    /**
     * Complete a quiz for a student.
     *
     * @Given /^student "(?P<username>[^"]*)" has completed quiz "(?P<quizname>[^"]*)"$/
     * @param string $username Student username.
     * @param string $quizname Quiz name.
     */
    public function student_has_completed_quiz(string $username, string $quizname): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        $attempt = $this->create_quiz_attempt($quiz, $cm, $user);
        $quba = $attempt->get_question_usage();

        $responses = [];
        foreach ($quba->get_slots() as $slot) {
            $responses[$slot] = 'answer';
        }

        $generator = testing_util::get_data_generator()->get_plugin_generator('core_question');
        $postdata = $generator->get_simulated_post_data_for_questions_in_usage(
            $quba,
            $responses,
            false // Simulate "Save" without clicking "Check".
        );

        // Process answers, with "false" to indicate "not overdue".
        $attempt->process_submitted_actions(time(), false, $postdata);
        $attempt->process_finish(time(), true);
    }

    /**
     * Create a quiz attempt for a student.
     *
     * @param stdClass $quiz
     * @param stdClass $cm
     * @param stdClass $user
     * @return \mod_quiz\quiz_attempt
     */
    private function create_quiz_attempt(stdClass $quiz, stdClass $cm, stdClass $user): \mod_quiz\quiz_attempt {
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id, $user->id);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $user->id);

        $quba = question_engine::make_questions_usage_by_activity(
            'mod_quiz',
            \context_module::instance($cm->id)
        );
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $attempt = quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        $attempt = quiz_attempt_save_started($quizobj, $quba, $attempt);

        return \mod_quiz\quiz_attempt::create($attempt->id);
    }

    /**
     * Check that students appear in the expected order.
     *
     * @Then /^the following students should appear in order:$/
     * @param TableNode $students Expected student names in order.
     */
    public function the_following_students_should_appear_in_order(TableNode $students): void {
        $expected = [];

        foreach ($students->getRows() as $row) {
            $expected[] = $row[0];
        }

        $rows = $this->getSession()->getPage()->findAll(
            'css',
            '.livequizmonitor-table tbody tr'
        );

        \PHPUnit\Framework\Assert::assertCount(
            count($expected),
            $rows,
            'The number of student rows does not match the expected number.'
        );

        $actual = [];

        foreach ($rows as $row) {
            $cells = $row->findAll('css', 'td');

            \PHPUnit\Framework\Assert::assertGreaterThanOrEqual(
                2,
                count($cells),
                'A student row does not contain a student name cell.'
            );

            $actual[] = trim($cells[1]->getText());
        }

        \PHPUnit\Framework\Assert::assertSame($expected, $actual);
    }

    /**
     * Make the student's current quiz attempt idle by backdating the latest
     * question attempt step.
     *
     * @Given /^the student "(?P<username>[^"]*)" has been idle for (?P<minutes>\d+) minutes on quiz "(?P<quizname>[^"]*)"$/
     * @param string $username Student username.
     * @param int $minutes Number of minutes to backdate the activity.
     * @param string $quizname Quiz activity name.
     */
    public function the_student_has_been_idle_for_minutes_on_quiz(
        string $username,
        int $minutes,
        string $quizname
    ): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], 'id', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], 'id', MUST_EXIST);

        $step = $DB->get_record_sql(
            "SELECT qas.*
               FROM {question_attempt_steps} qas
               JOIN {question_attempts} qa ON qa.id = qas.questionattemptid
               JOIN {question_usages} qu ON qu.id = qa.questionusageid
               JOIN {quiz_attempts} qza ON qza.uniqueid = qu.id
              WHERE qza.quiz = :quizid
                AND qza.userid = :userid
                AND qza.state = :state
           ORDER BY qas.timecreated DESC",
            [
                'quizid' => $quiz->id,
                'userid' => $user->id,
                'state' => \mod_quiz\quiz_attempt::IN_PROGRESS,
            ],
            MUST_EXIST
        );

        $step->timecreated = time() - ($minutes * 60);
        $DB->update_record('question_attempt_steps', $step);
    }

    /**
     * Create a user override with an extended time limit for a student.
     *
     * @Given /^user "(?P<username>[^"]*)" has a time limit override on quiz "(?P<quizname>[^"]*)"$/
     * @param string $username Student username.
     * @param string $quizname Quiz activity name.
     */
    public function user_has_a_time_limit_override_on_quiz(string $username, string $quizname): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], 'id', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], 'id', MUST_EXIST);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'timelimit' => 3600,
        ]);
    }

    /**
     * Create a user override that only changes the allowed attempts (not time-related).
     *
     * @Given /^user "(?P<username>[^"]*)" has an attempts override on quiz "(?P<quizname>[^"]*)"$/
     * @param string $username Student username.
     * @param string $quizname Quiz activity name.
     */
    public function user_has_an_attempts_override_on_quiz(string $username, string $quizname): void {
        global $DB;

        $user = $DB->get_record('user', ['username' => $username], 'id', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], 'id', MUST_EXIST);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'attempts' => 5,
        ]);
    }

    /**
     * Create a group override with an extended time limit.
     *
     * @Given /^group "(?P<groupname>[^"]*)" has a time limit override on quiz "(?P<quizname>[^"]*)"$/
     * @param string $groupname Group name (idnumber).
     * @param string $quizname Quiz activity name.
     */
    public function group_has_a_time_limit_override_on_quiz(string $groupname, string $quizname): void {
        global $DB;

        $group = $DB->get_record('groups', ['name' => $groupname], 'id', MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['name' => $quizname], 'id', MUST_EXIST);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'groupid' => $group->id,
            'timelimit' => 3600,
        ]);
    }
}

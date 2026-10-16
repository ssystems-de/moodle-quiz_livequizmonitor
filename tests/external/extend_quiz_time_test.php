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
 * External API tests for extend_quiz_time.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../traits/group_scope_test_trait.php');
require_once(__DIR__ . '/../traits/quiz_attempt_activity_trait.php');

use advanced_testcase;
use moodle_exception;
use quiz_livequizmonitor\local\manager\extend_time_manager;
use quiz_livequizmonitor\tests\traits\group_scope_test_trait;
use quiz_livequizmonitor\tests\traits\quiz_attempt_activity_trait;
use required_capability_exception;

/**
 * Tests for the extend_quiz_time external function.
 *
 * @covers \quiz_livequizmonitor\external\extend_quiz_time
 * @runTestsInSeparateProcesses
 */
final class extend_quiz_time_test extends advanced_testcase {
    use group_scope_test_trait;
    use quiz_attempt_activity_trait;

    /**
     * Create a timed quiz with one question.
     *
     * @param \stdClass $course Course record.
     * @return array{0: \stdClass, 1: \stdClass, 2: \mod_quiz_generator}
     */
    private function create_timed_quiz(\stdClass $course): array {
        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $quiz = $quizgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 1,
            'timelimit' => 600,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $cat = $questiongenerator->create_question_category(['contextid' => \context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return [$quiz, $cm, $quizgenerator];
    }

    /**
     * Individual extend succeeds for in-progress attempt.
     */
    public function test_execute_individual_success(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($student);
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $result = extend_quiz_time::execute($cm->id, 0, 15, extend_time_manager::SCOPE_INDIVIDUAL, $student->id);

        $this->assertSame(1, $result['extendedcount']);
        $this->assertSame(15, $result['minutes']);
        $this->assertSame(extend_time_manager::SCOPE_INDIVIDUAL, $result['scope']);
    }

    /**
     * Bulk extend returns extended count for all in-progress students.
     */
    public function test_execute_bulk_success(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($student);
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $result = extend_quiz_time::execute($cm->id, 0, 10, extend_time_manager::SCOPE_BULK, 0);

        $this->assertSame(1, $result['extendedcount']);
        $this->assertSame(10, $result['minutes']);
    }

    /**
     * A custom minutes value that is not one of the quick-fill presets is still valid,
     * as long as it falls within extend_time_manager::MAX_CUSTOM_MINUTES.
     */
    public function test_execute_accepts_custom_minutes_outside_presets(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');

        [$quiz, $cm] = $this->create_timed_quiz($course);

        $this->setUser($teacher);

        $result = extend_quiz_time::execute($cm->id, 0, 7, extend_time_manager::SCOPE_BULK, 0);

        $this->assertSame(7, $result['minutes']);
    }

    /**
     * Minutes outside [1, MAX_CUSTOM_MINUTES] are rejected. This is validated once,
     * in extend_time_manager::extend_quiz_time(), so it surfaces here as a
     * moodle_exception rather than an invalid_parameter_exception.
     */
    public function test_execute_rejects_out_of_range_minutes(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        [$quiz, $cm] = $this->create_timed_quiz($course);

        $this->setUser($teacher);

        $this->expectException(moodle_exception::class);
        extend_quiz_time::execute(
            $cm->id,
            0,
            extend_time_manager::MAX_CUSTOM_MINUTES + 1,
            extend_time_manager::SCOPE_BULK,
            0
        );
    }

    /**
     * Student without manageoverrides cannot extend time.
     */
    public function test_execute_requires_capability(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm] = $this->create_timed_quiz($course);

        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        extend_quiz_time::execute($cm->id, 0, 15, extend_time_manager::SCOPE_BULK, 0);
    }

    /**
     * Teacher in Group A cannot extend time for a student in Group B.
     */
    public function test_execute_rejects_out_of_scope_user_individual(): void {
        global $DB;

        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $quiz = $fixture['quiz'];
        $cm = $fixture['cm'];
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $DB->set_field('quiz', 'timelimit', 600, ['id' => $quiz->id]);

        $this->setUser($fixture['studentb']);
        $quizgenerator->create_attempt($quiz->id, $fixture['studentb']->id);

        $this->setUser($fixture['teacher']);
        $this->set_activity_group($cm, (int) $fixture['groupa']->id);

        $this->expectException(moodle_exception::class);
        try {
            extend_quiz_time::execute(
                $cm->id,
                (int) $fixture['groupa']->id,
                15,
                extend_time_manager::SCOPE_INDIVIDUAL,
                $fixture['studentb']->id
            );
        } catch (moodle_exception $e) {
            $this->assertSame('error:usernotvisible', $e->errorcode);
            throw $e;
        }

        $this->assertFalse($DB->record_exists('quiz_overrides', [
            'quiz' => $quiz->id,
            'userid' => $fixture['studentb']->id,
        ]));
    }

    /**
     * Bulk-eligible user ids include idle students, not just in-progress ones.
     */
    public function test_get_extendable_userids_includes_idle_students(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $activeuser = $generator->create_and_enrol($course, 'student');
        $idleuser = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($activeuser);
        $quizgenerator->create_attempt($quiz->id, $activeuser->id);

        $this->setUser($idleuser);
        $idleattempt = $quizgenerator->create_attempt($quiz->id, $idleuser->id);
        $this->backdate_last_activity($idleattempt->id, 6);

        $this->setUser($teacher);
        $userids = extend_time_manager::get_extendable_userids($course, $cm, $quiz, 0);

        $this->assertContains((int) $activeuser->id, $userids);
        $this->assertContains((int) $idleuser->id, $userids);
    }

    /**
     * Bulk extend actually extends idle students, not just in-progress ones.
     */
    public function test_extend_quiz_time_bulk_extends_idle_student(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $idleuser = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($idleuser);
        $idleattempt = $quizgenerator->create_attempt($quiz->id, $idleuser->id);
        $this->backdate_last_activity($idleattempt->id, 6);

        $this->setUser($teacher);
        $outcome = extend_time_manager::extend_quiz_time($course, $cm, $quiz, 0, 10, extend_time_manager::SCOPE_BULK);

        $this->assertSame(1, $outcome->extendedcount);
        $this->assertContains(\fullname($idleuser), $outcome->usernames);
    }
}

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
 * Unit tests for extend_time_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use advanced_testcase;
use mod_quiz\event\user_override_created;
use mod_quiz\event\user_override_updated;
use mod_quiz\quiz_attempt;

/**
 * Tests for extend_time_manager.
 *
 * @covers \quiz_livequizmonitor\local\manager\extend_time_manager
 */
final class extend_time_manager_test extends advanced_testcase {
    /**
     * Create a timed quiz with one question.
     *
     * @param \stdClass $course Course record.
     * @param array $settings Extra quiz settings (e.g. timelimit, timeclose).
     * @return array{0: \stdClass, 1: \stdClass, 2: \mod_quiz_generator}
     */
    private function create_timed_quiz(\stdClass $course, array $settings = []): array {
        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $quiz = $quizgenerator->create_instance(array_merge([
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 1,
            'timelimit' => 600,
        ], $settings));
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $cat = $questiongenerator->create_question_category(['contextid' => \context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        return [$quiz, $cm, $quizgenerator];
    }

    /**
     * Individual extend creates a user override with incremented timelimit.
     */
    public function test_extend_user_creates_timelimit_override(): void {
        global $DB;

        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($student);
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $outcome = extend_time_manager::extend_quiz_time(
            $course,
            $cm,
            $quiz,
            0,
            15,
            extend_time_manager::SCOPE_INDIVIDUAL,
            $student->id
        );

        $this->assertSame(1, $outcome->extendedcount);
        $override = $DB->get_record('quiz_overrides', ['quiz' => $quiz->id, 'userid' => $student->id]);
        $this->assertNotFalse($override);
        $this->assertGreaterThan(600, (int) $override->timelimit);
    }

    /**
     * Bulk extend only targets in-progress students from the obliged cohort.
     */
    public function test_bulk_extend_targets_inprogress_only(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $inprogress = $generator->create_and_enrol($course, 'student');
        $notstarted = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($inprogress);
        $quizgenerator->create_attempt($quiz->id, $inprogress->id);

        $this->setUser($teacher);
        $userids = extend_time_manager::get_extendable_userids($course, $cm, $quiz, 0);
        $this->assertSame([(int) $inprogress->id], $userids);

        $outcome = extend_time_manager::extend_quiz_time(
            $course,
            $cm,
            $quiz,
            0,
            10,
            extend_time_manager::SCOPE_BULK
        );

        $this->assertSame(1, $outcome->extendedcount);
        $this->assertContains(fullname($inprogress), $outcome->usernames);
    }

    /**
     * A custom minutes value outside the presets is accepted, as long as it is
     * within [1, MAX_CUSTOM_MINUTES]. A preset is just a convenient value within
     * this same range, not a separate category.
     */
    public function test_extend_accepts_custom_minutes_within_range(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course);

        $this->setUser($student);
        $quizgenerator->create_attempt($quiz->id, $student->id);

        $this->setUser($teacher);
        $outcome = extend_time_manager::extend_quiz_time(
            $course,
            $cm,
            $quiz,
            0,
            extend_time_manager::MAX_CUSTOM_MINUTES,
            extend_time_manager::SCOPE_INDIVIDUAL,
            $student->id
        );

        $this->assertSame(1, $outcome->extendedcount);
        $this->assertSame(extend_time_manager::MAX_CUSTOM_MINUTES, $outcome->minutes);
    }

    /**
     * Zero minutes is rejected.
     */
    public function test_extend_rejects_zero_minutes(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        [$quiz, $cm] = $this->create_timed_quiz($course);

        $this->setUser($teacher);

        $this->expectException(\moodle_exception::class);
        extend_time_manager::extend_quiz_time($course, $cm, $quiz, 0, 0, extend_time_manager::SCOPE_BULK);
    }

    /**
     * A value above MAX_CUSTOM_MINUTES is rejected.
     */
    public function test_extend_rejects_minutes_over_max(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        [$quiz, $cm] = $this->create_timed_quiz($course);

        $this->setUser($teacher);

        $this->expectException(\moodle_exception::class);
        extend_time_manager::extend_quiz_time(
            $course,
            $cm,
            $quiz,
            0,
            extend_time_manager::MAX_CUSTOM_MINUTES + 1,
            extend_time_manager::SCOPE_BULK
        );
    }

    /**
     * user_can_extend requires manageoverrides capability.
     */
    public function test_user_can_extend_requires_manageoverrides(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm] = $this->create_timed_quiz($course);
        $context = \context_module::instance($cm->id);

        $this->setUser($teacher);
        $this->assertTrue(extend_time_manager::user_can_extend($context));

        $this->setUser($student);
        $this->assertFalse(extend_time_manager::user_can_extend($context));
    }

    /**
     * Create a course with teacher and student, a timed quiz and an attempt started at a fixed time.
     *
     * @param array $settings Extra quiz settings.
     * @param int $timestart Attempt start time.
     * @return array{course: \stdClass, cm: \stdClass, quiz: \stdClass, teacher: \stdClass, student: \stdClass, attempt: \stdClass}
     */
    private function create_started_attempt(array $settings, int $timestart): array {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        [$quiz, $cm, $quizgenerator] = $this->create_timed_quiz($course, $settings);

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        $DB->set_field('quiz_attempts', 'timestart', $timestart, ['id' => $attempt->id]);
        $attempt = $DB->get_record('quiz_attempts', ['id' => $attempt->id], '*', MUST_EXIST);

        $this->setUser($teacher);

        return [
            'course' => $course,
            'cm' => $cm,
            'quiz' => $quiz,
            'teacher' => $teacher,
            'student' => $student,
            'attempt' => $attempt,
        ];
    }

    /**
     * Extend the fixture student's attempt.
     *
     * @param array $fixture Fixture from create_started_attempt().
     * @param int $minutes Minutes to add.
     */
    private function extend(array $fixture, int $minutes): void {
        extend_time_manager::extend_user(
            $fixture['course'],
            $fixture['cm'],
            $fixture['quiz'],
            (int) $fixture['student']->id,
            $minutes,
            (int) $fixture['teacher']->id
        );
    }

    /**
     * Effective end time of an attempt including overrides.
     *
     * @param int $attemptid Attempt id.
     * @return int
     */
    private function get_attempt_end_time(int $attemptid): int {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $attemptid], '*', MUST_EXIST);
        return (int) quiz_attempt::create($attemptid)->get_access_manager(time())->get_end_time($attempt);
    }

    /**
     * Get the fixture student's override.
     *
     * @param array $fixture Fixture from create_started_attempt().
     * @return \stdClass
     */
    private function get_override(array $fixture): \stdClass {
        global $DB;

        return $DB->get_record(
            'quiz_overrides',
            ['quiz' => $fixture['quiz']->id, 'userid' => $fixture['student']->id],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Close date binds first: both close date and time limit are raised to the new end.
     *
     * Close in 10 minutes, time limit ends in 20 minutes, +30 minutes:
     * close date +30, time limit +20, new end = old end + 30.
     */
    public function test_extend_user_raises_both_limits_when_timeclose_binds(): void {
        $this->resetAfterTest();

        $now = time();
        $fixture = $this->create_started_attempt(['timelimit' => 1200, 'timeclose' => $now + 600], $now);
        $this->assertSame($now + 600, $this->get_attempt_end_time((int) $fixture['attempt']->id));

        $this->extend($fixture, 30);

        $override = $this->get_override($fixture);
        $this->assertSame($now + 2400, (int) $override->timeclose);
        $this->assertSame(2400, (int) $override->timelimit);
        $this->assertSame($now + 2400, $this->get_attempt_end_time((int) $fixture['attempt']->id));
    }

    /**
     * Time limit binds first: time limit and close date are both raised to the new end.
     */
    public function test_extend_user_raises_both_limits_when_timelimit_binds(): void {
        $this->resetAfterTest();

        $now = time();
        $fixture = $this->create_started_attempt(['timelimit' => 600, 'timeclose' => $now + 1200], $now);

        $this->extend($fixture, 30);

        $override = $this->get_override($fixture);
        $this->assertSame(2400, (int) $override->timelimit);
        $this->assertSame($now + 2400, (int) $override->timeclose);
        $this->assertSame($now + 2400, $this->get_attempt_end_time((int) $fixture['attempt']->id));
    }

    /**
     * A limit already beyond the new end is not written into the override.
     */
    public function test_extend_user_leaves_non_binding_limit_untouched(): void {
        $this->resetAfterTest();

        $now = time();
        $fixture = $this->create_started_attempt(['timelimit' => 600, 'timeclose' => $now + 7200], $now);

        $this->extend($fixture, 15);

        $override = $this->get_override($fixture);
        $this->assertSame(1500, (int) $override->timelimit);
        $this->assertNull($override->timeclose);
        $this->assertSame($now + 1500, $this->get_attempt_end_time((int) $fixture['attempt']->id));
    }

    /**
     * Only a close date: the close date is extended.
     */
    public function test_extend_user_extends_timeclose_only_quiz(): void {
        $this->resetAfterTest();

        $now = time();
        $fixture = $this->create_started_attempt(['timelimit' => 0, 'timeclose' => $now + 600], $now);

        $this->extend($fixture, 10);

        $override = $this->get_override($fixture);
        $this->assertSame($now + 1200, (int) $override->timeclose);
        $this->assertNull($override->timelimit);
    }

    /**
     * Extensions go through the core override manager and fire the override events.
     */
    public function test_extend_user_fires_override_events(): void {
        $this->resetAfterTest();

        $now = time();
        $fixture = $this->create_started_attempt([], $now);

        $sink = $this->redirectEvents();
        $this->extend($fixture, 5);
        $this->extend($fixture, 5);
        $events = $sink->get_events();
        $sink->close();

        $created = array_values(array_filter($events, fn($e) => $e instanceof user_override_created));
        $updated = array_values(array_filter($events, fn($e) => $e instanceof user_override_updated));
        $this->assertCount(1, $created);
        $this->assertCount(1, $updated);
        $this->assertSame((int) $fixture['student']->id, (int) $created[0]->relateduserid);
        $this->assertSame((int) $fixture['teacher']->id, (int) $created[0]->userid);

        // Two extensions add up on the same override.
        $this->assertSame(1200, (int) $this->get_override($fixture)->timelimit);
    }

    /**
     * Open attempts are recalculated so the overdue check uses the new end time.
     */
    public function test_extend_user_updates_timecheckstate(): void {
        global $DB;

        $this->resetAfterTest();

        $now = time();
        $fixture = $this->create_started_attempt([], $now);

        $this->extend($fixture, 15);

        $timecheckstate = (int) $DB->get_field('quiz_attempts', 'timecheckstate', ['id' => $fixture['attempt']->id]);
        $this->assertSame($now + 1500, $timecheckstate);
    }

    /**
     * Settings of an existing user override (e.g. extra attempts, password) survive an extension.
     */
    public function test_extend_user_keeps_existing_override_settings(): void {
        global $DB;

        $this->resetAfterTest();

        $now = time();
        $fixture = $this->create_started_attempt([], $now);
        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $fixture['quiz']->id,
            'userid' => $fixture['student']->id,
            'attempts' => 3,
            'password' => 'secret',
        ]);

        $this->extend($fixture, 10);

        $override = $this->get_override($fixture);
        $this->assertSame(3, (int) $override->attempts);
        $this->assertSame('secret', $override->password);
        $this->assertSame(1200, (int) $override->timelimit);
        $this->assertSame(1, $DB->count_records('quiz_overrides', ['quiz' => $fixture['quiz']->id]));
    }
}

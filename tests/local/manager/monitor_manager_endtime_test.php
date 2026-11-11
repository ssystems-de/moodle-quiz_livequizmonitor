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
 * Unit tests for the End time column in monitor_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use advanced_testcase;

/**
 * Tests for the end time and its display in the monitor's student rows.
 *
 * @covers \quiz_livequizmonitor\local\manager\monitor_manager
 */
final class monitor_manager_endtime_test extends advanced_testcase {
    /** @var string Time zone used by all tests, so the expected text is fixed. */
    const TIMEZONE = 'Europe/Berlin';

    /**
     * Convert a local date and time to a timestamp.
     *
     * @param string $datetime Date and time, e.g. '2026-01-15 10:00'.
     * @return int
     */
    private static function ts(string $datetime): int {
        return (new \DateTime($datetime, new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
    }

    /**
     * Create a course, a quiz with one question, and some enrolled students.
     *
     * @param array $quizsettings Extra quiz settings, e.g. timelimit.
     * @param int $numstudents Number of students to enrol.
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \mod_quiz_generator, 4: \stdClass[]}
     */
    private function create_quiz(array $quizsettings, int $numstudents = 1): array {
        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $course = $generator->create_course();
        $quiz = $quizgenerator->create_instance(['course' => $course->id, 'grade' => 100, 'sumgrades' => 1] + $quizsettings);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $cat = $questiongenerator->create_question_category(['contextid' => \context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $students = [];
        for ($i = 1; $i <= $numstudents; $i++) {
            $students[] = $generator->create_and_enrol($course, 'student', ['firstname' => 'Student', 'lastname' => "S$i"]);
        }

        return [$course, $cm, $quiz, $quizgenerator, $students];
    }

    /**
     * Start an attempt for a student, then move its start time.
     *
     * @param \mod_quiz_generator $quizgenerator Quiz generator.
     * @param \stdClass $quiz Quiz record.
     * @param \stdClass $student Student record.
     * @param int|null $timestart New start time, or null to keep the real one.
     * @return \stdClass Attempt record.
     */
    private function start_attempt($quizgenerator, \stdClass $quiz, \stdClass $student, ?int $timestart = null): \stdClass {
        global $DB;

        $this->setUser($student);
        $attempt = $quizgenerator->create_attempt($quiz->id, $student->id);
        if ($timestart !== null) {
            $DB->set_field('quiz_attempts', 'timestart', $timestart, ['id' => $attempt->id]);
            $attempt->timestart = $timestart;
        }
        return $attempt;
    }

    /**
     * Get the monitor rows in their sorted order, keyed by user id.
     *
     * @param \stdClass $course Course record.
     * @param \stdClass $cm Course module record.
     * @param \stdClass $quiz Quiz record.
     * @param string $sortdirection Sort direction: asc or desc.
     * @param string $sortcolumn Column to sort by.
     * @return \stdClass[]
     */
    private function get_rows(
        \stdClass $course,
        \stdClass $cm,
        \stdClass $quiz,
        string $sortdirection = 'asc',
        string $sortcolumn = 'endtime'
    ): array {
        global $DB;

        $this->setAdminUser();
        $quiz = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $state = monitor_manager::get_state($course, $cm, $quiz, 0, $sortcolumn, $sortdirection);

        $rows = [];
        foreach ($state->students as $row) {
            $rows[$row->userid] = $row;
        }
        return $rows;
    }

    /**
     * Data provider for test_format_end_time.
     *
     * @return array
     */
    public static function format_end_time_provider(): array {
        return [
            'Same day' => ['2026-11-05 10:00', '2026-11-05 11:30', 0, '11:30'],
            'Leading zero on the hour' => ['2026-11-05 08:00', '2026-11-05 09:05', 0, '09:05'],
            'Different day' => ['2026-11-05 23:30', '2026-11-06 00:15', 0, '06-Nov-2026 00:15'],
            'Grace period' => ['2026-11-05 11:00', '2026-11-05 12:15', 300, '12:15 ~ 12:20'],
            'Grace period, different day' => ['2026-11-05 22:00', '2026-11-06 23:55', 900, '06-Nov-2026 23:55 ~ 00:10'],
        ];
    }

    /**
     * End times are shown as hh:mm, with the date when the day changes, and a range for a grace period.
     *
     * @dataProvider format_end_time_provider
     * @param string $start Attempt start, local time.
     * @param string $end Attempt end, local time.
     * @param int $graceperiod Grace period in seconds.
     * @param string $expected Expected display.
     */
    public function test_format_end_time(string $start, string $end, int $graceperiod, string $expected): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        $this->assertSame($expected, monitor_manager::format_end_time(self::ts($start), self::ts($end), $graceperiod));
    }

    /**
     * The end time is the start time plus the time limit, even after the time is up.
     */
    public function test_end_time_from_time_limit(): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, [$student]] = $this->create_quiz(['timelimit' => 3600]);
        $this->start_attempt($quizgenerator, $quiz, $student, self::ts('2026-01-15 10:00'));

        $row = $this->get_rows($course, $cm, $quiz)[$student->id];
        $this->assertSame(self::ts('2026-01-15 11:00'), $row->endtime);
        $this->assertSame('11:00', $row->endtimedisplay);
        $this->assertSame(get_string('timeup', 'quiz_livequizmonitor'), $row->timeremainingdisplay);
    }

    /**
     * When the quiz closes before the time limit runs out, the close date is the end time.
     */
    public function test_end_time_from_close_date(): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        $timestart = time();
        [$course, $cm, $quiz, $quizgenerator, [$student]] = $this->create_quiz([
            'timelimit' => 7200,
            'timeclose' => $timestart + 1800,
        ]);
        $this->start_attempt($quizgenerator, $quiz, $student, $timestart);

        $row = $this->get_rows($course, $cm, $quiz)[$student->id];
        $this->assertSame($timestart + 1800, $row->endtime);
        $this->assertSame(monitor_manager::format_end_time($timestart, $timestart + 1800), $row->endtimedisplay);
    }

    /**
     * An end time on a different day from the start shows the date as well.
     */
    public function test_end_time_on_different_day(): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, [$student]] = $this->create_quiz(['timelimit' => 3600]);
        $this->start_attempt($quizgenerator, $quiz, $student, self::ts('2026-01-15 23:30'));

        $row = $this->get_rows($course, $cm, $quiz)[$student->id];
        $this->assertSame('16-Jan-2026 00:30', $row->endtimedisplay);
    }

    /**
     * A quiz with a grace period shows the end time as a range.
     */
    public function test_end_time_with_grace_period(): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, [$student]] = $this->create_quiz([
            'timelimit' => 4500,
            'overduehandling' => 'graceperiod',
            'graceperiod' => 300,
        ]);
        $this->start_attempt($quizgenerator, $quiz, $student, self::ts('2026-01-15 11:00'));

        $row = $this->get_rows($course, $cm, $quiz)[$student->id];
        $this->assertSame(self::ts('2026-01-15 12:15'), $row->endtime);
        $this->assertSame('12:15 ~ 12:20', $row->endtimedisplay);
    }

    /**
     * User and group overrides change the end time, and a user override wins over a group override.
     */
    public function test_end_time_with_overrides(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, $students] = $this->create_quiz(['timelimit' => 3600], 3);
        [$useronly, $grouponly, $both] = $students;

        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $grouponly->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $both->id]);

        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'groupid' => $group->id, 'timelimit' => 5400]);
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $useronly->id, 'timelimit' => 7200]);
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $both->id, 'timelimit' => 1800]);

        $timestart = self::ts('2026-01-15 10:00');
        foreach ($students as $student) {
            $this->start_attempt($quizgenerator, $quiz, $student, $timestart);
        }

        $rows = $this->get_rows($course, $cm, $quiz);
        $this->assertSame('12:00', $rows[$useronly->id]->endtimedisplay);
        $this->assertSame('11:30', $rows[$grouponly->id]->endtimedisplay);
        $this->assertSame('10:30', $rows[$both->id]->endtimedisplay);
    }

    /**
     * A time extension moves the end time on.
     */
    public function test_end_time_after_time_extension(): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, [$student]] = $this->create_quiz(['timelimit' => 3600]);
        $attempt = $this->start_attempt($quizgenerator, $quiz, $student);

        $before = $this->get_rows($course, $cm, $quiz)[$student->id];
        $this->assertSame((int) $attempt->timestart + 3600, $before->endtime);

        extend_time_manager::extend_quiz_time($course, $cm, $quiz, 0, 15, extend_time_manager::SCOPE_INDIVIDUAL, $student->id);

        $after = $this->get_rows($course, $cm, $quiz)[$student->id];
        $this->assertSame($before->endtime + 900, $after->endtime);
        $this->assertSame(
            monitor_manager::format_end_time((int) $attempt->timestart, $after->endtime),
            $after->endtimedisplay
        );
    }

    /**
     * Not started and no deadline show a dash. Completed shows when the attempt was due
     * to close (with the grace period, as for an active attempt), not when it was submitted.
     */
    public function test_end_time_by_status(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, [$notstarted, $completed, $nodeadline]] = $this->create_quiz([
            'timelimit' => 3600,
            'overduehandling' => 'graceperiod',
            'graceperiod' => 300,
        ], 3);

        $attempt = $this->start_attempt($quizgenerator, $quiz, $completed, self::ts('2026-01-15 10:00'));
        $DB->update_record('quiz_attempts', (object) [
            'id' => $attempt->id,
            'state' => \mod_quiz\quiz_attempt::FINISHED,
            'timefinish' => self::ts('2026-01-15 10:42'),
        ]);

        // Remove the time limit for this student only.
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $nodeadline->id, 'timelimit' => 0]);
        $this->start_attempt($quizgenerator, $quiz, $nodeadline);

        $rows = $this->get_rows($course, $cm, $quiz);

        $this->assertNull($rows[$notstarted->id]->endtime);
        $this->assertSame('—', $rows[$notstarted->id]->endtimedisplay);

        // Started at 10:00 with a 1 hour limit, so due at 11:00, although submitted at 10:42.
        $this->assertSame(self::ts('2026-01-15 11:00'), $rows[$completed->id]->endtime);
        $this->assertSame('11:00 ~ 11:05', $rows[$completed->id]->endtimedisplay);

        $this->assertNull($rows[$nodeadline->id]->endtime);
        $this->assertSame('—', $rows[$nodeadline->id]->endtimedisplay);
    }

    /**
     * A completed attempt's end time follows the student's own deadline, including their overrides.
     */
    public function test_completed_end_time_follows_overrides(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, [$student, $overridden]] = $this->create_quiz(['timelimit' => 3600], 2);

        // A 90 minute time limit for one student only.
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $quiz->id, 'userid' => $overridden->id, 'timelimit' => 5400]);

        // Both started at 10:00, and submitted early.
        foreach ([$student, $overridden] as $user) {
            $attempt = $this->start_attempt($quizgenerator, $quiz, $user, self::ts('2026-01-15 10:00'));
            $DB->update_record('quiz_attempts', (object) [
                'id' => $attempt->id,
                'state' => \mod_quiz\quiz_attempt::FINISHED,
                'timefinish' => self::ts('2026-01-15 10:45'),
            ]);
        }

        $rows = $this->get_rows($course, $cm, $quiz);

        $this->assertSame('11:00', $rows[$student->id]->endtimedisplay);
        $this->assertSame('11:30', $rows[$overridden->id]->endtimedisplay);
    }

    /**
     * Data provider for test_sort_students_without_a_time_last.
     *
     * @return array
     */
    public static function sort_without_time_provider(): array {
        return [
            'End time' => ['endtime'],
            'Time left' => ['timeremaining'],
        ];
    }

    /**
     * Sorting by end time or time left works both ways. Students without a time
     * always go last, sorted by name in the sort direction.
     *
     * @dataProvider sort_without_time_provider
     * @param string $sortcolumn Column to sort by.
     */
    public function test_sort_students_without_a_time_last(string $sortcolumn): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        $timestart = time();
        [$course, $cm, $quiz, $quizgenerator, [$early, $late, $notstarted1, $notstarted2]]
            = $this->create_quiz(['timelimit' => 3600], 4);
        $this->start_attempt($quizgenerator, $quiz, $early, $timestart - 600);
        $this->start_attempt($quizgenerator, $quiz, $late, $timestart);

        // Ascending: earliest time first, then the students with no time, A to Z.
        $rows = $this->get_rows($course, $cm, $quiz, 'asc', $sortcolumn);
        $expected = [$early->id, $late->id, $notstarted1->id, $notstarted2->id];
        $this->assertEquals($expected, array_keys($rows));

        // Descending: latest time first, then the students with no time, Z to A.
        $rows = $this->get_rows($course, $cm, $quiz, 'desc', $sortcolumn);
        $expected = [$late->id, $early->id, $notstarted2->id, $notstarted1->id];
        $this->assertEquals($expected, array_keys($rows));
    }

    /**
     * Students with the same value are sorted by name, in the sort direction.
     */
    public function test_sort_ties_follow_sort_direction(): void {
        $this->resetAfterTest();
        $this->setTimezone(self::TIMEZONE);

        [$course, $cm, $quiz, $quizgenerator, [$tied1, $tied2, $later]] = $this->create_quiz(['timelimit' => 3600], 3);
        $this->start_attempt($quizgenerator, $quiz, $tied1, self::ts('2026-01-15 10:00'));
        $this->start_attempt($quizgenerator, $quiz, $tied2, self::ts('2026-01-15 10:00'));
        $this->start_attempt($quizgenerator, $quiz, $later, self::ts('2026-01-15 10:30'));

        // Ascending: the two tied students first, A to Z, then the later one.
        $rows = $this->get_rows($course, $cm, $quiz, 'asc');
        $expected = [$tied1->id, $tied2->id, $later->id];
        $this->assertEquals($expected, array_keys($rows));

        // Descending: the later student first, then the two tied students, Z to A.
        $rows = $this->get_rows($course, $cm, $quiz, 'desc');
        $expected = [$later->id, $tied2->id, $tied1->id];
        $this->assertEquals($expected, array_keys($rows));
    }
}

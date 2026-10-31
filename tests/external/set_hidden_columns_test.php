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
 * External API tests for set_hidden_columns.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

use advanced_testcase;
use quiz_livequizmonitor\local\column_helper;
use required_capability_exception;

/**
 * Tests for the set_hidden_columns external function.
 *
 * @covers \quiz_livequizmonitor\external\set_hidden_columns
 * @runTestsInSeparateProcesses
 */
final class set_hidden_columns_test extends advanced_testcase {
    /**
     * Create course, teacher, student, and quiz module for monitor API tests.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass}
     */
    private function create_monitor_fixture(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_user();
        $student = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $generator->enrol_user($student->id, $course->id, 'student');

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        return [$teacher, $student, $cm];
    }

    /**
     * Saving hidden columns persists them for the current user and returns them.
     */
    public function test_execute_saves_and_returns_hidden_columns(): void {
        $this->resetAfterTest();

        [$teacher, , $cm] = $this->create_monitor_fixture();
        $this->setUser($teacher);

        $result = set_hidden_columns::execute($cm->id, ['email', 'timeremaining']);

        $this->assertEqualsCanonicalizing(['email', 'timeremaining'], $result['hidden']);
        $this->assertEqualsCanonicalizing(
            ['email', 'timeremaining'],
            column_helper::get_hidden_columns((int) $teacher->id)
        );
    }

    /**
     * Locked (status/actions) and unknown column ids are filtered out server-side,
     * regardless of what the client sends.
     */
    public function test_execute_filters_locked_and_unknown_columns(): void {
        $this->resetAfterTest();

        [$teacher, , $cm] = $this->create_monitor_fixture();
        $this->setUser($teacher);

        $result = set_hidden_columns::execute($cm->id, ['status', 'actions', 'notacolumn', 'progress']);

        $this->assertSame(['progress'], $result['hidden']);
    }

    /**
     * Each user's hidden-column preference is independent.
     */
    public function test_execute_preference_is_per_user(): void {
        $this->resetAfterTest();

        [$teacher, , $cm] = $this->create_monitor_fixture();
        $course = get_coursemodule_from_id('quiz', $cm->id)->course;
        $otherteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($otherteacher->id, $course, 'editingteacher');

        $this->setUser($teacher);
        set_hidden_columns::execute($cm->id, ['email']);

        $this->assertSame([], column_helper::get_hidden_columns((int) $otherteacher->id));
        $this->assertSame(['email'], column_helper::get_hidden_columns((int) $teacher->id));
    }

    /**
     * Users without monitor capability are denied.
     */
    public function test_execute_denies_without_capability(): void {
        $this->resetAfterTest();

        [, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        set_hidden_columns::execute($cm->id, ['email']);
    }

    /**
     * Shown default-hidden extension columns are saved and returned.
     */
    public function test_execute_saves_shown_columns(): void {
        $this->resetAfterTest();

        [$teacher, , $cm] = $this->create_monitor_fixture();
        $this->setUser($teacher);

        $result = set_hidden_columns::execute($cm->id, ['local_sebstatus-battery'], ['local_sebstatus-wifi', 'email']);
        $result = \core_external\external_api::clean_returnvalue(set_hidden_columns::execute_returns(), $result);

        $this->assertSame(['local_sebstatus-battery'], $result['hidden']);
        $this->assertSame(['local_sebstatus-wifi'], $result['shown']);
        $this->assertSame(['local_sebstatus-wifi'], column_helper::get_shown_columns((int) $teacher->id));
    }
}

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
 * External API tests for get_student_note.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../traits/group_scope_test_trait.php');

use advanced_testcase;
use moodle_exception;
use quiz_livequizmonitor\local\manager\student_note_manager;
use quiz_livequizmonitor\tests\traits\group_scope_test_trait;
use required_capability_exception;

/**
 * Tests for get_student_note external function.
 *
 * @covers \quiz_livequizmonitor\external\get_student_note
 * @runTestsInSeparateProcesses
 */
final class get_student_note_test extends advanced_testcase {
    use group_scope_test_trait;

    /**
     * Create course, teacher, student, and quiz module for monitor API tests.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass}
     */
    private function create_monitor_fixture(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        return [$teacher, $student, $cm, $quiz];
    }

    /**
     * Read returns saved note content.
     */
    public function test_execute_returns_saved_note(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm, $quiz] = $this->create_monitor_fixture();
        student_note_manager::save_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Saved text');

        $this->setUser($teacher);
        $result = get_student_note::execute($cm->id, 0, $student->id);

        $this->assertTrue($result['hasnote']);
        $this->assertSame('Saved text', $result['content']);
        $this->assertGreaterThan(0, $result['timemodified']);
    }

    /**
     * Monitor viewers without the managenotes capability can read the note but not edit it.
     */
    public function test_execute_reports_canmanage(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm, $quiz] = $this->create_monitor_fixture();
        student_note_manager::save_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Read only');

        $this->setUser($teacher);
        $this->assertTrue(get_student_note::execute($cm->id, 0, $student->id)['canmanage']);

        $roleid = (int) $this->getDataGenerator()->create_role();
        $context = \context_module::instance($cm->id);
        assign_capability('quiz/livequizmonitor:view', CAP_ALLOW, $roleid, $context->id);
        $viewer = $this->getDataGenerator()->create_and_enrol(get_course($cm->course), 'student');
        role_assign($roleid, $viewer->id, $context->id);

        $this->setUser($viewer);
        $result = get_student_note::execute($cm->id, 0, $student->id);
        $this->assertFalse($result['canmanage']);
        $this->assertSame('Read only', $result['content']);
    }

    /**
     * Read returns empty payload when no note exists.
     */
    public function test_execute_returns_empty_when_no_note(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($teacher);
        $result = get_student_note::execute($cm->id, 0, $student->id);

        $this->assertFalse($result['hasnote']);
        $this->assertSame('', $result['content']);
    }

    /**
     * Users without monitor capability are denied.
     */
    public function test_execute_denies_without_capability(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm] = $this->create_monitor_fixture();
        unset($teacher);

        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        get_student_note::execute($cm->id, 0, $student->id);
    }

    /**
     * Teacher restricted to Group A cannot read notes of a Group B student via a foreign group id.
     */
    public function test_execute_rejects_foreign_group(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        student_note_manager::save_note(
            (int) $fixture['quiz']->id,
            (int) $fixture['studentb']->id,
            (int) get_admin()->id,
            'Confidential'
        );

        $this->setUser($fixture['teacher']);

        $this->expectException(moodle_exception::class);
        try {
            get_student_note::execute($fixture['cm']->id, (int) $fixture['groupb']->id, $fixture['studentb']->id);
        } catch (moodle_exception $e) {
            $this->assertSame('error:groupnotvisible', $e->errorcode);
            throw $e;
        }
    }

    /**
     * Teacher without any group in separate groups mode cannot read notes.
     */
    public function test_execute_rejects_groupless_teacher(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $this->setUser($this->create_groupless_teacher($fixture));

        $this->expectException(moodle_exception::class);
        try {
            get_student_note::execute($fixture['cm']->id, 0, $fixture['studentb']->id);
        } catch (moodle_exception $e) {
            $this->assertSame('error:usernotvisible', $e->errorcode);
            throw $e;
        }
    }
}

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
 * External API tests for save_student_note.
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
 * Tests for save_student_note external function.
 *
 * @covers \quiz_livequizmonitor\external\save_student_note
 * @runTestsInSeparateProcesses
 */
final class save_student_note_test extends advanced_testcase {
    use group_scope_test_trait;

    /**
     * Create course, teacher, student, and quiz module for monitor API tests.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass}
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
     * Save persists note content.
     */
    public function test_execute_saves_note(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($teacher);
        $result = save_student_note::execute($cm->id, 0, $student->id, 'Proctor note');

        $this->assertTrue($result['hasnote']);
        $this->assertSame('Proctor note', $result['content']);
    }

    /**
     * Empty save removes note.
     */
    public function test_execute_deletes_on_empty_save(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm, $quiz] = $this->create_monitor_fixture();
        student_note_manager::save_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'To delete');

        $this->setUser($teacher);
        $result = save_student_note::execute($cm->id, 0, $student->id, '');

        $this->assertFalse($result['hasnote']);
        $this->assertSame('', $result['content']);
    }

    /**
     * Over-length content is rejected.
     */
    public function test_execute_rejects_overlength(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($teacher);
        $this->expectException(moodle_exception::class);
        save_student_note::execute(
            $cm->id,
            0,
            $student->id,
            str_repeat('a', student_note_manager::MAX_LENGTH + 1)
        );
    }

    /**
     * Users without monitor capability are denied.
     */
    public function test_execute_denies_without_capability(): void {
        $this->resetAfterTest();

        [, $student, $cm] = $this->create_monitor_fixture();

        $this->setUser($student);
        $this->expectException(required_capability_exception::class);
        save_student_note::execute($cm->id, 0, $student->id, 'Nope');
    }

    /**
     * Monitor viewers without the managenotes capability cannot write notes.
     */
    public function test_execute_denies_without_managenotes_capability(): void {
        $this->resetAfterTest();

        [$teacher, $student, $cm, $quiz] = $this->create_monitor_fixture();
        student_note_manager::save_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Keep me');

        $roleid = (int) $this->getDataGenerator()->create_role();
        $context = \context_module::instance($cm->id);
        assign_capability('quiz/livequizmonitor:view', CAP_ALLOW, $roleid, $context->id);
        $viewer = $this->getDataGenerator()->create_and_enrol(get_course($cm->course), 'student');
        role_assign($roleid, $viewer->id, $context->id);

        $this->setUser($viewer);
        try {
            save_student_note::execute($cm->id, 0, $student->id, '');
            $this->fail('Expected required_capability_exception');
        } catch (required_capability_exception $e) {
            $this->assertStringContainsString(
                get_string('livequizmonitor:managenotes', 'quiz_livequizmonitor'),
                $e->getMessage()
            );
        }

        $this->assertSame('Keep me', student_note_manager::get_note((int) $quiz->id, (int) $student->id)->content);
    }

    /**
     * Teacher restricted to Group A cannot overwrite or delete a Group B note via a foreign group id.
     */
    public function test_execute_rejects_foreign_group(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $quizid = (int) $fixture['quiz']->id;
        $studentbid = (int) $fixture['studentb']->id;
        student_note_manager::save_note($quizid, $studentbid, (int) get_admin()->id, 'Confidential');

        $this->setUser($fixture['teacher']);

        try {
            save_student_note::execute($fixture['cm']->id, (int) $fixture['groupb']->id, $studentbid, '');
            $this->fail('Expected moodle_exception');
        } catch (moodle_exception $e) {
            $this->assertSame('error:groupnotvisible', $e->errorcode);
        }

        $this->assertSame('Confidential', student_note_manager::get_note($quizid, $studentbid)->content);
    }

    /**
     * Teacher without any group in separate groups mode cannot write notes.
     */
    public function test_execute_rejects_groupless_teacher(): void {
        $this->resetAfterTest();

        $fixture = $this->create_separate_groups_fixture();
        $this->setUser($this->create_groupless_teacher($fixture));

        $this->expectException(moodle_exception::class);
        try {
            save_student_note::execute($fixture['cm']->id, 0, $fixture['studenta']->id, 'Note');
        } catch (moodle_exception $e) {
            $this->assertSame('error:usernotvisible', $e->errorcode);
            throw $e;
        }
    }
}

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
 * Privacy provider tests for supervision notes.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\privacy;

use context_module;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;
use core_privacy\tests\request\approved_contextlist;

/**
 * Tests for quiz_livequizmonitor privacy provider.
 *
 * @covers \quiz_livequizmonitor\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var string Plugin component name for privacy tests. */
    private const COMPONENT = 'quiz_livequizmonitor';

    /**
     * Create course, users, quiz, and module context for privacy tests.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass, 4: context_module, 5: \stdClass}
     */
    private function create_fixture(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');

        $quiz = $generator->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        return [$teacher, $student, $cm, $quiz, $context, $course];
    }

    /**
     * Insert a supervision note row for tests.
     *
     * @param int $quizid Quiz id.
     * @param int $studentid Subject student id.
     * @param int|null $authorid Author user id.
     * @param string $content Note text.
     * @return int New row id.
     */
    private function create_note(int $quizid, int $studentid, ?int $authorid, string $content): int {
        global $DB;

        return (int) $DB->insert_record('quiz_livequizmonitor_notes', (object) [
            'quizid' => $quizid,
            'userid' => $studentid,
            'content' => $content,
            'timemodified' => time(),
            'usermodified' => $authorid,
        ]);
    }

    /**
     * Build an approved context list for a user and quiz module.
     *
     * @param int $userid User id.
     * @param context_module $context Module context.
     * @return approved_contextlist
     */
    private function approved_contextlist_for_user(int $userid, context_module $context): approved_contextlist {
        return new approved_contextlist(
            \core_user::get_user($userid),
            self::COMPONENT,
            [$context->id]
        );
    }

    /**
     * Export plugin data for a user in a quiz context.
     *
     * @param int $userid User id.
     * @param context_module $context Module context.
     * @return \stdClass|null Exported data subtree.
     */
    private function export_for_user(int $userid, context_module $context): ?\stdClass {
        $this->export_context_data_for_user($userid, $context, self::COMPONENT);

        $data = writer::with_context($context)->get_data([get_string('pluginname', self::COMPONENT)]);
        if ($data === null || $data === []) {
            return null;
        }

        return is_object($data) ? $data : (object) $data;
    }

    /**
     * P-01: Teacher export includes authored notes.
     */
    public function test_export_authored_notes(): void {
        $this->resetAfterTest();

        [$teacher, $student, , $quiz, $context] = $this->create_fixture();
        $this->create_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Authored note');

        $export = $this->export_for_user((int) $teacher->id, $context);
        $this->assertNotNull($export);
        $this->assertObjectHasProperty('notes_authored_by_user', $export);
        $this->assertCount(1, $export->notes_authored_by_user);
        $this->assertSame('Authored note', $export->notes_authored_by_user[0]->content);
        $this->assertObjectHasProperty('subject_user', $export->notes_authored_by_user[0]);
    }

    /**
     * P-02: Export includes notes about the user as subject.
     */
    public function test_export_subject_notes(): void {
        $this->resetAfterTest();

        [$teacher, $student, , $quiz, $context] = $this->create_fixture();
        $this->create_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Subject note');

        $export = $this->export_for_user((int) $student->id, $context);
        $this->assertNotNull($export);
        $this->assertObjectHasProperty('notes_about_user', $export);
        $this->assertCount(1, $export->notes_about_user);
        $this->assertSame('Subject note', $export->notes_about_user[0]->content);
    }

    /**
     * P-03: Teacher erasure clears author attribution only.
     */
    public function test_erase_teacher_clears_author(): void {
        global $DB;

        $this->resetAfterTest();

        [$teacher, $student, , $quiz, $context] = $this->create_fixture();
        $noteid = $this->create_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Keep me');

        provider::delete_data_for_user($this->approved_contextlist_for_user((int) $teacher->id, $context));

        $record = $DB->get_record('quiz_livequizmonitor_notes', ['id' => $noteid], '*', MUST_EXIST);
        $this->assertNull($record->usermodified);
        $this->assertSame('Keep me', $record->content);
        $this->assertSame((int) $student->id, (int) $record->userid);
    }

    /**
     * P-04: Student erasure deletes subject note.
     */
    public function test_student_erasure_deletes_note(): void {
        global $DB;

        $this->resetAfterTest();

        [$teacher, $student, , $quiz, $context] = $this->create_fixture();
        $noteid = $this->create_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Gone');

        provider::delete_data_for_user($this->approved_contextlist_for_user((int) $student->id, $context));

        $this->assertFalse($DB->record_exists('quiz_livequizmonitor_notes', ['id' => $noteid]));
    }

    /**
     * P-05: Self-note erasure deletes the row.
     */
    public function test_erase_self_note_deletes_row(): void {
        global $DB;

        $this->resetAfterTest();

        [$teacher, , , $quiz, $context] = $this->create_fixture();
        $noteid = $this->create_note((int) $quiz->id, (int) $teacher->id, (int) $teacher->id, 'Self note');

        provider::delete_data_for_user($this->approved_contextlist_for_user((int) $teacher->id, $context));

        $this->assertFalse($DB->record_exists('quiz_livequizmonitor_notes', ['id' => $noteid]));
    }

    /**
     * P-06: Bulk delete applies author and subject rules.
     */
    public function test_bulk_delete_teacher_and_student(): void {
        global $DB;

        $this->resetAfterTest();

        [$teacher, $student, , $quiz, $context, $course] = $this->create_fixture();
        $studenttwo = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($studenttwo->id, $course->id, 'student');

        $keptnoteid = $this->create_note((int) $quiz->id, (int) $studenttwo->id, (int) $teacher->id, 'Other student');
        $deletednoteid = $this->create_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Student note');

        $userlist = new approved_userlist($context, self::COMPONENT, [$teacher->id, $student->id]);
        provider::delete_data_for_users($userlist);

        $this->assertFalse($DB->record_exists('quiz_livequizmonitor_notes', ['id' => $deletednoteid]));

        $kept = $DB->get_record('quiz_livequizmonitor_notes', ['id' => $keptnoteid], '*', MUST_EXIST);
        $this->assertNull($kept->usermodified);
        $this->assertSame('Other student', $kept->content);
    }

    /**
     * P-07: Context discovery includes author relationship.
     */
    public function test_get_contexts_for_author(): void {
        $this->resetAfterTest();

        [$teacher, $student, , $quiz, $context] = $this->create_fixture();
        $this->create_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Discovery');

        $contextlist = $this->get_contexts_for_userid((int) $teacher->id, self::COMPONENT);
        $this->assertEquals(1, $contextlist->count());
        $this->assertEquals($context->id, (int) $contextlist->get_contextids()[0]);
    }

    /**
     * P-08: After teacher erasure, authored notes no longer export for teacher.
     */
    public function test_export_after_teacher_erasure(): void {
        $this->resetAfterTest();

        [$teacher, $student, , $quiz, $context] = $this->create_fixture();
        $this->create_note((int) $quiz->id, (int) $student->id, (int) $teacher->id, 'Erased author');

        provider::delete_data_for_user($this->approved_contextlist_for_user((int) $teacher->id, $context));
        writer::reset();

        $teachexport = $this->export_for_user((int) $teacher->id, $context);
        $this->assertTrue($teachexport === null || !property_exists($teachexport, 'notes_authored_by_user'));

        $studentexport = $this->export_for_user((int) $student->id, $context);
        $this->assertNotNull($studentexport);
        $this->assertObjectHasProperty('notes_about_user', $studentexport);
        $this->assertSame('Erased author', $studentexport->notes_about_user[0]->content);
        $this->assertFalse(property_exists($studentexport->notes_about_user[0], 'usermodified'));
    }

    /**
     * Both column preferences are exported.
     */
    public function test_export_user_preferences(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        \quiz_livequizmonitor\local\column_helper::set_hidden_columns(
            ['email'],
            (int) $user->id,
            ['local_sebstatus-wifi']
        );

        provider::export_user_preferences((int) $user->id);

        $preferences = writer::with_context(\context_system::instance())->get_user_preferences(self::COMPONENT);
        $this->assertSame('["email"]', $preferences->quiz_livequizmonitor_hiddencolumns->value);
        $this->assertSame('["local_sebstatus-wifi"]', $preferences->quiz_livequizmonitor_showncolumns->value);
    }
}

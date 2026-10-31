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
 * Unit tests for the monitor_report renderable.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\output;

use advanced_testcase;
use mod_quiz\quiz_attempt;
use quiz_livequizmonitor\local\manager\monitor_manager;
use stdClass;

/**
 * Tests for the template context exported by monitor_report.
 *
 * @covers \quiz_livequizmonitor\output\monitor_report
 */
final class monitor_report_test extends advanced_testcase {
    /**
     * Create a course quiz with one short-answer question.
     *
     * @param stdClass $course Course record.
     * @return array{0: stdClass, 1: stdClass, 2: \mod_quiz_generator}
     */
    private function create_quiz_with_question(stdClass $course): array {
        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');
        $questiongenerator = $generator->get_plugin_generator('core_question');

        $quiz = $quizgenerator->create_instance([
            'course' => $course->id,
            'grade' => 100,
            'sumgrades' => 1,
        ]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $cat = $questiongenerator->create_question_category(['contextid' => \context_module::instance($cm->id)->id]);
        $question = $questiongenerator->create_question('shortanswer', null, [
            'category' => $cat->id,
            'answer' => ['frog', '*'],
            'fraction' => [1, 0],
        ]);
        quiz_add_quiz_question($question->id, $quiz);

        return [$quiz, $cm, $quizgenerator];
    }

    /**
     * Create a quiz attempt for a user and set its state.
     *
     * @param \mod_quiz_generator $quizgenerator Quiz generator.
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @param string $state Attempt state.
     */
    private function create_quiz_attempt($quizgenerator, int $quizid, int $userid, string $state): void {
        global $DB;

        $this->setUser($userid);
        $attempt = $quizgenerator->create_attempt($quizid, $userid);
        if ($attempt->state !== $state) {
            $attempt->state = $state;
            if (in_array($state, monitor_manager::completed_attempt_states(), true)) {
                $attempt->timefinish = time();
            }
            $DB->update_record('quiz_attempts', $attempt);
        }
    }

    /**
     * Build the monitor state of a quiz with a not started, an in progress and a finished student.
     *
     * @return stdClass Monitor state from monitor_manager::get_state().
     */
    private function get_state_with_students(): stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        [$quiz, $cm, $quizgenerator] = $this->create_quiz_with_question($course);

        $generator->create_and_enrol($course, 'student', ['firstname' => 'Clara', 'lastname' => 'Notstarted']);
        $inprogress = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bert', 'lastname' => 'Inprogress']);
        $finished = $generator->create_and_enrol($course, 'student', ['firstname' => 'Anna', 'lastname' => 'Finished']);

        $this->create_quiz_attempt($quizgenerator, $quiz->id, $inprogress->id, quiz_attempt::IN_PROGRESS);
        $this->create_quiz_attempt($quizgenerator, $quiz->id, $finished->id, quiz_attempt::FINISHED);

        $this->setAdminUser();
        return monitor_manager::get_state($course, $cm, $quiz, 0);
    }

    /**
     * Export the template context of a monitor report.
     *
     * @param stdClass $state Monitor state.
     * @param int $groupid Active group id.
     * @param string $groupmenu Group menu HTML.
     * @return array Template context.
     */
    private function export(stdClass $state, int $groupid = 0, string $groupmenu = ''): array {
        global $PAGE;

        $report = new monitor_report($state, $groupid, $groupmenu);
        return $report->export_for_template($PAGE->get_renderer('quiz_livequizmonitor'));
    }

    /**
     * Find the exported student entry by full name.
     *
     * @param array $context Template context.
     * @param string $fullname Student full name.
     * @return array Student entry.
     */
    private function find_student(array $context, string $fullname): array {
        foreach ($context['students'] as $student) {
            if ($student['fullname'] === $fullname) {
                return $student;
            }
        }
        $this->fail("Student {$fullname} not exported");
    }

    /**
     * Students, per-row data and filter counts are exported.
     */
    public function test_export_with_students(): void {
        $this->resetAfterTest();

        $state = $this->get_state_with_students();
        $context = $this->export($state);

        $keys = ['quizname', 'students', 'summary', 'columns', 'tableheaders', 'filter', 'hiddencolumnsjson', 'canextend'];
        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $context);
        }
        $this->assertTrue($context['hasstudents']);
        $this->assertCount(3, $context['students']);
        foreach ($context['students'] as $student) {
            $this->assertSame($state->cmid, $student['cmid']);
            $this->assertSame($state->courseid, $student['courseid']);
            foreach (['extendactionenabled', 'canviewattempts', 'notelabel'] as $key) {
                $this->assertArrayHasKey($key, $student);
            }
        }

        // The status chips show the total and the summary counts.
        $chips = array_column($context['filter']['chips'], 'count', 'status');
        $this->assertSame($state->totalstudents, $chips['all']);
        foreach (['notstarted', 'idle', 'inprogress', 'completed'] as $status) {
            $this->assertSame($state->summary->$status->count, $chips[$status]);
        }
        $this->assertSame(1, $chips['notstarted']);
        $this->assertSame(1, $chips['inprogress']);
        $this->assertSame(1, $chips['completed']);
    }

    /**
     * An empty cohort exports no students and hides the email column.
     */
    public function test_export_without_students(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        [$quiz, $cm] = $this->create_quiz_with_question($course);
        $this->setAdminUser();

        $context = $this->export(monitor_manager::get_state($course, $cm, $quiz, 0));

        $this->assertSame([], $context['students']);
        $this->assertFalse($context['hasstudents']);
        $this->assertFalse($context['showemailcolumn']);
    }

    /**
     * The extend action is only enabled with the capability and for in progress or idle students.
     */
    public function test_export_without_extend_capability(): void {
        $this->resetAfterTest();

        $state = $this->get_state_with_students();

        $state->canextend = false;
        $context = $this->export($state);
        $this->assertFalse($context['canextend']);
        foreach ($context['students'] as $student) {
            $this->assertFalse($student['extendactionenabled']);
        }

        $state->canextend = true;
        $context = $this->export($state);
        $this->assertTrue($this->find_student($context, 'Bert Inprogress')['extendactionenabled']);
        $this->assertFalse($this->find_student($context, 'Anna Finished')['extendactionenabled']);
        $this->assertFalse($this->find_student($context, 'Clara Notstarted')['extendactionenabled']);
    }

    /**
     * Group id and group menu are passed through unchanged.
     */
    public function test_export_passes_group_and_menu(): void {
        $this->resetAfterTest();

        $groupmenu = '<div class="groupmenu">x</div>';
        $context = $this->export($this->get_state_with_students(), 42, $groupmenu);

        $this->assertSame(42, $context['groupid']);
        $this->assertSame($groupmenu, $context['filter']['groupmenu']);
    }

    /**
     * Only the sorted column is marked active, with the sort direction.
     */
    public function test_export_sort_metadata(): void {
        $this->resetAfterTest();

        $state = $this->get_state_with_students();
        $state->sortcolumn = 'fullname';
        $state->sortdirection = 'desc';
        $context = $this->export($state);

        $columns = array_column($context['columns'], null, 'id');
        $active = array_filter($context['columns'], fn(array $column): bool => !empty($column['active']));
        $this->assertCount(1, $active);
        $this->assertSame('student', reset($active)['id']);
        $this->assertSame(get_string('desc'), $columns['student']['sortlabel']);
        $this->assertSame('fa-arrow-down-short-wide', $columns['student']['sorticon']);
        $this->assertFalse($columns['actions']['sortable']);
    }

    /**
     * Extension columns go before actions; every row gets one cell per extension column.
     */
    public function test_export_extension_columns_cells_and_actions(): void {
        $this->resetAfterTest();
        $state = $this->get_state_with_students();
        $state->extensioncolumns = [
            ['id' => 'local_sebstatus-battery', 'label' => 'Battery', 'defaulthidden' => false],
            ['id' => 'local_sebstatus-wifi', 'label' => 'Wi-Fi', 'defaulthidden' => true],
        ];
        $first = $state->students[0];
        $first->extensioncells = [
            ['column' => 'local_sebstatus-wifi', 'display' => 'weak', 'sortvalue' => 'weak', 'sortnumeric' => false],
        ];
        $first->highlight = 'danger';
        $first->extensionactions = [
            ['label' => 'Log', 'url' => 'https://example.com', 'iconkey' => '', 'iconcomponent' => '', 'newtab' => false],
        ];

        $context = $this->export($state);

        $ids = array_column($context['columns'], 'id');
        $this->assertSame(['local_sebstatus-battery', 'local_sebstatus-wifi', 'actions'], array_slice($ids, -3));
        $battery = $context['columns'][count($ids) - 3];
        $this->assertTrue($battery['sortable']);
        $this->assertFalse($battery['locked']);
        $this->assertSame('local_sebstatus-battery', $battery['sortcolumn']);
        $this->assertSame('Battery', $battery['label']);

        $student = $context['students'][0];
        $this->assertSame([
            ['column' => 'local_sebstatus-battery', 'display' => ''],
            ['column' => 'local_sebstatus-wifi', 'display' => 'weak'],
        ], $student['extensioncells']);
        $this->assertSame('danger', $student['highlight']);
        $this->assertTrue($student['hasextensionactions']);
        $this->assertFalse($context['students'][1]['hasextensionactions']);
        $this->assertCount(2, $context['students'][1]['extensioncells']);

        $this->assertContains('local_sebstatus-wifi', json_decode($context['hiddencolumnsjson']));
        $this->assertNotContains('local_sebstatus-battery', json_decode($context['hiddencolumnsjson']));
    }
}

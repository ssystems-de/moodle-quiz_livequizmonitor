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
 * Unit tests for the live quiz monitor extension hooks.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\extension;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../fixtures/extension_callbacks.php');

use advanced_testcase;
use quiz_livequizmonitor\hook\extend_columns;
use quiz_livequizmonitor\hook\extend_row_actions;
use quiz_livequizmonitor\hook\extend_row_highlights;
use quiz_livequizmonitor\local\manager\extend_time_manager;
use quiz_livequizmonitor\local\manager\monitor_manager;
use quiz_livequizmonitor\tests\fixtures\extension_callbacks;

/**
 * Tests for hook dispatch, isolation and the extension data in the monitor state.
 *
 * @covers \quiz_livequizmonitor\local\extension\extension_dispatcher
 * @covers \quiz_livequizmonitor\hook\row_hook_base
 * @covers \quiz_livequizmonitor\hook\extend_columns
 * @covers \quiz_livequizmonitor\hook\extend_row_highlights
 * @covers \quiz_livequizmonitor\hook\extend_row_actions
 * @covers \quiz_livequizmonitor\local\manager\monitor_manager
 */
final class extension_dispatcher_test extends advanced_testcase {
    /** @var string First test component. */
    private const FIRST = 'quiz_livequizmonitor';

    /** @var string Second test component. */
    private const SECOND = 'local_fixturetwo';

    /** @var \stdClass Course. */
    private $course;

    /** @var \cm_info Quiz course module. */
    private $cm;

    /** @var \stdClass Quiz. */
    private $quiz;

    /** @var \stdClass[] Students ordered by name: Anna, Bert, Carl. */
    private $students;

    /**
     * Reset the fixture callbacks and inject a hook manager with both test components.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        extension_callbacks::reset();
        $this->use_hook_files([
            self::FIRST => __DIR__ . '/../../fixtures/extension_hooks.php',
            self::SECOND => __DIR__ . '/../../fixtures/extension_hooks_second.php',
        ]);
    }

    /**
     * Replace the hook manager with one that only knows the given callback files.
     *
     * @param array $componentfiles Component => hook callback file.
     */
    private function use_hook_files(array $componentfiles): void {
        \core\di::set(\core\hook\manager::class, \core\hook\manager::phpunit_get_instance($componentfiles));
    }

    /**
     * Create a course with a quiz and three students, and log in as admin.
     *
     * @param array $quizoptions Extra quiz options (e.g. groupmode).
     */
    private function create_quiz_with_students(array $quizoptions = []): void {
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id] + $quizoptions);
        $this->cm = get_fast_modinfo($this->course)->get_cm($this->quiz->cmid);

        $this->students = [];
        foreach (['Anna', 'Bert', 'Carl'] as $firstname) {
            $student = $generator->create_user(['firstname' => $firstname, 'lastname' => 'Student']);
            $generator->enrol_user($student->id, $this->course->id, 'student');
            $this->students[] = $student;
        }
        $this->setAdminUser();
    }

    /**
     * Build the monitor state.
     *
     * @param string $sortcolumn Sort column.
     * @param string $sortdirection Sort direction.
     * @return \stdClass
     */
    private function get_state(string $sortcolumn = 'status', string $sortdirection = 'asc'): \stdClass {
        return monitor_manager::get_state($this->course, $this->cm, $this->quiz, 0, $sortcolumn, $sortdirection);
    }

    /**
     * Index the state rows by user id.
     *
     * @param \stdClass $state Monitor state.
     * @return \stdClass[]
     */
    private function rows_by_userid(\stdClass $state): array {
        return array_column($state->students, null, 'userid');
    }

    /**
     * Number of calls of a hook by a component.
     *
     * @param string $hookclass Hook class.
     * @param string $component Component.
     * @return int
     */
    private function count_calls(string $hookclass, string $component): int {
        return count(array_filter(
            extension_callbacks::$calls[$hookclass] ?? [],
            static fn(array $call): bool => $call['component'] === $component
        ));
    }

    /**
     * Without callbacks the state has empty extension fields (FR-006).
     */
    public function test_state_without_callbacks_has_empty_extension_fields(): void {
        $this->use_hook_files([]);
        $this->create_quiz_with_students();

        $state = $this->get_state();

        $this->assertSame([], $state->extensioncolumns);
        $this->assertCount(3, $state->students);
        foreach ($state->students as $row) {
            $this->assertSame([], $row->extensioncells);
            $this->assertSame('', $row->highlight);
            $this->assertSame([], $row->extensionactions);
        }
    }

    /**
     * Each row hook is called once per state build, with all students at once (SC-005).
     */
    public function test_each_row_hook_called_once_per_state(): void {
        $this->create_quiz_with_students();

        $this->get_state();

        $userids = array_map(static fn($student): int => (int) $student->id, $this->students);
        sort($userids);
        foreach ([extend_columns::class, extend_row_highlights::class, extend_row_actions::class] as $hookclass) {
            $this->assertSame(1, $this->count_calls($hookclass, self::FIRST), $hookclass);
            $this->assertSame(1, $this->count_calls($hookclass, self::SECOND), $hookclass);
            $received = extension_callbacks::$calls[$hookclass][0]['userids'];
            sort($received);
            $this->assertSame($userids, $received);
        }
    }

    /**
     * A failing callback only loses its own contributions (FR-005, SC-004).
     */
    public function test_failing_callback_is_isolated(): void {
        $this->create_quiz_with_students();
        $anna = (int) $this->students[0]->id;
        extension_callbacks::$config[self::FIRST] = [
            'columns' => [['broken', 'Broken', false]],
            'values' => [['broken', $anna, 'x', null, false]],
            'highlights' => [[$anna, 'danger']],
            'actions' => [[$anna, 'Broken action', '/user/profile.php', false, false]],
            'throw' => ['extend_columns' => true, 'extend_row_highlights' => true, 'extend_row_actions' => true],
        ];
        extension_callbacks::$config[self::SECOND] = [
            'columns' => [['ok', 'OK', false]],
            'values' => [['ok', $anna, 'fine', null, false]],
            'highlights' => [[$anna, 'info']],
            'actions' => [[$anna, 'OK action', '/user/profile.php', false, false]],
        ];

        $state = $this->get_state();
        $this->assertDebuggingCalledCount(3);

        $this->assertSame(['local_fixturetwo-ok'], array_column($state->extensioncolumns, 'id'));
        $row = $this->rows_by_userid($state)[$anna];
        $this->assertSame(['local_fixturetwo-ok'], array_column($row->extensioncells, 'column'));
        $this->assertSame('info', $row->highlight);
        $this->assertSame(['OK action'], array_column($row->extensionactions, 'label'));
    }

    /**
     * Callbacks disabled by the admin are not called.
     */
    public function test_disabled_callback_is_not_called(): void {
        global $CFG;

        $CFG->hooks_callback_overrides = [
            extend_columns::class => [
                extension_callbacks::class . '::extend_columns' => ['disabled' => true],
            ],
        ];
        $this->use_hook_files([self::FIRST => __DIR__ . '/../../fixtures/extension_hooks.php']);
        $this->create_quiz_with_students();

        $this->get_state();

        $this->assertSame(0, $this->count_calls(extend_columns::class, self::FIRST));
        $this->assertSame(1, $this->count_calls(extend_row_highlights::class, self::FIRST));
    }

    /**
     * Hooks only receive the students the user may see (FR-004).
     */
    public function test_hooks_only_receive_visible_students(): void {
        $this->create_quiz_with_students(['groupmode' => SEPARATEGROUPS]);
        $generator = $this->getDataGenerator();
        $groupa = $generator->create_group(['courseid' => $this->course->id]);
        $groupb = $generator->create_group(['courseid' => $this->course->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->students[0]->id]);
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $this->students[1]->id]);
        $generator->create_group_member(['groupid' => $groupb->id, 'userid' => $this->students[2]->id]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $this->course->id, 'teacher');
        $generator->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);
        $this->setUser($teacher);

        monitor_manager::get_state($this->course, $this->cm, $this->quiz, $groupa->id);

        $received = extension_callbacks::$calls[extend_columns::class][0]['userids'];
        sort($received);
        $expected = [(int) $this->students[0]->id, (int) $this->students[1]->id];
        sort($expected);
        $this->assertSame($expected, $received);
    }

    /**
     * The bulk extend lookup does not run the extension hooks.
     */
    public function test_extendable_userids_skip_hooks(): void {
        $this->create_quiz_with_students();

        extend_time_manager::get_extendable_userids($this->course, $this->cm, $this->quiz, 0);

        $this->assertSame([], extension_callbacks::$calls);
    }

    /**
     * Columns are namespaced per component, validated and kept in registration order.
     */
    public function test_column_registration(): void {
        $this->create_quiz_with_students();
        extension_callbacks::$config[self::FIRST] = [
            'columns' => [
                ['fixture', 'Fixture', false],
                ['fixture', 'Duplicate', false],
                ['Bad-Name', 'Invalid', false],
                ['second', 'Second', true],
            ],
        ];
        extension_callbacks::$config[self::SECOND] = [
            'columns' => [['fixture', 'Other plugin', false]],
        ];

        $state = $this->get_state();
        $this->assertDebuggingCalledCount(2);

        $this->assertSame([
            ['id' => 'quiz_livequizmonitor-fixture', 'label' => 'Fixture', 'defaulthidden' => false],
            ['id' => 'quiz_livequizmonitor-second', 'label' => 'Second', 'defaulthidden' => true],
            ['id' => 'local_fixturetwo-fixture', 'label' => 'Other plugin', 'defaulthidden' => false],
        ], $state->extensioncolumns);
    }

    /**
     * Plain values are escaped, HTML values are cleaned (FR-015).
     */
    public function test_values_are_escaped_and_cleaned(): void {
        $this->create_quiz_with_students();
        [$anna, $bert] = [(int) $this->students[0]->id, (int) $this->students[1]->id];
        extension_callbacks::$config[self::FIRST] = [
            'columns' => [['fixture', 'Fixture', false]],
            'values' => [
                ['fixture', $anna, '<b>bold</b>', null, false],
                ['fixture', $bert, '<b onclick="alert(1)">bold</b><script>alert(2)</script>', null, true],
            ],
        ];

        $rows = $this->rows_by_userid($this->get_state());

        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', $rows[$anna]->extensioncells[0]['display']);
        $html = $rows[$bert]->extensioncells[0]['display'];
        $this->assertStringContainsString('<b>bold</b>', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame('bold', $rows[$bert]->extensioncells[0]['sortvalue']);
        $this->assertFalse($rows[$bert]->extensioncells[0]['sortnumeric']);
    }

    /**
     * Values for unknown users or unregistered columns are ignored.
     */
    public function test_invalid_values_are_ignored(): void {
        $this->create_quiz_with_students();
        $anna = (int) $this->students[0]->id;
        $outsider = $this->getDataGenerator()->create_user();
        extension_callbacks::$config[self::FIRST] = [
            'columns' => [['fixture', 'Fixture', false]],
            'values' => [
                ['fixture', (int) $outsider->id, 'x', null, false],
                ['unknown', $anna, 'x', null, false],
            ],
        ];
        // The other component may not write into the first component's column.
        extension_callbacks::$config[self::SECOND] = [
            'values' => [['fixture', $anna, 'x', null, false]],
        ];

        $state = $this->get_state();
        $this->assertDebuggingCalledCount(3);

        foreach ($state->students as $row) {
            $this->assertSame([], $row->extensioncells);
        }
    }

    /**
     * Rows sort by an extension column; rows without a value sort last in both directions.
     */
    public function test_sort_by_extension_column(): void {
        $this->create_quiz_with_students();
        [$anna, $bert, $carl] = array_map(static fn($student): int => (int) $student->id, $this->students);
        extension_callbacks::$config[self::FIRST] = [
            'columns' => [['fixture', 'Fixture', false]],
            'values' => [
                ['fixture', $anna, 'ten', 10, false],
                ['fixture', $bert, 'nine', 9, false],
            ],
        ];

        $asc = array_column($this->get_state('quiz_livequizmonitor-fixture', 'asc')->students, 'userid');
        $this->assertSame([$bert, $anna, $carl], $asc);

        $desc = array_column($this->get_state('quiz_livequizmonitor-fixture', 'desc')->students, 'userid');
        $this->assertSame([$anna, $bert, $carl], $desc);
    }

    /**
     * Without a sort value, rows sort by the displayed text.
     */
    public function test_sort_by_display_text(): void {
        $this->create_quiz_with_students();
        [$anna, $bert, $carl] = array_map(static fn($student): int => (int) $student->id, $this->students);
        extension_callbacks::$config[self::FIRST] = [
            'columns' => [['fixture', 'Fixture', false]],
            'values' => [
                ['fixture', $anna, 'Zebra', null, false],
                ['fixture', $bert, '<i>apple</i>', null, true],
                ['fixture', $carl, 'Mango', null, false],
            ],
        ];

        $asc = array_column($this->get_state('quiz_livequizmonitor-fixture', 'asc')->students, 'userid');
        $this->assertSame([$bert, $carl, $anna], $asc);
    }

    /**
     * The highest severity over all components wins; unknown values are ignored.
     */
    public function test_highlights(): void {
        $this->create_quiz_with_students();
        [$anna, $bert, $carl] = array_map(static fn($student): int => (int) $student->id, $this->students);
        extension_callbacks::$config[self::FIRST] = [
            'highlights' => [[$anna, 'info'], [$bert, 'critical']],
        ];
        extension_callbacks::$config[self::SECOND] = [
            'highlights' => [[$anna, 'danger'], [$carl, 'warning']],
        ];

        $rows = $this->rows_by_userid($this->get_state());
        $this->assertDebuggingCalledCount(1);

        $this->assertSame('danger', $rows[$anna]->highlight);
        $this->assertSame('', $rows[$bert]->highlight);
        $this->assertSame('warning', $rows[$carl]->highlight);
    }

    /**
     * Actions are exported per student in callback order; invalid ones are dropped.
     */
    public function test_actions(): void {
        $this->create_quiz_with_students();
        [$anna, $bert] = [(int) $this->students[0]->id, (int) $this->students[1]->id];
        $outsider = $this->getDataGenerator()->create_user();
        extension_callbacks::$config[self::FIRST] = [
            'actions' => [
                [$anna, 'First', '/user/profile.php', true, true],
                [$anna, '  ', '/user/profile.php', false, false],
                [(int) $outsider->id, 'Outsider', '/user/profile.php', false, false],
            ],
        ];
        extension_callbacks::$config[self::SECOND] = [
            'actions' => [[$anna, 'Second', '/user/view.php', false, false]],
        ];

        $rows = $this->rows_by_userid($this->get_state());
        $this->assertDebuggingCalledCount(2);

        $this->assertSame([
            [
                'label' => 'First',
                'url' => (new \moodle_url('/user/profile.php', ['id' => $anna]))->out(false),
                'iconkey' => 'i/info',
                'iconcomponent' => 'moodle',
                'newtab' => true,
            ],
            [
                'label' => 'Second',
                'url' => (new \moodle_url('/user/view.php', ['id' => $anna]))->out(false),
                'iconkey' => '',
                'iconcomponent' => '',
                'newtab' => false,
            ],
        ], $rows[$anna]->extensionactions);
        $this->assertSame([], $rows[$bert]->extensionactions);
    }
}

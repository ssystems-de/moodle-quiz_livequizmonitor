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
 * Unit tests for the declare_filters hook of the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

use advanced_testcase;
use core\output\pix_icon;

/**
 * Tests for declaring filter groups and filters.
 *
 * @covers \quiz_livequizmonitor\hook\declare_filters
 */
final class declare_filters_test extends advanced_testcase {
    /**
     * Create the hook for a new quiz.
     *
     * @return declare_filters
     */
    private function create_hook(): declare_filters {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $cm = get_fast_modinfo($course)->get_cm($quiz->cmid);
        return new declare_filters($course, $cm, $quiz);
    }

    /**
     * The hook hands out the monitored quiz.
     */
    public function test_monitor_data(): void {
        $hook = $this->create_hook();

        $this->assertSame((int) $hook->get_cm()->instance, (int) $hook->get_quiz()->id);
        $this->assertSame((int) $hook->get_cm()->course, (int) $hook->get_course()->id);
        $this->assertSame((int) $hook->get_cm()->id, (int) $hook->get_context()->instanceid);
    }

    /**
     * Groups and filters are kept in declaration order, with their icons.
     */
    public function test_groups_and_filters_in_declaration_order(): void {
        $hook = $this->create_hook();

        $hook->add_filter_group('local_fixturetwo', 'Second plugin');
        $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');
        $hook->add_filter('quiz_livequizmonitor-odd', 'Odd', new pix_icon('i/warning', '', 'moodle'));
        $hook->add_filter('local_fixturetwo-even', 'Even');
        $hook->add_filter('quiz_livequizmonitor-first', 'First');

        $this->assertSame([
            [
                'component' => 'local_fixturetwo',
                'label' => 'Second plugin',
                'filters' => [
                    ['id' => 'local_fixturetwo-even', 'label' => 'Even', 'iconkey' => '', 'iconcomponent' => ''],
                ],
            ],
            [
                'component' => 'quiz_livequizmonitor',
                'label' => 'Fixture filters',
                'filters' => [
                    ['id' => 'quiz_livequizmonitor-odd', 'label' => 'Odd', 'iconkey' => 'i/warning', 'iconcomponent' => 'moodle'],
                    ['id' => 'quiz_livequizmonitor-first', 'label' => 'First', 'iconkey' => '', 'iconcomponent' => ''],
                ],
            ],
        ], $hook->get_filter_groups());
        $this->assertSame([
            'quiz_livequizmonitor-odd' => ['id' => 'quiz_livequizmonitor-odd', 'label' => 'Odd'],
            'local_fixturetwo-even' => ['id' => 'local_fixturetwo-even', 'label' => 'Even'],
            'quiz_livequizmonitor-first' => ['id' => 'quiz_livequizmonitor-first', 'label' => 'First'],
        ], $hook->get_filters());
    }

    /**
     * Without declarations the results are empty; a group without filters is left out.
     */
    public function test_group_without_filters_is_left_out(): void {
        $hook = $this->create_hook();
        $this->assertSame([], $hook->get_filter_groups());

        $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');

        $this->assertSame([], $hook->get_filter_groups());
        $this->assertSame([], $hook->get_filters());
    }

    /**
     * A group with an empty label is dropped with debugging, and so are its filters, without another message.
     */
    public function test_group_without_label_is_dropped(): void {
        $hook = $this->create_hook();

        $hook->add_filter_group('quiz_livequizmonitor', ' ');
        $this->assertDebuggingCalledCount(1);

        $hook->add_filter('quiz_livequizmonitor-odd', 'Odd');
        $this->assertDebuggingNotCalled();
        $this->assertSame([], $hook->get_filter_groups());
        $this->assertSame([], $hook->get_filters());
        $this->assertTrue($hook->is_dropped_filter('quiz_livequizmonitor-odd'));
    }

    /**
     * A filter with an empty label is dropped with debugging; the other filters are kept.
     */
    public function test_filter_without_label_is_dropped(): void {
        $hook = $this->create_hook();
        $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');

        $hook->add_filter('quiz_livequizmonitor-empty', '');
        $this->assertDebuggingCalledCount(1);
        $hook->add_filter('quiz_livequizmonitor-odd', 'Odd');

        $this->assertSame(['quiz_livequizmonitor-odd'], array_keys($hook->get_filters()));
        $this->assertTrue($hook->is_dropped_filter('quiz_livequizmonitor-empty'));
        $this->assertFalse($hook->is_dropped_filter('quiz_livequizmonitor-odd'));
        $this->assertTrue($hook->is_declared_filter('quiz_livequizmonitor-odd'));
        $this->assertFalse($hook->is_declared_filter('quiz_livequizmonitor-empty'));
        $this->assertFalse($hook->is_declared_filter('quiz_livequizmonitor-unknown'));
    }

    /**
     * Programming errors that do not depend on data.
     *
     * @return array[] Case => [callable that misuses the hook, expected message].
     */
    public static function programming_error_provider(): array {
        return [
            'invalid component' => [
                static fn(declare_filters $hook) => $hook->add_filter_group('Local_Bad', 'Bad'),
                "Invalid live quiz monitor filter group 'Local_Bad'",
            ],
            'component with a dash' => [
                static fn(declare_filters $hook) => $hook->add_filter_group('local_bad-name', 'Bad'),
                "Invalid live quiz monitor filter group 'local_bad-name'",
            ],
            'component with a trailing newline' => [
                static fn(declare_filters $hook) => $hook->add_filter_group("local_bad\n", 'Bad'),
                "Invalid live quiz monitor filter group 'local_bad",
            ],
            'second group for a component' => [
                static function (declare_filters $hook): void {
                    $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');
                    $hook->add_filter_group('quiz_livequizmonitor', 'Again');
                },
                "Live quiz monitor filter group 'quiz_livequizmonitor' is already declared",
            ],
            'invalid filter name' => [
                static function (declare_filters $hook): void {
                    $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');
                    $hook->add_filter('quiz_livequizmonitor-Bad', 'Bad');
                },
                "Invalid live quiz monitor filter id 'quiz_livequizmonitor-Bad'",
            ],
            'filter id with a trailing newline' => [
                static function (declare_filters $hook): void {
                    $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');
                    $hook->add_filter("quiz_livequizmonitor-odd\n", 'Odd');
                },
                "Invalid live quiz monitor filter id 'quiz_livequizmonitor-odd",
            ],
            'filter without component' => [
                static fn(declare_filters $hook) => $hook->add_filter('odd', 'Odd'),
                "Invalid live quiz monitor filter id 'odd'",
            ],
            'duplicate filter' => [
                static function (declare_filters $hook): void {
                    $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');
                    $hook->add_filter('quiz_livequizmonitor-odd', 'Odd');
                    $hook->add_filter('quiz_livequizmonitor-odd', 'Again');
                },
                "Live quiz monitor filter 'quiz_livequizmonitor-odd' is already declared",
            ],
            'filter without group' => [
                static fn(declare_filters $hook) => $hook->add_filter('local_fixturetwo-even', 'Even'),
                "Live quiz monitor filter 'local_fixturetwo-even' has no group",
            ],
        ];
    }

    /**
     * Programming errors throw a coding_exception.
     *
     * @dataProvider programming_error_provider
     * @param callable $misuse Callable that misuses the hook.
     * @param string $message Expected part of the exception message.
     */
    public function test_programming_errors_throw(callable $misuse, string $message): void {
        $hook = $this->create_hook();

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage($message);
        $misuse($hook);
    }

    /**
     * Declaring a dropped group or filter again is still a programming error.
     */
    public function test_dropped_declarations_cannot_be_repeated(): void {
        $hook = $this->create_hook();
        $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');
        $hook->add_filter('quiz_livequizmonitor-empty', '');
        $this->assertDebuggingCalledCount(1);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage("Live quiz monitor filter 'quiz_livequizmonitor-empty' is already declared");
        $hook->add_filter('quiz_livequizmonitor-empty', 'Label');
    }

    /**
     * A second group for a component whose first group was dropped is still a programming error.
     */
    public function test_dropped_group_cannot_be_repeated(): void {
        $hook = $this->create_hook();
        $hook->add_filter_group('quiz_livequizmonitor', '');
        $this->assertDebuggingCalledCount(1);

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage("Live quiz monitor filter group 'quiz_livequizmonitor' is already declared");
        $hook->add_filter_group('quiz_livequizmonitor', 'Label');
    }
}

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
 * Unit tests for overrides_manager.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use advanced_testcase;
use context_module;

/**
 * Tests for overrides_manager::get_override_map().
 *
 * @covers \quiz_livequizmonitor\local\manager\overrides_manager
 */
final class overrides_manager_test extends advanced_testcase {
    /**
     * Create a course, quiz, and two enrolled students.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass, 4: \stdClass}
     *         course, quiz, cm, student1, student2
     */
    private function create_quiz_with_students(): array {
        $generator = $this->getDataGenerator();
        $quizgenerator = $generator->get_plugin_generator('mod_quiz');

        $course = $generator->create_course();
        $quiz = $quizgenerator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $student1 = $generator->create_user();
        $student2 = $generator->create_user();
        $generator->enrol_user($student1->id, $course->id, 'student');
        $generator->enrol_user($student2->id, $course->id, 'student');

        return [$course, $quiz, $cm, $student1, $student2];
    }

    /**
     * A student with no override row at all is reported as false, not an object.
     */
    public function test_get_override_map_no_override_returns_false(): void {
        $this->resetAfterTest();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertFalse($map[$student1->id]);
    }

    /**
     * A user override with a timelimit set is reported as a user override,
     * not a group override, and as time-related.
     */
    public function test_get_override_map_detects_user_time_override(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, , $student1, $student2] = $this->create_quiz_with_students();

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
            'timelimit' => 1800,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id, $student2->id]);

        $this->assertTrue($map[$student1->id]->hasuseroverride);
        $this->assertFalse($map[$student1->id]->hasgroupoverride);
        $this->assertTrue($map[$student1->id]->hastimeoverride);
        $this->assertFalse($map[$student2->id]);
    }

    /**
     * Each of the five override value columns is independently detected on
     * a user override, and correctly flagged as time-related or not.
     *
     * @dataProvider override_column_provider
     * @param string $column Override column to set.
     * @param mixed $value Value to store in that column.
     * @param bool $expectedhastimeoverride Whether this column should set hastimeoverride.
     */
    public function test_get_override_map_detects_each_user_column(
        string $column,
        $value,
        bool $expectedhastimeoverride
    ): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
            $column => $value,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertTrue($map[$student1->id]->hasuseroverride);
        $this->assertSame($expectedhastimeoverride, $map[$student1->id]->hastimeoverride);
    }

    /**
     * Data provider for test_get_override_map_detects_each_user_column.
     *
     * @return array
     */
    public static function override_column_provider(): array {
        return [
            'timeopen' => ['timeopen', 1000000000, true],
            'timeclose' => ['timeclose', 1000000000, true],
            'timelimit' => ['timelimit', 1800, true],
            'attempts' => ['attempts', 3, false],
            'password' => ['password', 'secret', false],
        ];
    }

    /**
     * A quiz_overrides row with every value column null does not count as an override.
     *
     * Only non-null VALUE_COLUMNS are treated as overridden settings, so a null-only row
     * leaves the student's map entry as false.
     */
    public function test_get_override_map_all_null_row_does_not_count_as_override(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertFalse($map[$student1->id]);
    }

    /**
     * A student who belongs to an overridden group is flagged with
     * hasgroupoverride (not hasuseroverride), and hastimeoverride fires
     * from the group override.
     */
    public function test_get_override_map_detects_group_override_for_member(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $quiz, , $student1, $student2] = $this->create_quiz_with_students();

        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student1->id]);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'groupid' => $group->id,
            'timeclose' => time() + 3600,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id, $student2->id]);

        $this->assertFalse($map[$student1->id]->hasuseroverride);
        $this->assertTrue($map[$student1->id]->hasgroupoverride);
        $this->assertTrue($map[$student1->id]->hastimeoverride);

        // Student2 is not in the group, so is unaffected.
        $this->assertFalse($map[$student2->id]);
    }

    /**
     * A student in a DIFFERENT (non-overridden) group is not flagged -
     * confirms group membership lookup is scoped to the correct group,
     * not just "any group member".
     */
    public function test_get_override_map_ignores_other_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $quiz, , $student1, $student2] = $this->create_quiz_with_students();

        $overriddengroup = $generator->create_group(['courseid' => $course->id]);
        $plaingroup = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $overriddengroup->id, 'userid' => $student1->id]);
        $generator->create_group_member(['groupid' => $plaingroup->id, 'userid' => $student2->id]);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'groupid' => $overriddengroup->id,
            'attempts' => 5,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id, $student2->id]);

        $this->assertTrue($map[$student1->id]->hasgroupoverride);
        $this->assertFalse($map[$student2->id]);
    }

    /**
     * A user override replaces a group override that sets the same settings.
     *
     * The group override is inserted first, so the result must not depend on row order.
     */
    public function test_get_override_map_user_override_takes_precedence_over_group(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student1->id]);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'groupid' => $group->id,
            'timelimit' => 1800,
        ]);
        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
            'timelimit' => 3600,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertTrue($map[$student1->id]->hasuseroverride);
        $this->assertFalse($map[$student1->id]->hasgroupoverride);
        $this->assertTrue($map[$student1->id]->hastimeoverride);
    }

    /**
     * A user override only takes precedence for the settings it overrides.
     *
     * A group time limit still applies when the user override sets only attempts.
     */
    public function test_get_override_map_group_applies_to_settings_not_in_user_override(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student1->id]);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'groupid' => $group->id,
            'timelimit' => 1800,
        ]);
        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
            'attempts' => 3,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertTrue($map[$student1->id]->hasuseroverride);
        $this->assertTrue($map[$student1->id]->hasgroupoverride);
        $this->assertTrue($map[$student1->id]->hastimeoverride);
        $this->assertFalse($map[$student1->id]->hasusertimeoverride);
        $this->assertTrue($map[$student1->id]->hasgrouptimeoverride);
    }

    /**
     * The time-override flag is set when only one of a student's groups overrides time.
     *
     * The non-time group override is inserted first, so the result must not depend on row order.
     */
    public function test_get_override_map_combines_settings_across_groups(): void {
        global $DB;

        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $attemptsgroup = $generator->create_group(['courseid' => $course->id]);
        $timegroup = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $attemptsgroup->id, 'userid' => $student1->id]);
        $generator->create_group_member(['groupid' => $timegroup->id, 'userid' => $student1->id]);

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'groupid' => $attemptsgroup->id,
            'attempts' => 3,
        ]);
        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'groupid' => $timegroup->id,
            'timelimit' => 1800,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertFalse($map[$student1->id]->hasuseroverride);
        $this->assertTrue($map[$student1->id]->hasgroupoverride);
        $this->assertTrue($map[$student1->id]->hastimeoverride);
    }

    /**
     * An override value of 0 (e.g. "no time limit") is a genuine override.
     *
     * Core stores null for settings that are not overridden, so only null is ignored.
     */
    public function test_get_override_map_zero_value_counts_as_override(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
            'timelimit' => 0,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertTrue($map[$student1->id]->hasuseroverride);
        $this->assertTrue($map[$student1->id]->hastimeoverride);
    }

    /**
     * Empty id list returns an empty (but defined) map.
     */
    public function test_get_override_map_empty_ids_returns_empty_map(): void {
        $this->resetAfterTest();
        [$course, $quiz] = $this->create_quiz_with_students();

        $this->assertSame([], overrides_manager::get_override_map((int) $quiz->id, []));
    }

    /**
     * No groups in the course at all: user overrides are still detected,
     * and nothing errors when there are no group memberships to look up.
     */
    public function test_get_override_map_no_groups_in_course(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, , $student1] = $this->create_quiz_with_students();

        $DB->insert_record('quiz_overrides', (object) [
            'quiz' => $quiz->id,
            'userid' => $student1->id,
            'timelimit' => 1800,
        ]);

        $map = overrides_manager::get_override_map((int) $quiz->id, [$student1->id]);

        $this->assertTrue($map[$student1->id]->hasuseroverride);
    }

    /**
     * Capability gate: only users with mod/quiz:viewoverrides or
     * mod/quiz:manageoverrides may view overrides.
     */
    public function test_user_can_view_overrides_respects_capability(): void {
        $this->resetAfterTest();
        [$course, $quiz, $cm, $student1] = $this->create_quiz_with_students();
        $context = context_module::instance($cm->id);

        $this->setUser($student1);
        $this->assertFalse(overrides_manager::user_can_view_overrides($context));

        $this->setAdminUser();
        $this->assertTrue(overrides_manager::user_can_view_overrides($context));
    }
}

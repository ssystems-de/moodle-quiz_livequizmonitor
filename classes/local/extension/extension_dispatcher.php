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
 * Dispatches the live quiz monitor extension hooks.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\extension;

use cm_info;
use quiz_livequizmonitor\hook\extend_columns;
use quiz_livequizmonitor\hook\extend_row_actions;
use quiz_livequizmonitor\hook\extend_row_highlights;
use stdClass;

/**
 * Runs the extension hook callbacks of other plugins and collects their results.
 */
class extension_dispatcher {
    /** @var string[] Student fields handed to the row hooks (see row_hook_base::get_students()). */
    protected const STUDENT_FIELDS = [
        'userid',
        'fullname',
        'email',
        'status',
        'attemptid',
        'attemptendat',
        'timeremaining',
        'progressanswered',
        'progresstotal',
    ];

    /**
     * Call every callback of a hook, each one isolated from the others.
     *
     * Core's \core\hook\manager::dispatch() does not catch exceptions, so one failing plugin
     * would stop all later callbacks and break the monitor. This loop reads the same callback
     * list (ordered by priority, admin overrides applied) and calls each callback on its own.
     * Before each call the hook learns the callback's component, so a failing callback's
     * contributions can be removed again and contributions are namespaced per plugin.
     *
     * @param object $hook Hook instance.
     */
    public static function dispatch_isolated(object $hook): void {
        $manager = \core\di::get(\core\hook\manager::class);
        $setcomponent = method_exists($hook, 'set_component');

        foreach ($manager->get_callbacks_for_hook(get_class($hook)) as $definition) {
            if (!empty($definition['disabled'])) {
                continue;
            }

            $callback = $definition['callback'];
            $component = $definition['component'];
            if (!is_callable($callback)) {
                debugging("quiz_livequizmonitor: hook callback {$callback} of {$component} is not callable", DEBUG_DEVELOPER);
                continue;
            }

            if ($setcomponent) {
                $hook->set_component($component);
            }
            try {
                call_user_func($callback, $hook);
            } catch (\Throwable $e) {
                // Drop everything the failing plugin added, keep the others.
                if (method_exists($hook, 'remove_contributions')) {
                    $hook->remove_contributions($component);
                }
                debugging(
                    "quiz_livequizmonitor: hook callback {$callback} of {$component} failed: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }

        if ($setcomponent) {
            $hook->set_component('');
        }
    }

    /**
     * Dispatch the column, highlight and action hooks for the monitor rows.
     *
     * @param stdClass $course Course record.
     * @param cm_info $cm Quiz course module.
     * @param stdClass $quiz Quiz record.
     * @param int $groupid Active group id.
     * @param stdClass[] $rows Student rows built by monitor_manager (only students the user may see).
     * @return stdClass {columns: array[], cells: array[], highlights: string[], actions: array[]},
     *     cells, highlights and actions indexed by user id.
     */
    public static function apply_row_hooks(
        stdClass $course,
        cm_info $cm,
        stdClass $quiz,
        int $groupid,
        array $rows
    ): stdClass {
        // Hand the hooks a reduced copy of each row, indexed by user id.
        $students = [];
        foreach ($rows as $row) {
            $student = new stdClass();
            foreach (self::STUDENT_FIELDS as $field) {
                $student->{$field} = $row->{$field} ?? null;
            }
            $students[(int) $row->userid] = $student;
        }

        $columns = new extend_columns($course, $cm, $quiz, $groupid, $students);
        self::dispatch_isolated($columns);

        $highlights = new extend_row_highlights($course, $cm, $quiz, $groupid, $students);
        self::dispatch_isolated($highlights);

        $actions = new extend_row_actions($course, $cm, $quiz, $groupid, $students);
        self::dispatch_isolated($actions);

        return (object) [
            'columns' => $columns->get_columns(),
            'cells' => $columns->get_cells(),
            'highlights' => $highlights->get_highlights(),
            'actions' => $actions->get_actions(),
        ];
    }
}

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
 * Extension hook callbacks used by the Behat tests.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\extension;

use core\output\pix_icon;
use moodle_url;
use quiz_livequizmonitor\hook\before_monitor_page_render;
use quiz_livequizmonitor\hook\extend_columns;
use quiz_livequizmonitor\hook\extend_row_actions;
use quiz_livequizmonitor\hook\extend_row_highlights;
use quiz_livequizmonitor\hook\row_hook_base;

/**
 * Test callbacks that act like a plugin extending the monitor.
 *
 * Registered in db/hooks.php on Behat sites only, and inactive until a scenario sets
 * the config flag quiz_livequizmonitor/behatextensionfixture. The callbacks also serve as
 * a working example of the hooks (see README).
 */
class behat_fixture {
    /**
     * Whether the fixture is switched on for the current scenario.
     *
     * @return bool
     */
    protected static function is_enabled(): bool {
        return defined('BEHAT_SITE_RUNNING') && get_config('quiz_livequizmonitor', 'behatextensionfixture');
    }

    /**
     * Students ordered by full name, so values are deterministic.
     *
     * @param row_hook_base $hook Row hook.
     * @return \stdClass[]
     */
    protected static function sorted_students(row_hook_base $hook): array {
        $students = array_values($hook->get_students());
        usort($students, static fn($a, $b): int => strcmp($a->fullname, $b->fullname));
        return $students;
    }

    /**
     * Add the column "Fixture value" with the student's position by name as value.
     *
     * @param extend_columns $hook Hook.
     */
    public static function extend_columns(extend_columns $hook): void {
        if (!self::is_enabled()) {
            return;
        }
        $hook->add_column('fixture', 'Fixture value');
        foreach (self::sorted_students($hook) as $index => $student) {
            $position = $index + 1;
            $hook->set_value('fixture', $student->userid, 'Value ' . $position, $position);
        }
    }

    /**
     * Highlight the first student by name as warning.
     *
     * @param extend_row_highlights $hook Hook.
     */
    public static function extend_row_highlights(extend_row_highlights $hook): void {
        if (!self::is_enabled()) {
            return;
        }
        $students = self::sorted_students($hook);
        if ($students) {
            $hook->set_highlight($students[0]->userid, extend_row_highlights::SEVERITY_WARNING);
        }
    }

    /**
     * Add a link to the profile of the first student by name, opening in a new tab.
     *
     * @param extend_row_actions $hook Hook.
     */
    public static function extend_row_actions(extend_row_actions $hook): void {
        if (!self::is_enabled()) {
            return;
        }
        $students = self::sorted_students($hook);
        if ($students) {
            $userid = $students[0]->userid;
            $hook->add_action(
                $userid,
                'Fixture action',
                new moodle_url('/user/profile.php', ['id' => $userid]),
                new pix_icon('i/info', ''),
                true
            );
        }
    }

    /**
     * Mark the page body once the monitor reports a refresh.
     *
     * @param before_monitor_page_render $hook Hook.
     */
    public static function before_monitor_page_render(before_monitor_page_render $hook): void {
        if (!self::is_enabled()) {
            return;
        }
        $hook->get_page()->requires->js_init_code(
            "document.querySelector('[data-region=\"live-quiz-monitor\"]')" .
            ".addEventListener('quiz_livequizmonitor:staterefreshed', function() {" .
            " document.body.dataset.lqmFixtureRefreshed = '1'; });"
        );
    }
}

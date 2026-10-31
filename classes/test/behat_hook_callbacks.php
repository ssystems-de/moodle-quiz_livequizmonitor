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

namespace quiz_livequizmonitor\test;

use core\output\pix_icon;
use moodle_url;
use quiz_livequizmonitor\hook\before_monitor_page_render;
use quiz_livequizmonitor\hook\extend_rows;

/**
 * Test callbacks that act like a plugin extending the monitor.
 *
 * Registered in db/hooks.php on Behat sites only, and inactive until a scenario sets
 * the config flag quiz_livequizmonitor/behatextensionfixture. The callbacks also serve as
 * a working example of the hooks (see README).
 */
class behat_hook_callbacks {
    /**
     * Whether the fixture is switched on for the current scenario.
     *
     * @return bool
     */
    protected static function is_enabled(): bool {
        return defined('BEHAT_SITE_RUNNING') && get_config('quiz_livequizmonitor', 'behatextensionfixture');
    }

    /**
     * Add the column "Fixture value" with the student's position by name as value, highlight the
     * first student by name as warning and add a link to their profile, opening in a new tab.
     *
     * @param extend_rows $hook Hook.
     */
    public static function extend_rows(extend_rows $hook): void {
        if (!self::is_enabled()) {
            return;
        }

        // Students ordered by full name, so values are deterministic.
        $students = array_values($hook->get_students());
        usort($students, static fn($a, $b): int => strcmp($a->fullname, $b->fullname));

        $hook->add_column('quiz_livequizmonitor-fixture', 'Fixture value');
        foreach ($students as $index => $student) {
            $position = $index + 1;
            $hook->set_value('quiz_livequizmonitor-fixture', $student->userid, 'Value ' . $position, $position);
        }

        if ($students) {
            $userid = $students[0]->userid;
            $hook->set_highlight($userid, extend_rows::SEVERITY_WARNING);
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

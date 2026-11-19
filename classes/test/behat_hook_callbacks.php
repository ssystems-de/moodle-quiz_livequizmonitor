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
use quiz_livequizmonitor\hook\declare_filters;
use quiz_livequizmonitor\hook\extend_rows;

/**
 * Test callbacks that act like a plugin extending the monitor.
 *
 * Registered in db/hooks.php on Behat sites only, and inactive until a scenario sets
 * the config flag quiz_livequizmonitor/behatextensionfixture. Setting the flag to 2 simulates
 * changed plugin data (see is_changed()). The callbacks also serve as a working example of
 * the hooks (see README).
 */
class behat_hook_callbacks {
    /** @var string Second component, to test filter groups of two plugins. */
    protected const SECOND_COMPONENT = 'local_fixturetwo';

    /**
     * Whether the fixture is switched on for the current scenario.
     *
     * @return bool
     */
    protected static function is_enabled(): bool {
        return defined('BEHAT_SITE_RUNNING') && get_config('quiz_livequizmonitor', 'behatextensionfixture');
    }

    /**
     * Whether the scenario changed the plugin data: then the filter "Odd position" matches every
     * student, the filter "No match" is not declared and the column shows "Changed N".
     *
     * @return bool
     */
    protected static function is_changed(): bool {
        return (int) get_config('quiz_livequizmonitor', 'behatextensionfixture') === 2;
    }

    /**
     * Declare the filter groups "Fixture filters" and "Second fixture".
     *
     * @param declare_filters $hook Hook.
     */
    public static function declare_filters(declare_filters $hook): void {
        if (!self::is_enabled()) {
            return;
        }

        $hook->add_filter_group('quiz_livequizmonitor', 'Fixture filters');
        $hook->add_filter('quiz_livequizmonitor-first', 'First student');
        $hook->add_filter('quiz_livequizmonitor-odd', 'Odd position', new pix_icon('i/flagged', ''));
        if (!self::is_changed()) {
            $hook->add_filter('quiz_livequizmonitor-none', 'No match');
        }

        $hook->add_filter_group(self::SECOND_COMPONENT, 'Second fixture');
        $hook->add_filter(self::SECOND_COMPONENT . '-even', 'Even position');
    }

    /**
     * Add the column "Fixture value" with the student's position by name as value, highlight the
     * first student by name as warning and add a link to their profile, opening in a new tab.
     * Mark the students matching the declared filters by their position.
     *
     * @param extend_rows $hook Hook.
     */
    public static function extend_rows(extend_rows $hook): void {
        if (!self::is_enabled()) {
            return;
        }

        // Students ordered by full name, so values are deterministic.
        $students = array_values($hook->get_student_rows());
        usort($students, static fn($a, $b): int => strcmp($a->fullname, $b->fullname));

        $hook->add_column('quiz_livequizmonitor-fixture', 'Fixture value');
        $text = self::is_changed() ? 'Changed ' : 'Value ';
        foreach ($students as $index => $student) {
            $position = $index + 1;
            $hook->set_value('quiz_livequizmonitor-fixture', $student->userid, $text . $position, $position);
        }

        // Only match filters that are declared for this quiz, as a plugin with per-quiz filters must.
        $filters = $hook->get_filters();
        $matches = [
            'quiz_livequizmonitor-first' => static fn(int $position): bool => $position === 1,
            'quiz_livequizmonitor-odd' => static fn(int $position): bool => self::is_changed() || $position % 2 === 1,
            self::SECOND_COMPONENT . '-even' => static fn(int $position): bool => $position % 2 === 0,
        ];
        foreach ($matches as $id => $matcher) {
            if (!isset($filters[$id])) {
                continue;
            }
            foreach ($students as $index => $student) {
                if ($matcher($index + 1)) {
                    $hook->add_filter_match($id, $student->userid);
                }
            }
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

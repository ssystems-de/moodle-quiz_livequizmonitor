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
 * Theme renderer override used by the renderer unit test.
 *
 * Only loaded by renderer_test in a separate process, so the class never leaks into other tests.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_boost\output;

/**
 * Boost theme override of the live quiz monitor renderer.
 */
class quiz_livequizmonitor_renderer extends \quiz_livequizmonitor\output\renderer {
    /** @var string Output returned instead of the monitor page. */
    public const MARKER = 'quiz_livequizmonitor theme override';

    /**
     * Render the monitor page.
     *
     * @param \quiz_livequizmonitor\output\monitor_report $report The monitor page.
     * @return string The marker.
     */
    public function render_monitor_report(\quiz_livequizmonitor\output\monitor_report $report): string {
        return self::MARKER;
    }
}

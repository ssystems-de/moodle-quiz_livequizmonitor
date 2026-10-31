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
 * Renderer for the live quiz monitor report.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\output;

use plugin_renderer_base;
use quiz_livequizmonitor\hook\before_monitor_page_render;
use quiz_livequizmonitor\local\extension\extension_dispatcher;

/**
 * Main renderer of the live quiz monitor report.
 *
 * This is the single place where the monitor page is rendered. Themes can change the
 * page by overriding render_monitor_report() in
 * theme_<name>\output\quiz_livequizmonitor_renderer.
 *
 * It is also where other plugins get the chance to add their own JavaScript to the page
 * (hook \quiz_livequizmonitor\hook\before_monitor_page_render).
 */
class renderer extends plugin_renderer_base {
    /**
     * Render the monitor page.
     *
     * @param monitor_report $report The monitor page.
     * @return string HTML.
     */
    public function render_monitor_report(monitor_report $report): string {
        // Let other plugins load their scripts; a failing plugin cannot break the page.
        extension_dispatcher::dispatch_isolated(new before_monitor_page_render($report, $this->page));

        return $this->render_from_template('quiz_livequizmonitor/monitor_page', $report->export_for_template($this));
    }
}

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
 * Hook dispatched before the live quiz monitor page is rendered.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

use cm_info;
use moodle_page;
use quiz_livequizmonitor\output\monitor_report;
use stdClass;

/**
 * Dispatched once from renderer::render_monitor_report(), before the page template is rendered.
 *
 * Plugins use it to load their own JavaScript on the monitor page, e.g. with
 * $hook->get_page()->requires->js_call_amd(). It is not dispatched when the monitor page is
 * not rendered (e.g. when the user is not in any group).
 */
final class before_monitor_page_render {
    /** @var monitor_report The monitor page about to be rendered. */
    protected $report;

    /** @var moodle_page The page the monitor is rendered on. */
    protected $page;

    /**
     * Constructor.
     *
     * @param monitor_report $report The monitor page about to be rendered.
     * @param moodle_page $page The page the monitor is rendered on.
     */
    public function __construct(monitor_report $report, moodle_page $page) {
        $this->report = $report;
        $this->page = $page;
    }

    /**
     * Course of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_course(): stdClass {
        return $this->report->get_course();
    }

    /**
     * Course module of the monitored quiz.
     *
     * @return cm_info
     */
    public function get_cm(): cm_info {
        return $this->report->get_cm();
    }

    /**
     * Record of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_quiz(): stdClass {
        return $this->report->get_quiz();
    }

    /**
     * Active group of the monitor (0 = all groups).
     *
     * @return int
     */
    public function get_groupid(): int {
        return $this->report->get_groupid();
    }

    /**
     * Copy of the monitor state that the page is rendered from.
     *
     * @return stdClass
     */
    public function get_state(): stdClass {
        // Deep copy, so callbacks cannot change what the page renders.
        return unserialize(serialize($this->report->get_state()));
    }

    /**
     * The page the monitor is rendered on, e.g. to add JavaScript.
     *
     * @return moodle_page
     */
    public function get_page(): moodle_page {
        return $this->page;
    }
}

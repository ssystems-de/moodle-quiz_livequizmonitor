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
 * Live quiz monitor report.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use quiz_livequizmonitor\local\manager\monitor_manager;
use quiz_livequizmonitor\local\manager\supervision_scope_manager;
use quiz_livequizmonitor\output\monitor_report;

/**
 * Live monitor quiz report.
 */
class quiz_livequizmonitor_report extends mod_quiz\local\reports\report_base {
    /**
     * Display the live monitor report.
     *
     * @param stdClass $quiz Quiz record.
     * @param stdClass $cm Course module record.
     * @param stdClass $course Course record.
     * @return bool
     */
    public function display($quiz, $cm, $course): bool {
        global $OUTPUT, $PAGE;

        $context = context_module::instance($cm->id);
        require_capability('quiz/livequizmonitor:view', $context);

        $groupid = supervision_scope_manager::resolve_group(0, $cm, $context);

        $this->print_header_and_tabs($cm, $course, $quiz, 'livequizmonitor');

        if ($groupid === supervision_scope_manager::NO_GROUPS_ALLOWED) {
            echo $OUTPUT->notification(get_string('notingroup'), \core\output\notification::NOTIFY_INFO);
            return true;
        }

        $state = monitor_manager::get_state($course, $cm, $quiz, $groupid);

        // Standard Moodle group selector. This is an empty string when the quiz
        // has no group mode, so the toolbar simply omits it in that case.
        $groupmenu = (string) groups_print_activity_menu($cm, $PAGE->url->out(false), true);

        // Render through the plugin renderer so that themes can override the page.
        $report = new monitor_report($state, $groupid, $groupmenu);
        echo $PAGE->get_renderer('quiz_livequizmonitor')->render($report);

        return true;
    }
}

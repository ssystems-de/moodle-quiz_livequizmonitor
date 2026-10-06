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
 * External API for polling live monitor state.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_multiple_structure;
use quiz_livequizmonitor\local\manager\monitor_manager;
use quiz_livequizmonitor\local\manager\supervision_scope_manager;

/**
 * Returns monitor state for AJAX polling.
 */
class get_monitor_state extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'groupid' => new external_value(PARAM_INT, 'Group id filter', VALUE_DEFAULT, 0),
            'sortcolumn' => new external_value(PARAM_ALPHANUM, 'Sort column name', VALUE_DEFAULT, 'status'),
            'sortdirection' => new external_value(PARAM_ALPHA, 'Sort direction', VALUE_DEFAULT, 'asc'),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param int $cmid Course module id.
     * @param int $groupid Group id.
     * @param string $sortcolumn Sort column name.
     * @param string $sortdirection Sort direction.
     * @return array
     */
    public static function execute(
        int $cmid,
        int $groupid = 0,
        string $sortcolumn = 'status',
        string $sortdirection = 'asc'
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'groupid' => $groupid,
            'sortcolumn' => $sortcolumn,
            'sortdirection' => $sortdirection,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('quiz/livequizmonitor:view', $context);

        supervision_scope_manager::validate_group_access((int) $params['groupid'], $cm);

        $state = monitor_manager::get_state(
            $course,
            $cm,
            $quiz,
            (int) $params['groupid'],
            (string) $params['sortcolumn'],
            (string) $params['sortdirection']
        );

        return self::export_state($state);
    }

    /**
     * Return structure description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return self::state_structure();
    }

    /**
     * External structure for monitor state.
     *
     * @return external_single_structure
     */
    protected static function state_structure(): external_single_structure {
        $statuscount = new external_single_structure([
            'count' => new external_value(PARAM_INT, 'Count'),
            'percent' => new external_value(PARAM_INT, 'Percent'),
            'label' => new external_value(PARAM_TEXT, 'Label'),
            'statusclass' => new external_value(PARAM_TEXT, 'CSS class'),
        ]);

        $student = new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User id'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'firstinitial' => new external_value(PARAM_TEXT, 'Initial of first name'),
            'lastinitial' => new external_value(PARAM_TEXT, 'Initial of last name'),
            'email' => new external_value(PARAM_TEXT, 'Email'),
            'showemail' => new external_value(PARAM_BOOL, 'Show email'),
            'status' => new external_value(PARAM_ALPHA, 'Status'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Status label'),
            'statusclass' => new external_value(PARAM_TEXT, 'Bootstrap badge CSS classes'),
            'attemptid' => new external_value(PARAM_INT, 'Attempt id', VALUE_OPTIONAL),
            'progressanswered' => new external_value(PARAM_INT, 'Answered count'),
            'progresstotal' => new external_value(PARAM_INT, 'Total questions'),
            'progresstext' => new external_value(PARAM_TEXT, 'Progress text'),
            'progresspercent' => new external_value(PARAM_INT, 'Progress bar fill 0-100'),
            'progressbarclass' => new external_value(PARAM_TEXT, 'Bootstrap progress-bar CSS class'),
            'timeremaining' => new external_value(PARAM_INT, 'Seconds remaining', VALUE_OPTIONAL),
            'timeremainingdisplay' => new external_value(PARAM_TEXT, 'Formatted time remaining'),
            'hastimer' => new external_value(PARAM_BOOL, 'Has countdown timer'),
            'searchtext' => new external_value(PARAM_TEXT, 'Lowercase search haystack'),
            'attemptendat' => new external_value(PARAM_INT, 'Attempt deadline timestamp', VALUE_OPTIONAL),
            'canextend' => new external_value(PARAM_BOOL, 'Viewer may extend time'),
            'hasnote' => new external_value(PARAM_BOOL, 'Student has a saved note'),
            'hasuseroverride' => new external_value(PARAM_BOOL, 'Student has a user override'),
            'hasusertimeoverride' => new external_value(PARAM_BOOL, 'Student has a time-related user override'),
            'hasgroupoverride' => new external_value(PARAM_BOOL, 'Student belongs to a group with an override'),
            'hasgrouptimeoverride' => new external_value(PARAM_BOOL, 'Student belongs to a group with a time-related override'),
            'hastimeoverride' => new external_value(PARAM_BOOL, 'Student has a time-related override, either user or group'),
            'timeoverrideflaglabel' => new external_value(PARAM_TEXT, 'Tooltip for the time override badge'),
            'isblocked' => new external_value(PARAM_BOOL, 'Student blocked by onesession'),
            'unblockactionenabled' => new external_value(PARAM_BOOL, 'Unblock action enabled for viewer'),
        ]);

        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'CM id'),
            'quizid' => new external_value(PARAM_INT, 'Quiz id'),
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
            'quizpassword' => new external_value(PARAM_RAW, 'Quiz password'),
            'updatedat' => new external_value(PARAM_INT, 'Updated timestamp'),
            'totalstudents' => new external_value(PARAM_INT, 'Total students'),
            'hasstudents' => new external_value(PARAM_BOOL, 'Has students'),
            'canextend' => new external_value(PARAM_BOOL, 'Viewer may extend time'),
            'inprogresscount' => new external_value(PARAM_INT, 'In-progress student count'),
            'idlecount' => new external_value(PARAM_INT, 'Idle student count'),
            'onesessionactive' => new external_value(PARAM_BOOL, 'Onesession rule active for quiz'),
            'canunblock' => new external_value(PARAM_BOOL, 'Viewer may unblock attempts'),
            'canviewattempts' => new external_value(PARAM_BOOL, 'Viewer may view student attempts'),
            'canviewlogs' => new external_value(PARAM_BOOL, 'Viewer may view student logs'),
            'canviewoverrides' => new external_value(PARAM_BOOL, 'Viewer may see override information'),
            'useroverridecount' => new external_value(PARAM_INT, 'Students with a user override'),
            'groupoverridecount' => new external_value(PARAM_INT, 'Students with a (relevant) group override'),
            'summary' => new external_single_structure([
                'notstarted' => $statuscount,
                'idle' => $statuscount,
                'inprogress' => $statuscount,
                'completed' => $statuscount,
            ]),
            'students' => new external_multiple_structure($student),
            'sortcolumn' => new external_value(PARAM_ALPHANUM, 'Sort column'),
            'sortdirection' => new external_value(PARAM_ALPHA, 'Sort direction'),
        ]);
    }

    /**
     * Convert manager state object to plain array for external API.
     *
     * @param \stdClass $state Monitor state.
     * @return array
     */
    protected static function export_state(\stdClass $state): array {
        $students = [];
        foreach ($state->students as $row) {
            $entry = [
                'userid' => $row->userid,
                'fullname' => $row->fullname,
                'firstinitial' => $row->firstinitial,
                'lastinitial' => $row->lastinitial,
                'email' => $row->email,
                'showemail' => (bool) $row->showemail,
                'status' => $row->status,
                'statuslabel' => $row->statuslabel,
                'statusclass' => $row->statusclass,
                'progressanswered' => $row->progressanswered,
                'progresstotal' => $row->progresstotal,
                'progresstext' => $row->progresstext,
                'progresspercent' => $row->progresspercent,
                'progressbarclass' => $row->progressbarclass,
                'timeremainingdisplay' => $row->timeremainingdisplay,
                'hastimer' => (bool) $row->hastimer,
                'searchtext' => $row->searchtext,
                'canextend' => (bool) $row->canextend,
                'hasnote' => (bool) ($row->hasnote ?? false),
                'hasuseroverride' => (bool) ($row->hasuseroverride ?? false),
                'hasusertimeoverride' => (bool) ($row->hasusertimeoverride ?? false),
                'hasgroupoverride' => (bool) ($row->hasgroupoverride ?? false),
                'hasgrouptimeoverride' => (bool) ($row->hasgrouptimeoverride ?? false),
                'hastimeoverride' => (bool) ($row->hastimeoverride ?? false),
                'timeoverrideflaglabel' => (string) ($row->timeoverrideflaglabel ?? ''),
                'isblocked' => (bool) ($row->isblocked ?? false),
                'unblockactionenabled' => (bool) ($row->unblockactionenabled ?? false),
            ];
            if ($row->attemptid !== null) {
                $entry['attemptid'] = $row->attemptid;
            }
            if ($row->timeremaining !== null) {
                $entry['timeremaining'] = (int) $row->timeremaining;
            }
            if ($row->attemptendat !== null) {
                $entry['attemptendat'] = (int) $row->attemptendat;
            }
            $students[] = $entry;
        }

        $summary = $state->summary;
        return [
            'cmid' => $state->cmid,
            'quizid' => $state->quizid,
            'quizname' => $state->quizname,
            'quizpassword' => $state->quizpassword,
            'updatedat' => $state->updatedat,
            'totalstudents' => $state->totalstudents,
            'hasstudents' => (bool) $state->hasstudents,
            'canextend' => (bool) ($state->canextend ?? false),
            'inprogresscount' => (int) ($state->inprogresscount ?? $summary->inprogress->count),
            'idlecount' => (int) ($state->idlecount ?? $summary->idle->count),
            'onesessionactive' => (bool) ($state->onesessionactive ?? false),
            'canunblock' => (bool) ($state->canunblock ?? false),
            'canviewattempts' => (bool) ($state->canviewattempts ?? false),
            'canviewlogs' => (bool) ($state->canviewlogs ?? false),
            'canviewoverrides' => (bool) ($state->canviewoverrides ?? false),
            'useroverridecount' => (int) ($state->useroverridecount ?? 0),
            'groupoverridecount' => (int) ($state->groupoverridecount ?? 0),
            'summary' => [
                'notstarted' => (array) $summary->notstarted,
                'idle' => (array) $summary->idle,
                'inprogress' => (array) $summary->inprogress,
                'completed' => (array) $summary->completed,
            ],
            'students' => $students,
            'sortcolumn' => $state->sortcolumn,
            'sortdirection' => $state->sortdirection,
        ];
    }
}

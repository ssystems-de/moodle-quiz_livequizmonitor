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
 * External API for extending quiz attempt time.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;
use moodle_exception;
use quiz_livequizmonitor\local\manager\extend_time_manager;
use quiz_livequizmonitor\local\manager\supervision_scope_manager;

/**
 * Extends quiz time for individual or bulk in-progress students.
 */
class extend_quiz_time extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'groupid' => new external_value(PARAM_INT, 'Group id filter', VALUE_DEFAULT, 0),
            'minutes' => new external_value(PARAM_INT, 'Extension duration in minutes'),
            'scope' => new external_value(PARAM_ALPHA, 'individual or bulk'),
            'userid' => new external_value(PARAM_INT, 'Target user id for individual scope', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param int $cmid Course module id.
     * @param int $groupid Group id.
     * @param int $minutes Minutes to add.
     * @param string $scope individual|bulk
     * @param int $userid Target user for individual scope.
     * @return array
     */
    public static function execute(
        int $cmid,
        int $groupid = 0,
        int $minutes = 0,
        string $scope = '',
        int $userid = 0
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'groupid' => $groupid,
            'minutes' => $minutes,
            'scope' => $scope,
            'userid' => $userid,
        ]);

        if (!in_array($params['scope'], [extend_time_manager::SCOPE_INDIVIDUAL, extend_time_manager::SCOPE_BULK], true)) {
            throw new invalid_parameter_exception('Invalid scope');
        }

        // Minutes range is validated once, in extend_time_manager::extend_quiz_time(), so it
        // is not duplicated here. A structurally invalid value (e.g. 0 or negative) still
        // surfaces to the caller, just as a moodle_exception rather than an API-parameter one.

        if ($params['scope'] === extend_time_manager::SCOPE_INDIVIDUAL && (int) $params['userid'] <= 0) {
            throw new invalid_parameter_exception('Missing userid for individual scope');
        }

        if ($params['scope'] === extend_time_manager::SCOPE_BULK && (int) $params['userid'] !== 0) {
            throw new invalid_parameter_exception('userid must be 0 for bulk scope');
        }

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $course = get_course($cm->course);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('quiz/livequizmonitor:view', $context);
        require_capability('mod/quiz:manageoverrides', $context);

        $groupid = supervision_scope_manager::resolve_group((int) $params['groupid'], $cm, $context);

        if ($params['scope'] === extend_time_manager::SCOPE_INDIVIDUAL) {
            if (
                !supervision_scope_manager::is_user_in_cohort(
                    (int) $params['userid'],
                    $context,
                    $groupid,
                    $cm
                )
            ) {
                throw new moodle_exception('error:usernotvisible', 'quiz_livequizmonitor');
            }
        }

        $outcome = extend_time_manager::extend_quiz_time(
            $course,
            $cm,
            $quiz,
            $groupid,
            (int) $params['minutes'],
            $params['scope'],
            (int) $params['userid']
        );

        return [
            'extendedcount' => (int) $outcome->extendedcount,
            'minutes' => (int) $outcome->minutes,
            'scope' => $outcome->scope,
            'usernames' => array_values($outcome->usernames),
            'warnings' => array_values($outcome->warnings),
        ];
    }

    /**
     * Return structure description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'extendedcount' => new external_value(PARAM_INT, 'Number of attempts extended'),
            'minutes' => new external_value(PARAM_INT, 'Minutes applied'),
            'scope' => new external_value(PARAM_ALPHA, 'Scope'),
            'usernames' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Extended user name')
            ),
            'warnings' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Warning message')
            ),
        ]);
    }
}

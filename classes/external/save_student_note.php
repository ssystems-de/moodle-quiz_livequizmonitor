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
 * External API for saving a student supervision note.
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
use invalid_parameter_exception;
use moodle_exception;
use quiz_livequizmonitor\local\manager\student_note_manager;

/**
 * Saves or deletes a student supervision note.
 */
class save_student_note extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'groupid' => new external_value(PARAM_INT, 'Group id filter', VALUE_DEFAULT, 0),
            'userid' => new external_value(PARAM_INT, 'Target student user id'),
            'content' => new external_value(PARAM_RAW, 'Note content'),
        ]);
    }

    /**
     * Execute the external function.
     *
     * @param int $cmid Course module id.
     * @param int $groupid Group id.
     * @param int $userid Target student user id.
     * @param string $content Note content.
     * @return array
     */
    public static function execute(int $cmid, int $groupid = 0, int $userid = 0, string $content = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'groupid' => $groupid,
            'userid' => $userid,
            'content' => $content,
        ]);

        if ((int) $params['userid'] <= 0) {
            throw new invalid_parameter_exception('Invalid userid');
        }

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('quiz/livequizmonitor:view', $context);
        require_capability('quiz/livequizmonitor:managenotes', $context);

        if (!student_note_manager::is_user_in_cohort((int) $params['userid'], $context, (int) $params['groupid'], $cm)) {
            throw new moodle_exception('error:usernotvisible', 'quiz_livequizmonitor');
        }

        $outcome = student_note_manager::save_note(
            (int) $quiz->id,
            (int) $params['userid'],
            (int) $USER->id,
            $params['content']
        );

        return [
            'hasnote' => (bool) $outcome->hasnote,
            'content' => $outcome->content,
        ];
    }

    /**
     * Return structure description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hasnote' => new external_value(PARAM_BOOL, 'Whether a non-empty note exists'),
            'content' => new external_value(PARAM_RAW, 'Saved content'),
        ]);
    }
}

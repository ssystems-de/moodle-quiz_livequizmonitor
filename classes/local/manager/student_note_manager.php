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
 * CRUD operations for student supervision notes.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use cm_info;
use context_module;
use moodle_exception;
use stdClass;

/**
 * Manages {quiz_livequizmonitor_notes} records.
 */
class student_note_manager {
    /** @var int Maximum note length in characters. */
    public const MAX_LENGTH = 2000;

    /**
     * Whether the viewer may add, edit and delete notes for this quiz.
     *
     * @param context_module $context Module context.
     * @return bool
     */
    public static function user_can_manage_notes(context_module $context): bool {
        return has_capability('quiz/livequizmonitor:view', $context)
            && has_capability('quiz/livequizmonitor:managenotes', $context);
    }

    /**
     * Load has-note flags for a set of students in one quiz.
     *
     * @param int $quizid Quiz instance id.
     * @param int[] $userids Student user ids.
     * @return array<int, bool> Map userid => has non-empty note.
     */
    public static function get_hasnote_map(int $quizid, array $userids): array {
        global $DB;

        $map = array_fill_keys($userids, false);
        if ($userids === []) {
            return $map;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $params['quizid'] = $quizid;

        $records = $DB->get_records_select(
            'quiz_livequizmonitor_notes',
            "quizid = :quizid AND userid $insql",
            $params,
            '',
            'userid, content'
        );

        foreach ($records as $record) {
            $map[(int) $record->userid] = trim($record->content) !== '';
        }

        return $map;
    }

    /**
     * Fetch a note for one student, or null when none exists.
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid Student user id.
     * @return stdClass|false
     */
    public static function get_note(int $quizid, int $userid): stdClass|false {
        global $DB;

        return $DB->get_record('quiz_livequizmonitor_notes', [
            'quizid' => $quizid,
            'userid' => $userid,
        ]);
    }

    /**
     * Save or delete a note (empty content removes the row).
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid Target student user id.
     * @param int $editorid Current user id performing the save.
     * @param string $content Raw note content.
     * @return stdClass Outcome with hasnote and content.
     */
    public static function save_note(int $quizid, int $userid, int $editorid, string $content): stdClass {
        global $DB;

        $trimmed = trim($content);
        $existing = $DB->get_record('quiz_livequizmonitor_notes', [
            'quizid' => $quizid,
            'userid' => $userid,
        ]);

        if ($trimmed === '') {
            if ($existing) {
                $DB->delete_records('quiz_livequizmonitor_notes', ['id' => $existing->id]);
            }
            return (object) [
                'hasnote' => false,
                'content' => '',
            ];
        }

        if (\core_text::strlen($trimmed) > self::MAX_LENGTH) {
            throw new moodle_exception('notes:errortoolong', 'quiz_livequizmonitor');
        }

        $now = time();
        if ($existing) {
            $existing->content = $trimmed;
            $existing->timemodified = $now;
            $existing->usermodified = $editorid;
            $DB->update_record('quiz_livequizmonitor_notes', $existing);
        } else {
            $record = (object) [
                'quizid' => $quizid,
                'userid' => $userid,
                'content' => $trimmed,
                'timemodified' => $now,
                'usermodified' => $editorid,
            ];
            $DB->insert_record('quiz_livequizmonitor_notes', $record);
        }

        return (object) [
            'hasnote' => true,
            'content' => $trimmed,
        ];
    }

    /**
     * Check whether a user is in the obliged cohort visible to the teacher.
     *
     * @param int $userid Target student user id.
     * @param context_module $context Module context.
     * @param int $groupid Active group id.
     * @param cm_info|stdClass $cm Course module.
     * @return bool
     */
    public static function is_user_in_cohort(int $userid, context_module $context, int $groupid, cm_info|stdClass $cm): bool {
        return supervision_scope_manager::is_user_in_cohort($userid, $context, $groupid, $cm);
    }
}

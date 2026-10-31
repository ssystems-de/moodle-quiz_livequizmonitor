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
 * Privacy provider for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\user_preference_provider;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use quiz_livequizmonitor\local\column_helper;
use stdClass;

/**
 * Privacy provider for student supervision notes and the hide/show column preference.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    user_preference_provider {
    /**
     * Describe stored personal data.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('quiz_livequizmonitor_notes', [
            'userid' => 'privacy:metadata:notes:userid',
            'content' => 'privacy:metadata:notes:content',
            'timemodified' => 'privacy:metadata:notes:timemodified',
            'usermodified' => 'privacy:metadata:notes:usermodified',
        ], 'privacy:metadata:notes');

        $collection->add_user_preference(
            column_helper::PREFERENCE_NAME,
            'privacy:metadata:preference:hiddencolumns'
        );
        $collection->add_user_preference(
            column_helper::SHOWN_PREFERENCE_NAME,
            'privacy:metadata:preference:showncolumns'
        );

        return $collection;
    }

    /**
     * Export the live monitor hide/show column preferences for a user.
     *
     * @param int $userid User id.
     */
    public static function export_user_preferences(int $userid): void {
        $preferences = [
            column_helper::PREFERENCE_NAME => 'privacy:metadata:preference:hiddencolumns_desc',
            column_helper::SHOWN_PREFERENCE_NAME => 'privacy:metadata:preference:showncolumns_desc',
        ];

        foreach ($preferences as $name => $descriptionkey) {
            $preference = get_user_preferences($name, null, $userid);
            if ($preference === null) {
                continue;
            }

            $columns = json_decode($preference, true);
            $summary = implode(', ', is_array($columns) ? $columns : []);

            writer::export_user_preference(
                'quiz_livequizmonitor',
                $name,
                $preference,
                get_string($descriptionkey, 'quiz_livequizmonitor', $summary)
            );
        }
    }

    /**
     * Find contexts containing user data.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :modulelevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {quiz} q ON q.id = cm.instance
                  JOIN {quiz_livequizmonitor_notes} n ON n.quizid = q.id
                 WHERE n.userid = :userid OR n.usermodified = :usermodified2";

        $params = [
            'modulelevel' => CONTEXT_MODULE,
            'modname' => 'quiz',
            'userid' => $userid,
            'usermodified2' => $userid,
        ];

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Export user data for a context.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if ($contextlist->count() === 0) {
            return;
        }

        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('quiz', $context->instanceid);
            $records = $DB->get_records_sql(
                "SELECT *
                   FROM {quiz_livequizmonitor_notes}
                  WHERE quizid = :quizid
                    AND (userid = :userid OR usermodified = :usermodified)",
                [
                    'quizid' => $cm->instance,
                    'userid' => $userid,
                    'usermodified' => $userid,
                ]
            );

            if (!$records) {
                continue;
            }

            $notesaboutuser = [];
            $notesauthored = [];

            foreach ($records as $record) {
                if ((int) $record->userid === $userid) {
                    $notesaboutuser[] = self::export_note_about_user($record);
                }
                if ((int) $record->usermodified === $userid && (int) $record->userid !== $userid) {
                    $notesauthored[] = self::export_note_authored_by_user($record);
                }
            }

            $export = new stdClass();
            if ($notesaboutuser !== []) {
                $export->notes_about_user = $notesaboutuser;
            }
            if ($notesauthored !== []) {
                $export->notes_authored_by_user = $notesauthored;
            }

            if ($notesaboutuser === [] && $notesauthored === []) {
                continue;
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'quiz_livequizmonitor')],
                $export
            );
        }
    }

    /**
     * Delete all data for all users in a context.
     *
     * @param \context $context Context.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $DB->delete_records('quiz_livequizmonitor_notes', ['quizid' => $cm->instance]);
    }

    /**
     * Delete data for one user in a context.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int) $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel !== CONTEXT_MODULE) {
                continue;
            }

            $cm = get_coursemodule_from_id('quiz', $context->instanceid);
            self::purge_user_notes_in_quiz((int) $cm->instance, $userid);
        }
    }

    /**
     * Find users with data in a context.
     *
     * @param userlist $userlist User list.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $sql = "SELECT userid AS userid
                  FROM {quiz_livequizmonitor_notes}
                 WHERE quizid = :quizid
                UNION
                SELECT usermodified AS userid
                  FROM {quiz_livequizmonitor_notes}
                 WHERE quizid = :quizid2
                   AND usermodified IS NOT NULL";

        $userlist->add_from_sql($sql, ['quizid' => $cm->instance, 'quizid2' => $cm->instance]);
    }

    /**
     * Delete data for users in a context.
     *
     * @param approved_userlist $userlist Approved user list.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid);
        $quizid = (int) $cm->instance;

        foreach ($userlist->get_userids() as $userid) {
            self::purge_user_notes_in_quiz($quizid, (int) $userid);
        }
    }

    /**
     * Remove or anonymise one user's note data within a quiz.
     *
     * Subject notes are deleted; author attribution is cleared on remaining rows.
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid User requesting erasure.
     */
    private static function purge_user_notes_in_quiz(int $quizid, int $userid): void {
        global $DB;

        $DB->delete_records('quiz_livequizmonitor_notes', [
            'quizid' => $quizid,
            'userid' => $userid,
        ]);

        $DB->set_field_select(
            'quiz_livequizmonitor_notes',
            'usermodified',
            null,
            'quizid = :quizid AND usermodified = :usermodified',
            ['quizid' => $quizid, 'usermodified' => $userid]
        );
    }

    /**
     * Build export item for notes where the user is the subject.
     *
     * @param stdClass $record Note row.
     * @return stdClass
     */
    private static function export_note_about_user(stdClass $record): stdClass {
        $item = (object) [
            'content' => $record->content,
            'timemodified' => transform::datetime($record->timemodified),
        ];

        $author = self::export_user_reference($record->usermodified);
        if ($author !== null) {
            $item->usermodified = $author;
        }

        return $item;
    }

    /**
     * Build export item for notes authored by the requesting user.
     *
     * @param stdClass $record Note row.
     * @return stdClass
     */
    private static function export_note_authored_by_user(stdClass $record): stdClass {
        return (object) [
            'content' => $record->content,
            'timemodified' => transform::datetime($record->timemodified),
            'subject_user' => transform::user($record->userid),
        ];
    }

    /**
     * Transform a user id for export, skipping empty author references.
     *
     * @param int|null $userid User id or null when author was erased.
     * @return mixed|null Transformed user data or null.
     */
    private static function export_user_reference(?int $userid) {
        if (empty($userid)) {
            return null;
        }

        return transform::user($userid);
    }
}

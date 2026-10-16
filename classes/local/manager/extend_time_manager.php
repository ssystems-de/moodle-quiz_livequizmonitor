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
 * Extends quiz attempt time for in-progress students via user overrides.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use cm_info;
use context_module;
use core\lock\lock_config;
use core\message\message;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use moodle_exception;
use stdClass;

/**
 * Applies relative time extensions through core quiz user overrides.
 */
class extend_time_manager {
    /** @var int Upper bound for a custom extension, in minutes. Passed to the modal via the monitor page. */
    public const MAX_CUSTOM_MINUTES = 180;

    /** @var string Individual extend scope. */
    public const SCOPE_INDIVIDUAL = 'individual';

    /** @var string Bulk extend scope. */
    public const SCOPE_BULK = 'bulk';

    /** @var string[] Overrideable quiz settings stored in quiz_overrides. */
    private const OVERRIDE_FIELDS = ['timeopen', 'timeclose', 'timelimit', 'attempts', 'password'];

    /** @var int Seconds to wait for the per-user extension lock. */
    private const LOCK_TIMEOUT = 10;

    /**
     * Whether the current user may extend quiz time on this monitor.
     *
     * @param context_module $context Module context.
     * @return bool
     */
    public static function user_can_extend(context_module $context): bool {
        return has_capability('quiz/livequizmonitor:view', $context)
            && has_capability('mod/quiz:manageoverrides', $context);
    }

    /**
     * Extend quiz time for one or all in-progress students.
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module.
     * @param stdClass $quiz Quiz record.
     * @param int $groupid Group filter for cohort resolution.
     * @param int $minutes Minutes to add.
     * @param string $scope individual|bulk
     * @param int $userid Target user for individual scope.
     * @return stdClass Outcome payload.
     */
    public static function extend_quiz_time(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $groupid,
        int $minutes,
        string $scope,
        int $userid = 0
    ): stdClass {
        global $USER;

        $context = context_module::instance($cm->id);
        if (!self::user_can_extend($context)) {
            throw new moodle_exception(
                'nopermissions',
                'error',
                '',
                get_string('extend:errornopermission', 'quiz_livequizmonitor')
            );
        }

        // A preset is just a convenient value within this same range, not a separate
        // category: any whole number of minutes from 1 up to MAX_CUSTOM_MINUTES is valid.
        if ($minutes <= 0 || $minutes > self::MAX_CUSTOM_MINUTES) {
            throw new moodle_exception('invalidminutes', 'quiz_livequizmonitor');
        }

        if ($scope === self::SCOPE_INDIVIDUAL) {
            if ($userid <= 0) {
                throw new moodle_exception('missinguserid', 'quiz_livequizmonitor');
            }
            $userids = [$userid];
        } else if ($scope === self::SCOPE_BULK) {
            $userids = self::get_extendable_userids($course, $cm, $quiz, $groupid);
        } else {
            throw new moodle_exception('invalidscope', 'quiz_livequizmonitor');
        }

        $outcome = (object) [
            'extendedcount' => 0,
            'minutes' => $minutes,
            'scope' => $scope,
            'usernames' => [],
            'warnings' => [],
        ];

        foreach ($userids as $targetuserid) {
            try {
                $username = self::extend_user($course, $cm, $quiz, $targetuserid, $minutes, $USER->id);
                $outcome->extendedcount++;
                $outcome->usernames[] = $username;
            } catch (moodle_exception $e) {
                $outcome->warnings[] = $e->getMessage();
            }
        }

        if ($scope === self::SCOPE_BULK && $outcome->extendedcount === 0 && empty($userids)) {
            $outcome->warnings[] = get_string('extend:errornoinprogress', 'quiz_livequizmonitor');
        }

        return $outcome;
    }

    /**
     * Resolve user ids eligible for a bulk time extension (in-progress or idle).
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module.
     * @param stdClass $quiz Quiz record.
     * @param int $groupid Group filter.
     * @return int[]
     */
    public static function get_extendable_userids(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $groupid
    ): array {
        $state = monitor_manager::get_state($course, $cm, $quiz, $groupid);
        $extendablestatus = [
            monitor_manager::STATUS_INPROGRESS,
            monitor_manager::STATUS_IDLE,
        ];
        $extendableusers = array_filter(
            $state->students,
            fn($student) => in_array($student->status, $extendablestatus)
        );
        return array_column($extendableusers, 'userid');
    }

    /**
     * Extend one user's active attempt.
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module.
     * @param stdClass $quiz Quiz record.
     * @param int $userid Target user id.
     * @param int $minutes Minutes to add.
     * @param int $actorid User performing the extension.
     * @return string Extended user's full name.
     */
    public static function extend_user(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $userid,
        int $minutes,
        int $actorid
    ): string {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $attempt = self::get_inprogress_attempt((int) $quiz->id, $userid);
        if ($attempt === null) {
            throw new moodle_exception('noattempttoextend', 'quiz_livequizmonitor', '', fullname($user));
        }

        // Serialise read-modify-write per user so concurrent extensions neither get lost
        // nor create duplicate overrides.
        $lockfactory = lock_config::get_lock_factory('quiz_livequizmonitor_extend');
        $lock = $lockfactory->get_lock("{$quiz->id}_{$userid}", self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new moodle_exception('locktimeout');
        }

        try {
            $accessmanager = quiz_attempt::create((int) $attempt->id)->get_access_manager(time());
            $endtime = $accessmanager->get_end_time($attempt);
            $values = $endtime === false ? [] : self::compute_extended_limits(
                quiz_settings::create((int) $quiz->id, $userid)->get_quiz(),
                (int) $attempt->timestart,
                (int) $endtime + $minutes * 60
            );
            if ($values === []) {
                throw new moodle_exception('noextendablelimit', 'quiz_livequizmonitor', '', fullname($user));
            }

            self::save_user_override((int) $quiz->id, $userid, $values);
        } finally {
            $lock->release();
        }

        self::notify_student($user, $quiz, $minutes, $actorid);

        return fullname($user);
    }

    /**
     * Get the active in-progress attempt for a user.
     *
     * @param int $quizid Quiz id.
     * @param int $userid User id.
     * @return stdClass|null
     */
    protected static function get_inprogress_attempt(int $quizid, int $userid): ?stdClass {
        global $DB;

        $attempts = $DB->get_records_select(
            'quiz_attempts',
            'quiz = :quizid AND userid = :userid AND preview = 0',
            ['quizid' => $quizid, 'userid' => $userid],
            'timestart DESC'
        );

        $relevant = monitor_manager::pick_relevant_attempt($attempts);
        if ($relevant === null) {
            return null;
        }
        if (!monitor_manager::is_active_attempt_state($relevant->state)) {
            return null;
        }
        return $relevant;
    }

    /**
     * Compute the override values needed so the attempt ends at the target time.
     *
     * Every limit that is set (time limit and/or close date) is raised to the target,
     * but never lowered. Limits already beyond the target are left untouched.
     *
     * @param stdClass $effectivequiz Quiz settings with the user's overrides applied.
     * @param int $timestart Attempt start time.
     * @param int $target Desired new attempt end time.
     * @return array<string, int> Changed override fields (timelimit and/or timeclose).
     */
    public static function compute_extended_limits(stdClass $effectivequiz, int $timestart, int $target): array {
        $values = [];
        if (!empty($effectivequiz->timelimit) && $timestart + (int) $effectivequiz->timelimit < $target) {
            $values['timelimit'] = $target - $timestart;
        }
        if (!empty($effectivequiz->timeclose) && (int) $effectivequiz->timeclose < $target) {
            $values['timeclose'] = $target;
        }
        return $values;
    }

    /**
     * Create or update the user's override through the core override manager.
     *
     * Existing override settings (open date, attempts, password, ...) are carried over,
     * because the override manager clears every setting that is not passed in.
     *
     * @param int $quizid Quiz id.
     * @param int $userid Target user id.
     * @param array $values Override fields to set (field => value).
     */
    protected static function save_user_override(int $quizid, int $userid, array $values): void {
        global $DB;

        // Base quiz settings (without overrides) so unchanged values are compared correctly.
        $manager = quiz_settings::create($quizid)->get_override_manager();
        $manager->require_manage_capability();

        $formdata = ['userid' => $userid];
        $override = $DB->get_record('quiz_overrides', ['quiz' => $quizid, 'userid' => $userid]);
        if ($override) {
            $formdata['id'] = (int) $override->id;
            foreach (self::OVERRIDE_FIELDS as $field) {
                if ($override->$field !== null) {
                    $formdata[$field] = $override->$field;
                }
            }
        }

        $manager->save_override(array_merge($formdata, $values));
    }

    /**
     * Notify a student that extra time was granted.
     *
     * @param stdClass $user Student user record.
     * @param stdClass $quiz Quiz record.
     * @param int $minutes Minutes added.
     * @param int $actorid Teacher user id.
     */
    protected static function notify_student(stdClass $user, stdClass $quiz, int $minutes, int $actorid): void {
        $eventdata = new message();
        $eventdata->component = 'quiz_livequizmonitor';
        $eventdata->name = 'timeextended';
        $eventdata->userfrom = \core_user::get_user($actorid);
        $eventdata->userto = $user;
        $eventdata->subject = get_string('message:timeextendedsubject', 'quiz_livequizmonitor', format_string($quiz->name));
        $eventdata->fullmessage = get_string('message:timeextendedbody', 'quiz_livequizmonitor', (object) [
            'quizname' => format_string($quiz->name),
            'minutes' => $minutes,
        ]);
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = '';
        $eventdata->smallmessage = get_string('message:timeextendedsmall', 'quiz_livequizmonitor', $minutes);
        $eventdata->notification = 1;

        message_send($eventdata);
    }
}

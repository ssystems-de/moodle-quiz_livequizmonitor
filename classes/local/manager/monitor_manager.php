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
 * Builds live monitor state from quiz enrolment and attempt data.
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
use core_availability\info_module;
use core_user\fields;
use mod_quiz\access_manager;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;
use quiz_livequizmonitor\local\column_helper;
use quiz_livequizmonitor\local\extension\extension_dispatcher;
use stdClass;

/**
 * Manager for cohort resolution, status mapping, and monitor payloads.
 */
class monitor_manager {
    /** @var string Monitor status: not started. */
    public const STATUS_NOTSTARTED = 'notstarted';

    /** @var string Monitor status: in progress. */
    public const STATUS_INPROGRESS = 'inprogress';

    /** @var string Monitor status: idle.
     *
     * Similar to "inprogress" but no user activity within the last 5 minutes.
     */
    public const STATUS_IDLE = 'idle';

    /** @var string Monitor status: completed. */
    public const STATUS_COMPLETED = 'completed';

    /** @var string[] Convenient array of in-progress and idle statuses. */
    public const INPROGRESS_OR_IDLE = [self::STATUS_INPROGRESS, self::STATUS_IDLE];

    /**
     * Quiz attempt state used on modified Moodle 4.5 and Moodle 5+.
     *
     * Stock Moodle 4.5 does not define {@see quiz_attempt::SUBMITTED}; use this
     * literal so we can match DB rows without referencing a missing constant.
     */
    public const QUIZ_ATTEMPT_SUBMITTED = 'submitted';

    /** @var int Idle threshold in seconds. 300 secs = 5 mins. */
    public const IDLE_THRESHOLD_SECONDS = 300;

    /**
     * Moodle attempt states that map to monitor "completed".
     *
     * @return string[]
     */
    public static function completed_attempt_states(): array {
        return [
            quiz_attempt::FINISHED,
            self::QUIZ_ATTEMPT_SUBMITTED,
        ];
    }

    /**
     * Moodle attempt states treated as active (in progress or overdue).
     *
     * @return string[]
     */
    public static function active_attempt_states(): array {
        return [
            quiz_attempt::IN_PROGRESS,
            quiz_attempt::OVERDUE,
        ];
    }

    /**
     * Whether a raw attempt state string is active.
     *
     * @param string $state Attempt state from quiz_attempts.state.
     * @return bool
     */
    public static function is_active_attempt_state(string $state): bool {
        return in_array($state, self::active_attempt_states(), true);
    }

    /**
     * Whether a raw attempt state string is completed.
     *
     * @param string $state Attempt state from quiz_attempts.state.
     * @return bool
     */
    public static function is_completed_attempt_state(string $state): bool {
        return in_array($state, self::completed_attempt_states(), true);
    }

    /**
     * Build the full monitor state for a quiz module.
     *
     * @param stdClass $course Course record.
     * @param cm_info|stdClass $cm Course module record or cached cm_info.
     * @param stdClass $quiz Quiz instance record.
     * @param int $groupid Requested group id (resolved via supervision_scope_manager::resolve_group()).
     * @param string $sortcolumn Column to sort by.
     * @param string $sortdirection Sort direction ('asc' or 'desc').
     * @param bool $withextensions Whether to dispatch the extension hooks; false for callers that do not display the state.
     * @return stdClass MonitorState payload.
     */
    public static function get_state(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $groupid = 0,
        string $sortcolumn = 'status',
        string $sortdirection = 'asc',
        bool $withextensions = true
    ): stdClass {
        global $DB;

        // Convert stdClass to cm_info early.
        if ($cm instanceof stdClass) {
            $cm = cm_info::create($cm);
        }

        $context = context_module::instance($cm->id);
        $now = time();

        $canviewattempts = has_capability('mod/quiz:viewreports', $context);

        // Can this user view log records in this context?
        $canviewlogs = has_any_capability(['report/log:view', 'report/log:viewtoday'], $context);

        // Resolve group for enrolment query (respect quiz report group mode).
        $groupid = supervision_scope_manager::resolve_group($groupid, $cm, $context);
        if ($groupid === supervision_scope_manager::NO_GROUPS_ALLOWED) {
            $students = [];
        } else {
            // Get the student list, honouring the activity's access restrictions.
            $students = self::get_allowed_students($cm, $context, $groupid);
        }

        $totalquestions = self::count_quiz_questions((int) $quiz->id);
        $showemail = has_capability('moodle/course:viewhiddenuserfields', $context);

        // Load all non-preview attempts for this quiz, newest first per user scan.
        $attempts = $DB->get_records_select(
            'quiz_attempts',
            'quiz = :quizid AND preview = 0',
            ['quizid' => $quiz->id],
            'timestart DESC'
        );

        $attemptsbyuser = [];
        foreach ($attempts as $attempt) {
            $attemptsbyuser[$attempt->userid][] = $attempt;
        }

        $relevantattempts = [];
        foreach ($students as $user) {
            $relevant = self::pick_relevant_attempt($attemptsbyuser[$user->id] ?? []);
            if ($relevant !== null) {
                $relevantattempts[(int) $user->id] = $relevant;
            }
        }

        // Load progress and access managers for all attempts at once instead of one quiz_attempt per student.
        $progressmap = self::load_attempt_progress($relevantattempts);
        $activeuserids = array_keys(array_filter(
            $relevantattempts,
            static fn(stdClass $attempt): bool => self::is_active_attempt_state($attempt->state)
        ));
        $accessmanagers = self::get_access_managers((int) $quiz->id, $activeuserids, $now);
        $canextend = extend_time_manager::user_can_extend($context);

        $rows = [];
        foreach ($students as $user) {
            $relevant = $relevantattempts[(int) $user->id] ?? null;
            $rows[] = self::build_student_row(
                $user,
                $relevant,
                $relevant ? ($progressmap[(int) $relevant->id] ?? null) : null,
                $accessmanagers[(int) $user->id] ?? null,
                $totalquestions,
                $quiz,
                $now,
                $showemail,
                $canextend
            );
        }

        // Let other plugins add columns, row highlights and row actions. This runs before the
        // sort, so rows can be sorted by extension columns like by built-in ones.
        $extensioncolumns = [];
        if ($withextensions) {
            $extensions = extension_dispatcher::apply_row_hooks($course, $cm, $quiz, $groupid, $rows);
            $extensioncolumns = $extensions->columns;
            foreach ($rows as $row) {
                $row->extensioncells = $extensions->cells[$row->userid] ?? [];
                $row->highlight = $extensions->highlights[$row->userid] ?? '';
                $row->extensionactions = $extensions->actions[$row->userid] ?? [];
            }
        }

        self::sort_student_rows($rows, $sortcolumn, $sortdirection);

        $userids = array_map(static fn(stdClass $row): int => (int) $row->userid, $rows);
        $hasnotemap = student_note_manager::get_hasnote_map((int) $quiz->id, $userids);
        foreach ($rows as $row) {
            $row->hasnote = !empty($hasnotemap[$row->userid]);
        }

        $nooverridecount = 0;
        $useroverridecount = 0;
        $groupoverridecount = 0;
        $canviewoverrides = overrides_manager::user_can_view_overrides($context);
        if ($canviewoverrides) {
            // Tooltips for the timer badge, depending on where the time override comes from.
            $timeoverridelabels = [
                'user' => get_string('filter:usertimeoverrideflag', 'quiz_livequizmonitor'),
                'group' => get_string('filter:grouptimeoverrideflag', 'quiz_livequizmonitor'),
                'userandgroup' => get_string('filter:userandgrouptimeoverrideflag', 'quiz_livequizmonitor'),
            ];
            // Fetch the override map for all students in the monitor.
            $overridemap = overrides_manager::get_override_map(
                (int) $quiz->id,
                $userids
            );
            foreach ($rows as $row) {
                // Set boolean flags for this row.
                $flags = $overridemap[$row->userid] ?? null;
                $row->hasuseroverride = $flags->hasuseroverride ?? false;
                $row->hasgroupoverride = $flags->hasgroupoverride ?? false;
                $row->hastimeoverride = $flags->hastimeoverride ?? false;
                $row->hasusertimeoverride = $flags->hasusertimeoverride ?? false;
                $row->hasgrouptimeoverride = $flags->hasgrouptimeoverride ?? false;

                // Set the timer badge tooltip for this row.
                if ($row->hasusertimeoverride && $row->hasgrouptimeoverride) {
                    $row->timeoverrideflaglabel = $timeoverridelabels['userandgroup'];
                } else if ($row->hasusertimeoverride) {
                    $row->timeoverrideflaglabel = $timeoverridelabels['user'];
                } else if ($row->hasgrouptimeoverride) {
                    $row->timeoverrideflaglabel = $timeoverridelabels['group'];
                } else {
                    $row->timeoverrideflaglabel = '';
                }

                // Update override counts, as necessary.
                $nooverride = true;
                if ($row->hasuseroverride) {
                    $useroverridecount++;
                    $nooverride = false;
                }
                if ($row->hasgroupoverride) {
                    $groupoverridecount++;
                    $nooverride = false;
                }
                if ($nooverride) {
                    $nooverridecount++;
                }
            }
        }

        $onesessionactive = onesession_manager::is_active_for_quiz((int) $quiz->id, $quiz);
        $canunblock = $onesessionactive && onesession_manager::user_can_unblock($context);

        $attemptids = [];
        foreach ($rows as $row) {
            if (in_array($row->status, self::INPROGRESS_OR_IDLE) && $row->attemptid !== null) {
                $attemptids[] = (int) $row->attemptid;
            }
        }
        $blockedmap = $onesessionactive ? onesession_manager::get_blocked_map($attemptids) : [];
        foreach ($rows as $row) {
            $row->isblocked = false;
            $row->unblockactionenabled = false;
            if ($onesessionactive && $row->attemptid !== null && !empty($blockedmap[(int) $row->attemptid])) {
                $row->isblocked = true;
                $row->unblockactionenabled = $canunblock;
            }
        }

        $summary = self::build_summary($rows, count($students));

        $state = (object) [
            'courseid' => (int) $course->id,
            'cmid' => (int) $cm->id,
            'quizid' => (int) $quiz->id,
            'quizname' => format_string($quiz->name, true, ['context' => $context]),
            'quizpassword' => $quiz->password,
            'updatedat' => $now,
            'totalstudents' => count($students),
            'summary' => $summary,
            'students' => $rows,
            'hasstudents' => count($students) > 0,
            'canextend' => extend_time_manager::user_can_extend($context),
            'inprogresscount' => $summary->inprogress->count,
            'idlecount' => $summary->idle->count,
            'onesessionactive' => $onesessionactive,
            'canunblock' => $canunblock,
            'canviewattempts' => $canviewattempts,
            'canviewlogs' => $canviewlogs,
            'sortcolumn' => $sortcolumn,
            'sortdirection' => $sortdirection,
            'canviewoverrides' => $canviewoverrides,
            'nooverridecount' => $nooverridecount,
            'useroverridecount' => $useroverridecount,
            'groupoverridecount' => $groupoverridecount,
            'extensioncolumns' => $extensioncolumns,
        ];

        return $state;
    }

    /**
     * Get the users who may attempt this quiz, honouring activity access restrictions.
     *
     * The availability filter is folded into the enrolment query via
     * info_module::get_user_list_sql(), so the roster costs one query and is never stale.
     *
     * Suspended enrolments are deliberately included: an invigilator still needs to see
     * a student whose enrolment changes while their attempt is in progress.
     *
     * @param cm_info|stdClass $cm Course module record.
     * @param context_module $context Module context.
     * @param int $groupid Active group id (0 = all groups).
     * @param int $userid Optional single user to test, 0 for the whole roster.
     * @return array User records keyed by user id.
     */
    public static function get_allowed_students(
        cm_info|stdClass $cm,
        context_module $context,
        int $groupid,
        int $userid = 0
    ): array {
        global $DB;

        $onlyactive = false;

        [$enrolledsql, $params] = get_enrolled_sql($context, 'mod/quiz:attempt', $groupid, $onlyactive);

        $userfields = fields::for_name()->including('id', 'email', 'username', 'idnumber');
        $fieldsql = $userfields->get_sql('u', false, '', '', false);
        $sql = "SELECT {$fieldsql->selects}
                FROM {user} u
                JOIN ($enrolledsql) e ON e.id = u.id
                WHERE u.deleted = 0";

        // Narrow to a single user when the caller only needs a membership test.
        if ($userid > 0) {
            $sql .= ' AND u.id = :targetuserid';
            $params['targetuserid'] = $userid;
        }

        // Fold the activity's access restrictions into the same query.
        if ($cm instanceof stdClass) {
            $cm = cm_info::create($cm);
        }
        $info = new info_module($cm);
        [$usersql, $userparams] = $info->get_user_list_sql($onlyactive);
        if ($usersql !== '') {
            $sql .= " AND u.id IN ($usersql)";
            $params = array_merge($params, $userparams);
        }

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Count question slots in the quiz.
     *
     * @param int $quizid Quiz id.
     * @return int
     */
    protected static function count_quiz_questions(int $quizid): int {
        global $DB;
        return (int) $DB->count_records('quiz_slots', ['quizid' => $quizid]);
    }

    /**
     * Load answered-question counts and last activity for many attempts with one query.
     *
     * Mirrors quiz_attempt: a question counts as answered when it is a real question
     * (length > 0) and the state of its latest step is neither todo nor invalid
     * (see quiz_attempt::get_number_of_unanswered_questions()). The last activity is the
     * newest latest-step time over all questions (see quiz_attempt::get_question_action_time()).
     * Autosaved steps have negative sequence numbers and are ignored, as in core.
     *
     * @param stdClass[] $attempts Attempt records.
     * @return stdClass[] Map attempt id => {answered: int, lastaction: int}.
     */
    protected static function load_attempt_progress(array $attempts): array {
        global $DB;

        $attemptidbyusage = [];
        foreach ($attempts as $attempt) {
            $attemptidbyusage[(int) $attempt->uniqueid] = (int) $attempt->id;
        }
        if ($attemptidbyusage === []) {
            return [];
        }

        [$usagesql, $params] = $DB->get_in_or_equal(array_keys($attemptidbyusage), SQL_PARAMS_NAMED, 'usage');
        $params['todo'] = 'todo';
        $params['invalid'] = 'invalid';

        $sql = "SELECT qa.questionusageid,
                       SUM(CASE WHEN q.length > 0 AND qas.state NOT IN (:todo, :invalid) THEN 1 ELSE 0 END) AS answered,
                       MAX(qas.timecreated) AS lastaction
                  FROM {question_attempts} qa
                  JOIN {question} q ON q.id = qa.questionid
                  JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                 WHERE qa.questionusageid $usagesql
                   AND qas.sequencenumber = (
                           SELECT MAX(laststep.sequencenumber)
                             FROM {question_attempt_steps} laststep
                            WHERE laststep.questionattemptid = qa.id
                       )
              GROUP BY qa.questionusageid";

        $progress = [];
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach ($rs as $row) {
            $progress[$attemptidbyusage[(int) $row->questionusageid]] = (object) [
                'answered' => (int) $row->answered,
                'lastaction' => (int) $row->lastaction,
            ];
        }
        $rs->close();

        return $progress;
    }

    /**
     * Get quiz access managers for users with an active attempt.
     *
     * Users without a user or group override share one access manager built from the
     * base quiz settings. Users with an override get their own one, built from the
     * effective settings exactly like quiz_settings::create($quizid, $userid).
     *
     * @param int $quizid Quiz id.
     * @param int[] $userids Users with an active attempt.
     * @param int $now Current timestamp.
     * @return access_manager[] Map user id => access manager.
     */
    protected static function get_access_managers(int $quizid, array $userids, int $now): array {
        if ($userids === []) {
            return [];
        }

        $basesettings = quiz_settings::create($quizid);
        $baseaccessmanager = $basesettings->get_access_manager($now);
        $overridden = overrides_manager::get_userids_with_overrides($quizid, $userids);

        $managers = [];
        foreach ($userids as $userid) {
            if (empty($overridden[$userid])) {
                $managers[$userid] = $baseaccessmanager;
                continue;
            }
            $usersettings = new quiz_settings(
                quiz_update_effective_access(clone $basesettings->get_quiz(), $userid),
                $basesettings->get_cm(),
                $basesettings->get_course()
            );
            $managers[$userid] = $usersettings->get_access_manager($now);
        }

        return $managers;
    }

    /**
     * Pick the most relevant attempt for a user (unfinished wins, else latest finished).
     *
     * @param array $attempts Attempt records ordered newest first.
     * @return stdClass|null
     */
    public static function pick_relevant_attempt(array $attempts): ?stdClass {
        $latestfinished = null;

        foreach ($attempts as $attempt) {
            if (self::is_active_attempt_state($attempt->state)) {
                return $attempt;
            }
            if (self::is_completed_attempt_state($attempt->state) && $latestfinished === null) {
                $latestfinished = $attempt;
            }
        }

        return $latestfinished;
    }

    /**
     * Bootstrap presentation classes for a monitor status.
     *
     * @param string $status Monitor status constant.
     * @return array{badgeclass: string, progressbarclass: string, tileborderclass: string}
     */
    public static function get_status_presentation(string $status): array {
        switch ($status) {
            case self::STATUS_INPROGRESS:
                return [
                    'badgeclass' => 'badge-warning',
                    'progressbarclass' => 'bg-warning',
                    'tileborderclass' => 'border-warning',
                ];
            case self::STATUS_IDLE:
                return [
                    'badgeclass' => 'badge-danger',
                    'progressbarclass' => 'bg-danger',
                    'tileborderclass' => 'border-danger',
                ];
            case self::STATUS_COMPLETED:
                return [
                    'badgeclass' => 'badge-success',
                    'progressbarclass' => 'bg-success',
                    'tileborderclass' => 'border-success',
                ];
            default:
                return [
                    'badgeclass' => 'badge-secondary',
                    'progressbarclass' => 'bg-secondary',
                    'tileborderclass' => 'border-secondary',
                ];
        }
    }

    /**
     * Build lowercase search haystack from fields the viewer may search.
     *
     * @param stdClass $user User record.
     * @param bool $showhidden Whether hidden identity fields may be included.
     * @return string
     */
    public static function build_searchtext(stdClass $user, bool $showhidden): string {
        $parts = [\core_text::strtolower(fullname($user))];

        if ($showhidden) {
            foreach (['email', 'username', 'idnumber'] as $field) {
                if (!empty($user->{$field})) {
                    $parts[] = \core_text::strtolower($user->{$field});
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Compute progress bar fill percentage for a student row.
     *
     * Fill is always based on answered ÷ total for active and completed attempts.
     * Completion is conveyed separately via {@see get_status_presentation()} bar colour.
     *
     * @param string $status Monitor status.
     * @param int $answered Questions answered.
     * @param int $total Total questions in quiz.
     * @return int Percentage 0–100.
     */
    public static function compute_progress_percent(string $status, int $answered, int $total): int {
        if ($status === self::STATUS_NOTSTARTED || $total <= 0) {
            return 0;
        }
        return (int) round($answered / $total * 100);
    }

    /**
     * Map a Moodle attempt state to monitor status.
     *
     * Note that an INPROGRESS status may be changed to an IDLE status later,
     * depending on the user activity. See the "build_student_row()" method.
     *
     * @param stdClass|null $attempt Relevant attempt or null.
     * @return string
     */
    protected static function map_status(?stdClass $attempt): string {
        if ($attempt === null) {
            return self::STATUS_NOTSTARTED;
        }
        if (self::is_active_attempt_state($attempt->state)) {
            return self::STATUS_INPROGRESS;
        }
        if (self::is_completed_attempt_state($attempt->state)) {
            return self::STATUS_COMPLETED;
        }
        return self::STATUS_NOTSTARTED;
    }

    /**
     * Build a student row for the monitor table/API.
     *
     * @param stdClass $user User record.
     * @param stdClass|null $attempt Relevant attempt.
     * @param stdClass|null $progress Attempt progress from load_attempt_progress().
     * @param access_manager|null $accessmanager Access manager for an active attempt.
     * @param int $totalquestions Total quiz questions.
     * @param stdClass $quiz Quiz record.
     * @param int $now Current timestamp.
     * @param bool $showemail Whether email may be shown.
     * @param bool $canextend Whether the viewer may extend time.
     * @return stdClass
     */
    protected static function build_student_row(
        stdClass $user,
        ?stdClass $attempt,
        ?stdClass $progress,
        ?access_manager $accessmanager,
        int $totalquestions,
        stdClass $quiz,
        int $now,
        bool $showemail,
        bool $canextend
    ): stdClass {
        $status = self::map_status($attempt);
        $statuslabel = self::status_label($status);

        $answered = 0;
        $timeremaining = null;
        $timeremainingdisplay = '';
        $attemptendat = null;

        if ($attempt !== null && $status !== self::STATUS_NOTSTARTED) {
            $answered = $progress->answered ?? 0;
            try {
                if ($status === self::STATUS_INPROGRESS && $accessmanager !== null) {
                    $timeremaining = $accessmanager->get_time_left_display($attempt, $now);
                    $endtime = $accessmanager->get_end_time($attempt);
                    if ($endtime !== false) {
                        $attemptendat = (int) $endtime;

                        // When there is no time limit and timeclose is more than an hour away,
                        // timeremaining is set to FALSE and 00:00 is displayed in the monitor.
                        // This is not what we want, so we recalculate and override it.
                        // See QUIZ_SHOW_TIME_BEFORE_DEADLINE.
                        if ($timeremaining === false && $endtime > $now) {
                            $timeremaining = $endtime - $now;
                        }
                    }
                    // Format timeremaining.
                    if ($timeremaining !== false && $timeremaining >= 0) {
                        $timeremainingdisplay = self::format_duration((int) $timeremaining);
                    } else if ($timeremaining !== false && $timeremaining < 0) {
                        $timeremaining = 0;
                        $timeremainingdisplay = get_string('timeup', 'quiz_livequizmonitor');
                    }
                    if (self::is_attempt_idle($progress, $now)) {
                        $status = self::STATUS_IDLE;
                        $statuslabel = self::status_label($status);
                    }
                }
            } catch (\Exception $e) {
                debugging('Failed to load attempt ' . $attempt->id . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        $progresstext = '';
        if ($totalquestions > 0) {
            $progresstext = get_string('progressanswered', 'quiz_livequizmonitor', (object) [
                'answered' => $answered,
                'total' => $totalquestions,
            ]);
        }

        $presentation = self::get_status_presentation($status);
        $progresspercent = self::compute_progress_percent($status, $answered, $totalquestions);

        $hastimer = in_array($status, self::INPROGRESS_OR_IDLE) && $timeremaining !== null;

        return (object) [
            'courseid' => (int) $quiz->course,
            'userid' => (int) $user->id,
            'fullname' => fullname($user),
            'firstinitial' => \core_text::strtoupper(\core_text::substr($user->firstname, 0, 1)),
            'lastinitial' => \core_text::strtoupper(\core_text::substr($user->lastname, 0, 1)),
            'email' => $showemail ? $user->email : '',
            'showemail' => $showemail,
            'status' => $status,
            'statuslabel' => $statuslabel,
            'statusclass' => $presentation['badgeclass'],
            'progressbarclass' => $presentation['progressbarclass'],
            'progresspercent' => $progresspercent,
            'attemptid' => $attempt ? (int) $attempt->id : null,
            'progressanswered' => $answered,
            'progresstotal' => $totalquestions,
            'progresstext' => $progresstext,
            'timeremaining' => $timeremaining,
            'timeremainingdisplay' => $timeremainingdisplay,
            'searchtext' => self::build_searchtext($user, $showemail),
            'attemptendat' => $attemptendat,
            'canextend' => $canextend,
            'hastimer' => $hastimer,
            'hasnote' => false,
            'hasuseroverride' => false,
            'hasusertimeoverride' => false,
            'hasgroupoverride' => false,
            'hasgrouptimeoverride' => false,
            'hastimeoverride' => false,
            'isblocked' => false,
            'unblockactionenabled' => false,
            'extensioncells' => [],
            'highlight' => '',
            'extensionactions' => [],
        ];
    }

    /**
     * Localised label for monitor status.
     *
     * @param string $status Monitor status constant.
     * @return string
     */
    protected static function status_label(string $status): string {
        switch ($status) {
            case self::STATUS_INPROGRESS:
                return get_string('status:inprogress', 'quiz_livequizmonitor');
            case self::STATUS_IDLE:
                return get_string('status:idle', 'quiz_livequizmonitor');
            case self::STATUS_COMPLETED:
                return get_string('status:completed', 'quiz_livequizmonitor');
            default:
                return get_string('status:notstarted', 'quiz_livequizmonitor');
        }
    }

    /**
     * Check whether or not the given quiz attempt is idle,
     * where "idle" means "no activity within the last 5 minutes".
     *
     * @param stdClass|null $progress Attempt progress from load_attempt_progress().
     * @param int $timenow Time stamp for the current time.
     * @return bool TRUE if the attempt is idle; otherwise FALSE.
     */
    protected static function is_attempt_idle(?stdClass $progress, int $timenow): bool {
        return ($progress->lastaction ?? 0) <= $timenow - self::IDLE_THRESHOLD_SECONDS;
    }

    /**
     * Format seconds as MM:SS or HH:MM:SS.
     *
     * @param int $seconds Seconds.
     * @return string
     */
    public static function format_duration(int $seconds): string {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%02d:%02d', $minutes, $secs);
    }

    /**
     * Sort rows by the requested column and direction.
     *
     * @param array $rows Student rows (by reference).
     * @param string $sortcolumn Column to sort by.
     * @param string $sortdirection Sort direction: asc or desc.
     */
    protected static function sort_student_rows(
        array &$rows,
        string $sortcolumn = 'status',
        string $sortdirection = 'asc'
    ): void {
        $sortable = [
            'status' => 'status',
            'fullname' => 'fullname',
            'email' => 'email',
            'progress' => 'progresspercent',
            'timeremaining' => 'timeremaining',
        ];

        // Sanity check on incoming values.
        $sortdirection = $sortdirection === 'desc' ? 'desc' : 'asc';
        if (!isset($sortable[$sortcolumn]) && column_helper::is_extension_column_id($sortcolumn)) {
            self::sort_rows_by_extension_column($rows, $sortcolumn, $sortdirection);
            return;
        }
        $sortcolumn = $sortable[$sortcolumn] ?? 'status';

        $rank = [
            self::STATUS_INPROGRESS => 0,
            self::STATUS_IDLE => 1,
            self::STATUS_NOTSTARTED => 2,
            self::STATUS_COMPLETED => 3,
        ];

        usort($rows, static function (
            stdClass $a,
            stdClass $b
        ) use (
            $sortcolumn,
            $sortdirection,
            $rank
        ): int {
            if ($sortcolumn === 'status') {
                // Status uses defined rank rather than alphabetical order.
                // The spaceship operator, <=>, works like strcmp for numbers.
                $cmp = ($rank[$a->status] ?? 99) <=> ($rank[$b->status] ?? 99);
            } else {
                $valuea = $a->{$sortcolumn} ?? null;
                $valueb = $b->{$sortcolumn} ?? null;

                if ($valuea === $valueb) {
                    $cmp = 0;
                } else if ($valuea === null) {
                    $cmp = 1;
                } else if ($valueb === null) {
                    $cmp = -1;
                } else if (is_numeric($valuea) && is_numeric($valueb)) {
                    $cmp = $valuea <=> $valueb;
                } else {
                    $cmp = strcmp((string) $valuea, (string) $valueb);
                }
            }

            if ($sortdirection === 'desc') {
                $cmp = -$cmp;
            }

            // Name tie-break stays A→Z regardless of sort direction.
            if ($cmp === 0) {
                $cmp = strcmp($a->fullname, $b->fullname);
            }

            return $cmp;
        });
    }

    /**
     * Sort rows by an extension column.
     *
     * Note: the sorting algorithm here mirrors the extension column branch of "getStudentRows()".
     * See "amd/src/monitor.js".
     *
     * @param array $rows Student rows (by reference).
     * @param string $columnid Extension column id.
     * @param string $sortdirection Sort direction: asc or desc.
     */
    protected static function sort_rows_by_extension_column(array &$rows, string $columnid, string $sortdirection): void {
        // Look up each row's cell once instead of on every comparison.
        $cells = [];
        foreach ($rows as $row) {
            foreach ($row->extensioncells ?? [] as $cell) {
                if ($cell['column'] === $columnid) {
                    $cells[$row->userid] = $cell;
                    break;
                }
            }
        }

        usort($rows, static function (stdClass $a, stdClass $b) use ($cells, $sortdirection): int {
            $cella = $cells[$a->userid] ?? null;
            $cellb = $cells[$b->userid] ?? null;

            // Rows without a value always sort last, in both directions.
            if ($cella === null || $cellb === null) {
                $cmp = ($cella === null ? 1 : 0) - ($cellb === null ? 1 : 0);
                return $cmp ?: strcmp($a->fullname, $b->fullname);
            }

            if ($cella['sortnumeric'] && $cellb['sortnumeric']) {
                $cmp = (float) $cella['sortvalue'] <=> (float) $cellb['sortvalue'];
            } else {
                $cmp = strcmp($cella['sortvalue'], $cellb['sortvalue']);
            }
            // Normalise strcmp() results, so negating them is safe.
            $cmp = $cmp <=> 0;

            if ($sortdirection === 'desc') {
                $cmp = -$cmp;
            }

            // Name tie-break stays A→Z regardless of sort direction.
            return $cmp ?: strcmp($a->fullname, $b->fullname);
        });
    }

    /**
     * Build cohort summary counts and percentages.
     *
     * @param array $rows Student rows.
     * @param int $total Total students.
     * @return stdClass
     */
    protected static function build_summary(array $rows, int $total): stdClass {
        $counts = [
            self::STATUS_NOTSTARTED => 0,
            self::STATUS_INPROGRESS => 0,
            self::STATUS_IDLE => 0,
            self::STATUS_COMPLETED => 0,
        ];

        foreach ($rows as $row) {
            if (isset($counts[$row->status])) {
                $counts[$row->status]++;
            }
        }

        $percent = static function (int $count) use ($total): int {
            if ($total === 0) {
                return 0;
            }
            return (int) round($count / $total * 100);
        };

        $buildbucket = static function (string $status, string $labelkey) use ($counts, $percent): stdClass {
            $presentation = self::get_status_presentation($status);
            return (object) [
                'count' => $counts[$status],
                'percent' => $percent($counts[$status]),
                'label' => get_string($labelkey, 'quiz_livequizmonitor'),
                'statusclass' => $presentation['tileborderclass'],
            ];
        };

        return (object) [
            'notstarted' => $buildbucket(self::STATUS_NOTSTARTED, 'summary:notstarted'),
            'inprogress' => $buildbucket(self::STATUS_INPROGRESS, 'summary:inprogress'),
            'idle' => $buildbucket(self::STATUS_IDLE, 'summary:idle'),
            'completed' => $buildbucket(self::STATUS_COMPLETED, 'summary:completed'),
        ];
    }
}

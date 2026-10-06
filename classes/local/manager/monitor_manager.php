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
use mod_quiz\quiz_attempt;
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
     * @param int $groupid Active group id (0 = all visible groups).
     * @param string $sortcolumn Column to sort by.
     * @param string $sortdirection Sort direction ('asc' or 'desc').
     * @return stdClass MonitorState payload.
     */
    public static function get_state(
        stdClass $course,
        cm_info|stdClass $cm,
        stdClass $quiz,
        int $groupid = 0,
        string $sortcolumn = 'status',
        string $sortdirection = 'asc'
    ): stdClass {
        global $DB;

        $context = context_module::instance($cm->id);
        $now = time();

        $canviewattempts = has_capability('mod/quiz:viewreports', $context);

        // Can this user view log records in this context?
        $canviewlogs = has_any_capability(['report/log:view', 'report/log:viewtoday'], $context);

        // Resolve group for enrolment query (respect quiz report group mode).
        if ($groupid <= 0) {
            $groupid = groups_get_activity_group($cm, true) ?: 0;
        }

        $students = get_enrolled_users($context, 'mod/quiz:attempt', $groupid, 'u.*', 'u.lastname ASC, u.firstname ASC');
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

        $rows = [];
        foreach ($students as $user) {
            $userattempts = $attemptsbyuser[$user->id] ?? [];
            $relevant = self::pick_relevant_attempt($userattempts);
            $rows[] = self::build_student_row($user, $relevant, $totalquestions, $quiz, $context, $now, $showemail);
        }

        self::sort_student_rows($rows, $sortcolumn, $sortdirection);

        $userids = array_map(static fn(stdClass $row): int => (int) $row->userid, $rows);
        $hasnotemap = student_note_manager::get_hasnote_map((int) $quiz->id, $userids);
        foreach ($rows as $row) {
            $row->hasnote = !empty($hasnotemap[$row->userid]);
        }

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

                // Update override counts, if necessary.
                if ($row->hasuseroverride) {
                    $useroverridecount++;
                }
                if ($row->hasgroupoverride) {
                    $groupoverridecount++;
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
            'useroverridecount' => $useroverridecount,
            'groupoverridecount' => $groupoverridecount,
        ];

        return $state;
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
     * @param int $totalquestions Total quiz questions.
     * @param stdClass $quiz Quiz record.
     * @param context_module $context Module context.
     * @param int $now Current timestamp.
     * @param bool $showemail Whether email may be shown.
     * @return stdClass
     */
    protected static function build_student_row(
        stdClass $user,
        ?stdClass $attempt,
        int $totalquestions,
        stdClass $quiz,
        context_module $context,
        int $now,
        bool $showemail
    ): stdClass {
        $status = self::map_status($attempt);
        $statuslabel = self::status_label($status);

        $answered = 0;
        $timeremaining = null;
        $timeremainingdisplay = '';
        $attemptendat = null;

        if ($attempt !== null && $status !== self::STATUS_NOTSTARTED) {
            try {
                $attemptobj = quiz_attempt::create((int) $attempt->id);
                $answered = self::count_answered_questions($attemptobj);
                if ($status === self::STATUS_INPROGRESS) {
                    $accessmanager = $attemptobj->get_access_manager($now);
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
                    if (self::is_attempt_idle($attemptobj, $now)) {
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

        $canextend = extend_time_manager::user_can_extend($context);

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
        ];
    }

    /**
     * Count answered questions on an attempt.
     *
     * @param quiz_attempt $attemptobj Attempt object.
     * @return int
     */
    protected static function count_answered_questions(quiz_attempt $attemptobj): int {
        $total = 0;
        foreach ($attemptobj->get_slots() as $slot) {
            if ($attemptobj->is_real_question($slot)) {
                $total++;
            }
        }
        return $total - $attemptobj->get_number_of_unanswered_questions();
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
     * @param quiz_attempt $attemptobj The quiz attempt.
     * @param int $timenow Time stamp for the current time.
     * @return bool TRUE if the attempt is idle; otherwise FALSE.
     */
    protected static function is_attempt_idle(quiz_attempt $attemptobj, int $timenow): bool {
        $timestamp = $timenow - self::IDLE_THRESHOLD_SECONDS;

        foreach ($attemptobj->get_slots() as $slot) {
            if ($attemptobj->get_question_action_time($slot) > $timestamp) {
                return false;
            }
        }

        // No recent activity detected, so attempt is idle.
        return true;
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
        $sortcolumn = $sortable[$sortcolumn] ?? 'status';
        $sortdirection = $sortdirection === 'desc' ? 'desc' : 'asc';

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

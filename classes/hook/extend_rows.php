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
 * Hook to add columns, row highlights and row actions to the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

use cm_info;
use context_module;
use core\output\pix_icon;
use core_text;
use moodle_url;
use quiz_livequizmonitor\local\column_helper;
use stdClass;

/**
 * Lets plugins add columns, row highlights and row actions to the live quiz monitor.
 *
 * Dispatched once per monitor state build (page load and every poll) with all students the
 * current user may see, so callbacks can load their data in one batch and use it for columns,
 * highlights and actions alike.
 *
 * - Columns are shown after the built-in data columns and before the actions column, and can be
 *   sorted and hidden like built-in columns. Column ids are "<component>-<name>", e.g.
 *   "local_sebstatus-battery", where component is the plugin that adds the column and name may
 *   only contain lowercase letters and digits.
 * - If several plugins highlight the same row, the highest severity wins (danger > warning > info).
 * - Actions are plain links, shown after the built-in entries of the actions menu, separated by a divider.
 * - Programming errors that do not depend on data (invalid or duplicate column id, value for a
 *   column that is not registered, unknown severity) throw a coding_exception.
 * - Empty labels may come from data, so they are ignored with a developer debugging message: the
 *   column or action is dropped, values for a dropped column are ignored silently. Users who are not
 *   shown in the monitor are ignored silently.
 */
#[\core\attribute\label('Add columns, row highlights and row actions to the live quiz monitor.')]
#[\core\attribute\tags('quiz_livequizmonitor')]
final class extend_rows {
    /** @var string Informational highlight. */
    public const SEVERITY_INFO = 'info';

    /** @var string Warning highlight. */
    public const SEVERITY_WARNING = 'warning';

    /** @var string Danger highlight. */
    public const SEVERITY_DANGER = 'danger';

    /** @var int[] Rank of each severity, higher wins. */
    protected const SEVERITY_RANKS = [
        self::SEVERITY_INFO => 1,
        self::SEVERITY_WARNING => 2,
        self::SEVERITY_DANGER => 3,
    ];

    /** @var string[] Row fields handed out by get_student_rows(). */
    protected const STUDENT_ROW_FIELDS = [
        'userid',
        'fullname',
        'email',
        'status',
        'attemptid',
        'attemptendat',
        'timeremaining',
        'progressanswered',
        'progresstotal',
    ];

    /** @var stdClass Course record. */
    protected $course;

    /** @var cm_info Quiz course module. */
    protected $cm;

    /** @var stdClass Quiz record. */
    protected $quiz;

    /** @var int Active group id (0 = all groups). */
    protected $groupid;

    /** @var stdClass[] Monitor rows indexed by user id. Internal, never handed out. */
    protected $rows = [];

    /** @var array[] Registered columns indexed by column id, in registration order. */
    protected $columns = [];

    /** @var bool[] Ids of columns dropped because of an empty label, as keys. */
    protected $droppedcolumns = [];

    /** @var array[] Cells indexed by user id and column id. */
    protected $cells = [];

    /** @var string[] Highest severity per student, indexed by user id. */
    protected $highlights = [];

    /** @var array[] Actions per student, indexed by user id, in the order they were added. */
    protected $actions = [];

    /**
     * Constructor.
     *
     * @param stdClass $course Course record.
     * @param cm_info $cm Quiz course module.
     * @param stdClass $quiz Quiz record.
     * @param int $groupid Active group id (0 = all groups).
     * @param stdClass[] $rows Student rows of the monitor.
     */
    public function __construct(stdClass $course, cm_info $cm, stdClass $quiz, int $groupid, array $rows) {
        $this->course = $course;
        $this->cm = $cm;
        $this->quiz = $quiz;
        $this->groupid = $groupid;
        foreach ($rows as $row) {
            $this->rows[(int) $row->userid] = $row;
        }
    }

    // Monitor data.

    /**
     * Course of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_course(): stdClass {
        return $this->course;
    }

    /**
     * Course module of the monitored quiz.
     *
     * @return cm_info
     */
    public function get_cm(): cm_info {
        return $this->cm;
    }

    /**
     * Record of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_quiz(): stdClass {
        return $this->quiz;
    }

    /**
     * Module context of the monitored quiz.
     *
     * @return context_module
     */
    public function get_context(): context_module {
        return context_module::instance($this->cm->id);
    }

    /**
     * Active group of the monitor (0 = all groups).
     *
     * @return int
     */
    public function get_groupid(): int {
        return $this->groupid;
    }

    /**
     * Data of the student rows shown in the monitor for the current user, indexed by user id.
     *
     * These are not user records, but new objects with these fields of the monitor rows:
     * userid, fullname, email ('' if the user may not see it), status, attemptid, attemptendat,
     * timeremaining, progressanswered, progresstotal.
     * Note, override and blocked flags are set after the hook and are not available.
     *
     * @return stdClass[]
     */
    public function get_student_rows(): array {
        // Built on each call, so one callback cannot change what the next one sees.
        $result = [];
        foreach ($this->rows as $userid => $row) {
            $studentrow = new stdClass();
            foreach (self::STUDENT_ROW_FIELDS as $field) {
                $studentrow->{$field} = $row->{$field} ?? null;
            }
            $result[$userid] = $studentrow;
        }
        return $result;
    }

    /**
     * User ids of the students shown in the monitor.
     *
     * @return int[]
     */
    public function get_userids(): array {
        return array_keys($this->rows);
    }

    // Columns.

    /**
     * Register a column.
     *
     * @param string $id Column id "<component>-<name>", e.g. "local_sebstatus-battery".
     * @param string $label Column header, already localised.
     * @param bool $defaulthidden Whether the column is hidden until the user shows it.
     * @throws \coding_exception If the id is invalid or already registered.
     */
    public function add_column(string $id, string $label, bool $defaulthidden = false): void {
        if (!column_helper::is_extension_column_id($id)) {
            throw new \coding_exception("Invalid live quiz monitor column id '{$id}', expected '<component>-<name>'");
        }
        if (isset($this->columns[$id]) || isset($this->droppedcolumns[$id])) {
            throw new \coding_exception("Live quiz monitor column '{$id}' is already registered");
        }
        if (trim($label) === '') {
            // The label may come from data, so this does not break the monitor.
            debugging("Live quiz monitor column '{$id}' has no label and is not shown", DEBUG_DEVELOPER);
            $this->droppedcolumns[$id] = true;
            return;
        }

        $this->columns[$id] = [
            'id' => $id,
            'label' => $label,
            'defaulthidden' => $defaulthidden,
        ];
    }

    /**
     * Set a plain text value. The text is escaped.
     *
     * @param string $id Column id, as passed to add_column().
     * @param int $userid Student user id.
     * @param string $text Plain text.
     * @param int|float|string|null $sortvalue Value to sort by; null sorts by the text.
     * @throws \coding_exception If the column is not registered.
     */
    public function set_value(string $id, int $userid, string $text, int|float|string|null $sortvalue = null): void {
        $this->add_cell($id, $userid, s($text), $sortvalue);
    }

    /**
     * Set an HTML value. The markup is cleaned, unsafe content (scripts, event handlers) is removed.
     *
     * @param string $id Column id, as passed to add_column().
     * @param int $userid Student user id.
     * @param string $html HTML fragment.
     * @param int|float|string|null $sortvalue Value to sort by; null sorts by the plain text of the HTML.
     * @throws \coding_exception If the column is not registered.
     */
    public function set_html_value(string $id, int $userid, string $html, int|float|string|null $sortvalue = null): void {
        $display = format_text($html, FORMAT_HTML, ['context' => $this->get_context(), 'filter' => false]);
        $this->add_cell($id, $userid, $display, $sortvalue);
    }

    // Highlights.

    /**
     * Highlight a student's row.
     *
     * @param int $userid Student user id.
     * @param string $severity One of the SEVERITY_* constants.
     * @throws \coding_exception If the severity is unknown.
     */
    public function set_highlight(int $userid, string $severity): void {
        if (!isset(self::SEVERITY_RANKS[$severity])) {
            throw new \coding_exception("Unknown live quiz monitor row severity '{$severity}'");
        }
        if (!$this->is_known_user($userid)) {
            return;
        }

        $current = $this->highlights[$userid] ?? null;
        if ($current === null || self::SEVERITY_RANKS[$severity] > self::SEVERITY_RANKS[$current]) {
            $this->highlights[$userid] = $severity;
        }
    }

    // Actions.

    /**
     * Add a link to a student's actions menu.
     *
     * @param int $userid Student user id.
     * @param string $label Link text, already localised.
     * @param moodle_url $url Link target.
     * @param pix_icon|null $icon Optional icon shown before the label.
     * @param bool $newtab Whether the link opens in a new browser tab.
     */
    public function add_action(
        int $userid,
        string $label,
        moodle_url $url,
        ?pix_icon $icon = null,
        bool $newtab = false,
    ): void {
        if (trim($label) === '') {
            // The label may come from data, so this does not break the monitor.
            debugging('Live quiz monitor row action has no label and is not shown', DEBUG_DEVELOPER);
            return;
        }
        if (!$this->is_known_user($userid)) {
            return;
        }

        $this->actions[$userid][] = [
            'label' => $label,
            'url' => $url->out(false),
            'iconkey' => $icon ? (string) $icon->pix : '',
            'iconcomponent' => $icon ? (string) $icon->component : '',
            'newtab' => $newtab,
        ];
    }

    // Results, read by the monitor after the dispatch.

    /**
     * Registered columns in registration order.
     *
     * @return array[] List of ['id' => string, 'label' => string, 'defaulthidden' => bool].
     */
    public function get_columns(): array {
        return array_values($this->columns);
    }

    /**
     * Extension cell values per student.
     *
     * @return array[] User id => column id => ['column', 'display', 'sortvalue', 'sortnumeric'].
     */
    public function get_extension_cells(): array {
        return $this->cells;
    }

    /**
     * Highest severity per student over all callbacks.
     *
     * @return string[] User id => severity.
     */
    public function get_highlights(): array {
        return $this->highlights;
    }

    /**
     * Actions per student, in the order they were added.
     *
     * @return array[] User id => list of ['label', 'url', 'iconkey', 'iconcomponent', 'newtab'].
     */
    public function get_actions(): array {
        return $this->actions;
    }

    // Internal helpers.

    /**
     * Store a cleaned cell value.
     *
     * @param string $id Column id.
     * @param int $userid Student user id.
     * @param string $display Safe HTML to render.
     * @param int|float|string|null $sortvalue Value to sort by.
     * @throws \coding_exception If the column is not registered.
     */
    protected function add_cell(string $id, int $userid, string $display, int|float|string|null $sortvalue): void {
        if (isset($this->droppedcolumns[$id])) {
            // Already reported when the column was registered.
            return;
        }
        if (!isset($this->columns[$id])) {
            throw new \coding_exception("Live quiz monitor column '{$id}' is not registered");
        }
        if (!$this->is_known_user($userid)) {
            return;
        }

        if ($sortvalue === null) {
            // Without an explicit sort value, sort by the visible text.
            $sortvalue = core_text::strtolower(trim(html_entity_decode(strip_tags($display), ENT_QUOTES, 'UTF-8')));
        }

        $this->cells[$userid][$id] = [
            'column' => $id,
            'display' => $display,
            'sortvalue' => (string) $sortvalue,
            'sortnumeric' => is_int($sortvalue) || is_float($sortvalue),
        ];
    }

    /**
     * Whether the user id belongs to a student shown in the monitor.
     *
     * Other users are ignored silently: callbacks may pass all users they have data for,
     * even if some of them are not shown (e.g. because of the active group).
     *
     * @param int $userid User id.
     * @return bool
     */
    protected function is_known_user(int $userid): bool {
        return isset($this->rows[$userid]);
    }
}

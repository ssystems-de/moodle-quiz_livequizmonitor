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
 * Renderable for the live quiz monitor page.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\output;

use cm_info;
use quiz_livequizmonitor\local\column_helper;
use quiz_livequizmonitor\local\manager\extend_time_manager;
use quiz_livequizmonitor\local\manager\monitor_manager;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The live quiz monitor page.
 *
 * Holds the page data and exports the context for the quiz_livequizmonitor/monitor_page
 * template. It is rendered by renderer::render_monitor_report(), which themes can override.
 */
class monitor_report implements renderable, templatable {
    /** @var stdClass Monitor state from monitor_manager::get_state(). */
    protected $state;

    /** @var int Active group id. */
    protected $groupid;

    /** @var string HTML of the standard group menu ('' if the activity has no group mode). */
    protected $groupmenu;

    /** @var stdClass|null Course record, loaded on demand. */
    protected $course = null;

    /** @var cm_info|null Quiz course module, loaded on demand. */
    protected $cm = null;

    /** @var stdClass|null Quiz record, loaded on demand. */
    protected $quiz = null;

    /**
     * Constructor.
     *
     * @param stdClass $state Monitor state from monitor_manager::get_state().
     * @param int $groupid Active group id.
     * @param string $groupmenu HTML of the standard group menu ('' if the activity has no group mode).
     */
    public function __construct(stdClass $state, int $groupid, string $groupmenu = '') {
        $this->state = $state;
        $this->groupid = $groupid;
        $this->groupmenu = $groupmenu;
    }

    /**
     * Monitor state the page is rendered from.
     *
     * @return stdClass
     */
    public function get_state(): stdClass {
        return $this->state;
    }

    /**
     * Active group id.
     *
     * @return int
     */
    public function get_groupid(): int {
        return $this->groupid;
    }

    /**
     * Course of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_course(): stdClass {
        $this->load_course_and_cm();
        return $this->course;
    }

    /**
     * Course module of the monitored quiz.
     *
     * @return cm_info
     */
    public function get_cm(): cm_info {
        $this->load_course_and_cm();
        return $this->cm;
    }

    /**
     * Record of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_quiz(): stdClass {
        global $DB;

        if ($this->quiz === null) {
            $this->quiz = $DB->get_record('quiz', ['id' => $this->get_cm()->instance], '*', MUST_EXIST);
        }
        return $this->quiz;
    }

    /**
     * Load course and course module from the ids in the state (modinfo is cached).
     */
    protected function load_course_and_cm(): void {
        if ($this->cm === null) {
            [$this->course, $this->cm] = get_course_and_cm_from_cmid($this->state->cmid, 'quiz');
        }
    }

    /**
     * Prepare the template context for quiz_livequizmonitor/monitor_page.
     *
     * @param renderer_base $output Renderer used to render the page.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $state = $this->state;
        $groupid = $this->groupid;
        $groupmenu = $this->groupmenu;

        $updated = userdate($state->updatedat, get_string('strftimetime', 'langconfig'));
        $canextend = !empty($state->canextend);
        $inprogresscount = (int) ($state->inprogresscount ?? $state->summary->inprogress->count);
        $idlecount = (int) ($state->idlecount ?? $state->summary->idle->count);
        $onesessionactive = !empty($state->onesessionactive);
        $canunblock = !empty($state->canunblock);
        $canviewattempts = !empty($state->canviewattempts);
        $canviewlogs = !empty($state->canviewlogs);
        $showemailcolumn = !empty($state->students) && !empty($state->students[0]->showemail);
        $extensioncolumns = $state->extensioncolumns ?? [];

        $students = [];
        foreach ($state->students as $row) {
            $student = (array) $row;
            $student['cmid'] = $state->cmid;
            $student['courseid'] = $state->courseid;
            $student['extendactionenabled'] = $canextend && in_array($row->status, monitor_manager::INPROGRESS_OR_IDLE, true);
            $student['canextend'] = $canextend;
            $student['onesessionactive'] = $onesessionactive;
            $student['canunblock'] = $canunblock;
            $student['canviewattempts'] = $canviewattempts;
            $student['canviewlogs'] = $canviewlogs;
            $student['notelabel'] = !empty($row->hasnote)
                ? get_string('notes:editlabel', 'quiz_livequizmonitor')
                : get_string('notes:addlabel', 'quiz_livequizmonitor');
            // Perhaps these label don't need to be passed with every student?
            $student['extendrowlabel'] = get_string('extend:rowaction', 'quiz_livequizmonitor');
            $student['unblocklabel'] = get_string('onesession:unblocklabel', 'quiz_livequizmonitor');
            $student['blockedflaglabel'] = get_string('onesession:blockedflag', 'quiz_livequizmonitor');
            $student['showattemptslabel'] = get_string('attempts:showlabel', 'quiz_livequizmonitor');
            $student['showlogslabel'] = get_string('logs:showlabel', 'quiz_livequizmonitor');
            $student['useroverrideflaglabel'] = get_string('filter:useroverrideflag', 'quiz_livequizmonitor');
            $student['usertimeoverrideflaglabel'] = get_string('filter:usertimeoverrideflag', 'quiz_livequizmonitor');
            $student['groupoverrideflaglabel'] = get_string('filter:groupoverrideflag', 'quiz_livequizmonitor');
            $student['noteflaglabel'] = get_string('notes:hasnoteflag', 'quiz_livequizmonitor');
            $student['extensioncells'] = $this->export_extension_cells($extensioncolumns, $row->extensioncells ?? []);
            $student['highlight'] = (string) ($row->highlight ?? '');
            $student['extensionactions'] = $row->extensionactions ?? [];
            $student['hasextensionactions'] = !empty($row->extensionactions);
            $students[] = $student;
        }

        $tableheaders = $this->export_table_headers();

        return [
            'quizname' => $state->quizname,
            'quizpassword' => $state->quizpassword,
            'totalstudents' => $state->totalstudents,
            'hasstudents' => (bool) $state->hasstudents,
            'updatedat' => $state->updatedat,
            'updatedattext' => get_string('lastupdated', 'quiz_livequizmonitor', $updated),
            'lastupdatedprefix' => get_string('lastupdated', 'quiz_livequizmonitor', ''),
            'liveindicator' => get_string('liveindicator', 'quiz_livequizmonitor'),
            'staleindicator' => get_string('staleindicator', 'quiz_livequizmonitor'),
            'emptycohort' => get_string('emptycohort', 'quiz_livequizmonitor'),
            'groupid' => $groupid,
            'cmid' => $state->cmid,
            'courseid' => $state->courseid,
            'summary' => (array) $state->summary,
            'students' => $students,
            'canextend' => $canextend,
            'extendmaxminutes' => extend_time_manager::MAX_CUSTOM_MINUTES,
            'onesessionactive' => $onesessionactive,
            'canunblock' => $canunblock,
            'canviewattempts' => $canviewattempts,
            'canviewlogs' => $canviewlogs,
            'inprogresscount' => $inprogresscount,
            'idlecount' => $idlecount,
            'showpasswordlabel' => get_string('showpassword:label', 'quiz_livequizmonitor'),
            'bulkextenddisabled' => ($inprogresscount + $idlecount) === 0,
            'bulkextendlabel' => get_string('extend:bulklabel', 'quiz_livequizmonitor'),
            'extendrowlabel' => get_string('extend:rowaction', 'quiz_livequizmonitor'),
            'notesaddlabel' => get_string('notes:addlabel', 'quiz_livequizmonitor'),
            'noteseditlabel' => get_string('notes:editlabel', 'quiz_livequizmonitor'),
            'unblocklabel' => get_string('onesession:unblocklabel', 'quiz_livequizmonitor'),
            'blockedflaglabel' => get_string('onesession:blockedflag', 'quiz_livequizmonitor'),
            'showattemptslabel' => get_string('attempts:showlabel', 'quiz_livequizmonitor'),
            'showlogslabel' => get_string('logs:showlabel', 'quiz_livequizmonitor'),
            'useroverrideflaglabel' => get_string('filter:useroverrideflag', 'quiz_livequizmonitor'),
            'usertimeoverrideflaglabel' => get_string('filter:usertimeoverrideflag', 'quiz_livequizmonitor'),
            'groupoverrideflaglabel' => get_string('filter:groupoverrideflag', 'quiz_livequizmonitor'),
            'noteflaglabel' => get_string('notes:hasnoteflag', 'quiz_livequizmonitor'),
            'actionsmenulabel' => get_string('actions'),
            'tableheaders' => $tableheaders,
            'columns' => $this->export_columns($state, $tableheaders),
            'hiddencolumnsjson' => json_encode(column_helper::get_hidden_columns(0, $extensioncolumns)),
            'showncolumnsjson' => json_encode(column_helper::get_shown_columns()),
            'showemailcolumn' => $showemailcolumn,
            'showactionscolumn' => true, // Always show Actions column.
            'actionscolumnlabel' => get_string('table:actions', 'quiz_livequizmonitor'),
            'filter' => $this->export_filter_context($state, $groupmenu),
            'filterempty' => get_string('filter:empty', 'quiz_livequizmonitor'),
            'sortascending' => get_string('asc'),
            'sortdescending' => get_string('desc'),
        ];
    }

    /**
     * Build one cell per extension column, in column order, for a student row.
     *
     * Every row gets a cell for every extension column, so the table stays aligned
     * when a plugin has no value for a student.
     *
     * @param array[] $extensioncolumns Extension columns (['id', 'label', 'defaulthidden']).
     * @param array[] $cells The student's extension cells (['column', 'display', ...]).
     * @return array[] List of ['column' => string, 'display' => string].
     */
    protected function export_extension_cells(array $extensioncolumns, array $cells): array {
        $displays = array_column($cells, 'display', 'column');

        $result = [];
        foreach ($extensioncolumns as $column) {
            $result[] = [
                'column' => $column['id'],
                'display' => $displays[$column['id']] ?? '',
            ];
        }
        return $result;
    }

    /**
     * Build column header labels from the column registry.
     *
     * @return array<string, string> Column id => header label.
     */
    protected function export_table_headers(): array {
        $headers = [];
        foreach (column_helper::get_columns() as $columnid => $column) {
            $headers[$columnid] = get_string($column['langkey'], 'quiz_livequizmonitor');
        }
        return $headers;
    }

    /**
     * Build the ordered, generic column list the header template loops over.
     *
     * This is the single thing the template needs to render any number of
     * columns, in any order, without knowing their ids in advance: each
     * entry carries its own visibility (showcolumn), lock state,
     * hide/show tooltip labels for the +/- toggle button, and sort metadata.
     *
     * @param stdClass $state Monitor state from monitor_manager.
     * @param array $tableheaders Column id maps to header label.
     * @return array List of column context entries, in registry order.
     */
    protected function export_columns(stdClass $state, array $tableheaders): array {
        // Columns whose presence (not visibility) depends on something other
        // than the registry itself, e.g. a capability or per-quiz setting.
        // Anything not listed here defaults to always present.
        $showflags = [
            'email' => !empty($state->students) && !empty($state->students[0]->showemail),
        ];

        // Extension columns from other plugins go after the built-in data columns, before actions.
        $registry = [];
        foreach (column_helper::get_columns() as $columnid => $column) {
            if ($columnid === 'actions') {
                foreach ($state->extensioncolumns ?? [] as $extensioncolumn) {
                    $registry[$extensioncolumn['id']] = [
                        'label' => $extensioncolumn['label'],
                        'locked' => false,
                        'defaulthidden' => !empty($extensioncolumn['defaulthidden']),
                    ];
                }
            }
            $registry[$columnid] = ['label' => $tableheaders[$columnid] ?? $columnid] + $column;
        }

        $columns = [];
        foreach ($registry as $columnid => $column) {
            $label = $column['label'];
            $locked = !empty($column['locked']);
            // Status is locked (always visible) but still sortable; actions is neither toggleable nor sortable.
            $sortable = $columnid !== 'actions';

            $entry = [
                'id' => $columnid,
                'label' => $label,
                'locked' => $locked,
                'showcolumn' => $showflags[$columnid] ?? true,
                'hidelabel' => get_string('columns:hidecolumn', 'quiz_livequizmonitor', $label),
                'showlabel' => get_string('columns:showcolumn', 'quiz_livequizmonitor', $label),
                'sortable' => $sortable,
                'defaulthidden' => !empty($column['defaulthidden']),
            ];

            if ($sortable) {
                $sortcolumn = $columnid === 'student' ? 'fullname' : $columnid;
                $active = $sortcolumn === ($state->sortcolumn ?? 'status');

                if ($active && ($state->sortdirection ?? 'asc') === 'desc') {
                    $sortlabel = get_string('desc');
                    $sorticon = 'fa-arrow-down-short-wide';
                } else {
                    $sortlabel = get_string('asc');
                    $sorticon = 'fa-arrow-up-short-wide';
                }
                $sortbylabel = get_string('sortby', 'quiz_livequizmonitor', $label);

                if ($active) {
                    $sortclass = 'text-primary';
                } else {
                    $sortclass = 'text-secondary';
                    $sortlabel = $sortbylabel;
                }

                $entry['sortcolumn'] = $sortcolumn;
                $entry['active'] = $active;
                $entry['sorticon'] = $sorticon;
                $entry['sortlabel'] = $sortlabel;
                $entry['sortclass'] = $sortclass;
                $entry['sortbylabel'] = $sortbylabel;
            }

            $columns[] = $entry;
        }
        return $columns;
    }

    /**
     * Build filter toolbar template context.
     *
     * @param stdClass $state Monitor state from monitor_manager.
     * @param string $groupmenu HTML of the standard group menu ('' if the activity has no group mode).
     * @return array Template context for filter partial.
     */
    protected function export_filter_context(stdClass $state, string $groupmenu = ''): array {
        $summary = $state->summary;

        return [
            'filterslabel' => get_string('filter:filterslabel', 'quiz_livequizmonitor'),
            'labelsep' => get_string('labelsep', 'langconfig'),
            'resetalllabel' => get_string('filter:resetall', 'quiz_livequizmonitor'),
            'namelabel' => get_string('filter:namelabel', 'quiz_livequizmonitor'),
            'searchplaceholder' => get_string('filter:searchplaceholder', 'quiz_livequizmonitor'),
            'statuslabel' => get_string('filter:statuslabel', 'quiz_livequizmonitor'),
            'groupmenu' => $groupmenu,
            'chips' => [
                [
                    'status' => 'all',
                    'label' => get_string('filter:all', 'quiz_livequizmonitor'),
                    'count' => $state->totalstudents,
                    'active' => true,
                ],
                [
                    'status' => 'notstarted',
                    'label' => get_string('status:notstarted', 'quiz_livequizmonitor'),
                    'count' => $summary->notstarted->count,
                    'active' => false,
                ],
                [
                    'status' => 'idle',
                    'label' => get_string('status:idle', 'quiz_livequizmonitor'),
                    'count' => $summary->idle->count,
                    'active' => false,
                ],
                [
                    'status' => 'inprogress',
                    'label' => get_string('status:inprogress', 'quiz_livequizmonitor'),
                    'count' => $summary->inprogress->count,
                    'active' => false,
                ],
                [
                    'status' => 'completed',
                    'label' => get_string('status:completed', 'quiz_livequizmonitor'),
                    'count' => $summary->completed->count,
                    'active' => false,
                ],
            ],
            'canviewoverrides' => !empty($state->canviewoverrides),
            'overridesgrouplabel' => get_string('filter:overridesgrouplabel', 'quiz_livequizmonitor'),
            'nooverridelabel' => get_string('filter:nooverride', 'quiz_livequizmonitor'),
            'nooverridecount' => $state->nooverridecount ?? 0,
            'nooverrideactive' => false,
            'useroverridelabel' => get_string('filter:useroverride', 'quiz_livequizmonitor'),
            'useroverridecount' => $state->useroverridecount ?? 0,
            'useroverrideactive' => false,
            'groupoverridelabel' => get_string('filter:groupoverride', 'quiz_livequizmonitor'),
            'groupoverridecount' => $state->groupoverridecount ?? 0,
            'groupoverrideactive' => false,
            // No override filter is active by default, so "All" starts selected.
            'alloverridelabel' => get_string('filter:all', 'quiz_livequizmonitor'),
            'alloverridecount' => $state->totalstudents,
            'alloverrideactive' => true,
        ];
    }
}

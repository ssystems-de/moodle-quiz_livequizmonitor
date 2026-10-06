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
 * Reactive state definition for the live quiz monitor.
 *
 * @module     quiz_livequizmonitor/reactive/monitor_state
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {Reactive} from 'core/reactive';

/**
 * Groups of meta.filters flags that behave like a single-select within the
 * group (activating one deactivates the others), even though flags remain
 * independent of, and combine with AND against, the status filter.
 *
 * @type {string[][]}
 */
const MUTUALLY_EXCLUSIVE_FLAG_GROUPS = [
    ['useroverride', 'groupoverride'],
];

/**
 * Empty summary bucket defaults.
 *
 * @returns {object}
 */
const emptySummary = () => ({
    notstarted: {
        count: 0,
        percent: 0,
        label: '',
        statusclass: 'border-secondary',
    },
    idle: {
        count: 0,
        percent: 0,
        label: '',
        statusclass: 'border-danger',
    },
    inprogress: {
        count: 0,
        percent: 0,
        label: '',
        statusclass: 'border-warning',
    },
    completed: {
        count: 0,
        percent: 0,
        label: '',
        statusclass: 'border-success',
    },
});

/**
 * Create the initial monitor state shape.
 *
 * Moodle reactive state root may only contain objects; list items require an id.
 *
 * @returns {object}
 */
export const createInitialState = () => ({
    meta: {
        quizid: 0,
        cmid: 0,
        quizname: '',
        quizpassword: '',
        updatedat: 0,
        totalstudents: 0,
        hasstudents: false,
        stale: false,
        groupid: 0,
        filters: {
            search: '',
            status: 'all',
            useroverride: false,
            groupoverride: false,
        },
        sortcolumn: 'status',
        sortdirection: 'asc',
        canextend: false,
        inprogresscount: 0,
        idlecount: 0,
        onesessionactive: false,
        canunblock: false,
        canviewoverrides: false,
        useroverridecount: 0,
        groupoverridecount: 0,
        canviewattempts: false,
        canviewlogs: false,
    },
    summary: emptySummary(),
    students: [],
});

/**
 * Normalise API student rows for reactive StateMap (requires id).
 *
 * @param {Array} students
 * @returns {Array}
 */
const normaliseStudents = (students) => students.map((student) => ({
    ...student,
    id: student.userid,
}));

/**
 * Mutation handlers for monitor state updates.
 */
class MonitorMutations {
    /**
     * Replace monitor state from a poll response.
     *
     * @param {StateManager} stateManager
     * @param {object} payload Monitor state payload
     */
    refreshState(stateManager, payload) {
        stateManager.setReadOnly(false);
        stateManager.state.meta.quizid = payload.quizid;
        stateManager.state.meta.cmid = payload.cmid;
        stateManager.state.meta.quizname = payload.quizname;
        stateManager.state.meta.quizpassword = payload.quizpassword;
        stateManager.state.meta.updatedat = payload.updatedat;
        stateManager.state.meta.totalstudents = payload.totalstudents;
        stateManager.state.meta.hasstudents = payload.hasstudents;
        stateManager.state.meta.sortcolumn = payload.sortcolumn;
        stateManager.state.meta.sortdirection = payload.sortdirection;
        stateManager.state.meta.stale = false;
        if (payload.canextend !== undefined) {
            stateManager.state.meta.canextend = payload.canextend;
        }
        if (payload.inprogresscount !== undefined) {
            stateManager.state.meta.inprogresscount = payload.inprogresscount;
        }
        if (payload.idlecount !== undefined) {
            stateManager.state.meta.idlecount = payload.idlecount;
        }
        if (payload.onesessionactive !== undefined) {
            stateManager.state.meta.onesessionactive = payload.onesessionactive;
        }
        if (payload.canunblock !== undefined) {
            stateManager.state.meta.canunblock = payload.canunblock;
        }
        if (payload.canviewattempts !== undefined) {
            stateManager.state.meta.canviewattempts = payload.canviewattempts;
        }
        if (payload.canviewlogs !== undefined) {
            stateManager.state.meta.canviewlogs = payload.canviewlogs;
        }
        if (payload.canviewoverrides !== undefined) {
            stateManager.state.meta.canviewoverrides = payload.canviewoverrides;
        }
        if (payload.useroverridecount !== undefined) {
            stateManager.state.meta.useroverridecount = payload.useroverridecount;
        }
        if (payload.groupoverridecount !== undefined) {
            stateManager.state.meta.groupoverridecount = payload.groupoverridecount;
        }

        // Update summary buckets in place so watchers receive summary.<bucket>:updated events.
        ['notstarted', 'inprogress', 'idle', 'completed'].forEach((key) => {
            if (payload.summary?.[key]) {
                stateManager.state.summary[key] = payload.summary[key];
            }
        });

        // Merge students into the existing StateMap so watchers receive students:updated/created.
        const incoming = normaliseStudents(payload.students ?? []);
        const incomingids = new Set(incoming.map((student) => String(student.id)));
        const students = stateManager.state.students;

        stateManager.getIds('students').forEach((id) => {
            if (!incomingids.has(String(id))) {
                students.delete(id);
            }
        });

        incoming.forEach((student) => {
            students.set(student.id, student);
        });

        stateManager.setReadOnly(true);
    }

    /**
     * Update the search filter term.
     *
     * @param {StateManager} stateManager
     * @param {string} value Search input value
     */
    setSearch(stateManager, value) {
        stateManager.setReadOnly(false);
        const trimmed = (value ?? '').trim();
        stateManager.state.meta.filters.search = trimmed;
        stateManager.setReadOnly(true);
    }

    /**
     * Update the status filter (single-select with toggle-off).
     *
     * @param {StateManager} stateManager
     * @param {string} status Status key or all
     */
    setStatusFilter(stateManager, status) {
        stateManager.setReadOnly(false);
        if (status === 'all') {
            stateManager.state.meta.filters.status = 'all';
        } else {
            const current = stateManager.state.meta.filters.status;
            stateManager.state.meta.filters.status = current === status ? 'all' : status;
        }
        stateManager.setReadOnly(true);
    }

    /**
     * Update the sort column and direction.
     *
     * @param {StateManager} stateManager
     * @param {string} column Name of the selected column
     */
    setSort(stateManager, column) {
        stateManager.setReadOnly(false);

        const meta = stateManager.state.meta;

        if (meta.sortcolumn === column) {
            // Subsequent clicks on the same column toggle the sort direction.
            meta.sortdirection = meta.sortdirection === 'asc' ? 'desc' : 'asc';
        } else {
            // First click on a column sets column name and initial sort direction.
            meta.sortcolumn = column;
            meta.sortdirection = 'asc';
        }

        stateManager.setReadOnly(true);
    }

    /**
     * Toggle a boolean flag filter (e.g. "useroverride"). Independent of
     * the status filter - flags and status can both be active at once.
     *
     * Flags in the same MUTUALLY_EXCLUSIVE_FLAG_GROUPS entry behave like a
     * single-select amongst themselves: activating one deactivates the
     * others in its group.
     *
     * @param {StateManager} stateManager
     * @param {string} flag Flag key in meta.filters (e.g. "useroverride")
     */
    setFlagFilter(stateManager, flag) {
        if (!Object.prototype.hasOwnProperty.call(stateManager.state.meta.filters, flag)) {
            return;
        }
        stateManager.setReadOnly(false);
        const current = stateManager.state.meta.filters[flag];
        const next = !current;
        stateManager.state.meta.filters[flag] = next;

        if (next) {
            const group = MUTUALLY_EXCLUSIVE_FLAG_GROUPS.find((candidate) => candidate.includes(flag));
            if (group) {
                group.forEach((otherFlag) => {
                    if (otherFlag !== flag) {
                        stateManager.state.meta.filters[otherFlag] = false;
                    }
                });
            }
        }

        stateManager.setReadOnly(true);
    }

    /**
     * Reset search, status, and flag filters to defaults.
     *
     * @param {StateManager} stateManager
     */
    clearFilters(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.meta.filters.search = '';
        stateManager.state.meta.filters.status = 'all';
        stateManager.state.meta.filters.useroverride = false;
        stateManager.state.meta.filters.groupoverride = false;
        stateManager.setReadOnly(true);
    }

    /**
     * Mark state as stale after poll failure.
     *
     * @param {StateManager} stateManager
     * @param {boolean} stale Whether data is stale
     */
    setStale(stateManager, stale = true) {
        stateManager.setReadOnly(false);
        stateManager.state.meta.stale = stale;
        stateManager.setReadOnly(true);
    }

    /**
     * Decrement in-progress timers locally between polls.
     *
     * @param {StateManager} stateManager
     */
    tickTimers(stateManager) {
        stateManager.setReadOnly(false);
        stateManager.state.students.forEach((student) => {
            if (!student.hastimer || student.timeremaining === null || student.timeremaining === undefined) {
                return;
            }
            if (student.timeremaining > 0) {
                student.timeremaining -= 1;
                student.timeremainingdisplay = formatDuration(student.timeremaining);
            }
        });
        stateManager.setReadOnly(true);
    }
}

/**
 * Format seconds as MM:SS or HH:MM:SS.
 *
 * @param {number} seconds
 * @returns {string}
 */
export const formatDuration = (seconds) => {
    const safe = Math.max(0, Math.floor(seconds));
    const hours = Math.floor(safe / 3600);
    const minutes = Math.floor((safe % 3600) / 60);
    const secs = safe % 60;
    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
};

/**
 * Create a reactive instance bound to a DOM target.
 *
 * @param {HTMLElement} target Root element for custom events
 * @returns {Reactive}
 */
export const createMonitorReactive = (target) => {
    return new Reactive({
        name: 'quiz_livequizmonitor',
        eventName: 'quiz-live-monitor-state-change',
        eventDispatch: (detail, container) => {
            container.dispatchEvent(new CustomEvent('quiz-live-monitor-state-change', {
                bubbles: false,
                detail,
            }));
        },
        target,
        state: createInitialState(),
        mutations: new MonitorMutations(),
    });
};

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
 * Live quiz monitor polling and DOM sync.
 *
 * @module     quiz_livequizmonitor/monitor
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {BaseComponent} from 'core/reactive';
import {matchesFilters, countVisible} from 'quiz_livequizmonitor/filter_utils';
import {
    parseHiddenColumns,
    applyColumnVisibility,
    saveHiddenColumns,
} from 'quiz_livequizmonitor/column_visibility';
import {createMonitorReactive, formatDuration} from 'quiz_livequizmonitor/reactive/monitor_state';
import {showPasswordModal} from 'quiz_livequizmonitor/show_password_modal';
import {showExtendModal} from 'quiz_livequizmonitor/extend_time_modal';
import {showStudentNoteModal} from 'quiz_livequizmonitor/student_note_modal';
import {showUnblockModal} from 'quiz_livequizmonitor/unblock_confirm_modal';

/** Fixed background refresh interval in milliseconds. */
const POLL_INTERVAL_MS = 5000;

/**
 * Reactive component that syncs monitor state to the page DOM.
 */
class MonitorComponent extends BaseComponent {
    /**
     * @param {object} descriptor Component descriptor
     */
    create(descriptor) {
        this.initSelectors();
        this.initPollState();

        const root = descriptor.element ?? this.element;
        this.initIdsAndFlags(descriptor, root);
        this.initLabels(root);
    }

    /**
     * Cache CSS selectors used throughout the component.
     */
    initSelectors() {
        this.selectors = {
            LASTUPDATED: '[data-region="last-updated"]',
            STALE: '[data-region="stale-indicator"]',
            SUMMARYCOUNT: '[data-summary]',
            SUMMARYPCT: '[data-summary-pct]',
            STUDENTROW: 'tr[data-userid]',
            STATUSBADGE: '.badge',
            PROGRESS: '[data-field="progress"]',
            PROGRESSBAR: '[data-field="progress"] .progress-bar',
            PROGRESSCONTAINER: '[data-field="progress"] .progress[role="progressbar"]',
            PROGRESSLABEL: '[data-field="progress"] .livequizmonitor-progress-label',
            TIMER: '[data-field="timer"]',
            SEARCHINPUT: '[data-action="search"]',
            FILTERCHIP: '.livequizmonitor-filter-toolbar [data-action="filter-status"]',
            FILTERFLAG: '.livequizmonitor-filter-toolbar [data-action="filter-flag"]',
            CLEARFILTERS: '[data-action="clear-filters"]',
            FILTEREMPTY: '[data-region="filter-empty"]',
            STUDENTTABLE: '[data-region="student-table"]',
            COHORTCONTENT: '[data-region="cohort-content"]',
            EMPTYCOHORT: '[data-region="empty-cohort"]',
            SUMMARYTILE: '.livequizmonitor-summary-tile',
            EXTENDBULK: '[data-action="extend-bulk"]',
            COLUMNTOGGLE: '[data-action="toggle-column"]',
            SHOWPASSWORD: '[data-action="show-password"]',
        };
    }

    /**
     * Initialise polling/sync state flags.
     */
    initPollState() {
        this.pollTimer = null;
        this.tickTimer = null;
        this.pollInFlight = false;
        this.syncInFlight = false;
        this.syncQueued = false;
        this.hasReceivedPoll = false;
    }

    /**
     * Read ids and boolean capability/visibility flags from the descriptor and dataset.
     *
     * @param {object} descriptor Component descriptor
     * @param {HTMLElement} root Root element
     */
    initIdsAndFlags(descriptor, root) {
        this.cmid = parseInt(descriptor.cmid ?? root.dataset.cmid ?? 0, 10);
        this.groupid = parseInt(descriptor.groupid ?? root.dataset.groupid ?? 0, 10);
        this.courseId = parseInt(descriptor.courseid ?? root.dataset.courseid ?? 1, 10);
        this.showEmailColumn = root.dataset.showEmail === '1';
        this.showActionsColumn = root.dataset.showActions === '1';
        this.lastUpdatedPrefix = root.dataset.lastupdatedPrefix ?? '';
        this.canextend = root.dataset.canextend === '1';
        this.onesessionactive = root.dataset.onesessionActive === '1';
        this.canunblock = root.dataset.canunblock === '1';
    }

    /**
     * Read display label strings from the dataset.
     *
     * @param {HTMLElement} root Root element
     */
    initLabels(root) {
        this.extendRowLabel = root.dataset.extendRowLabel ?? 'Extend time';
        this.noteAddLabel = root.dataset.notesAddLabel ?? 'Add note';
        this.noteEditLabel = root.dataset.notesEditLabel ?? 'Edit note';
        this.actionsMenuLabel = root.dataset.actionsMenuLabel ?? 'Actions';
        this.unblockRowLabel = root.dataset.unblockLabel ?? 'Unblock user';
        this.blockedFlagLabel = root.dataset.blockedFlagLabel ?? 'Blocked';
        this.hiddenColumns = parseHiddenColumns(root);
        this.showAttemptsLabel = root.dataset.showAttemptsLabel ?? 'Show attempts';
        this.canviewattempts = root.dataset.canviewattempts === '1';
        this.showLogsLabel = root.dataset.showLogsLabel ?? 'Show logs';
        this.canviewlogs = root.dataset.canviewlogs === '1';
        this.sortAscendingLabel = root.dataset.sortAscending ?? 'Ascending';
        this.sortDescendingLabel = root.dataset.sortDescending ?? 'Descending';
        this.userOverrideFlagLabel = root.dataset.useroverrideFlagLabel ?? 'Has extension';
        this.userTimeOverrideFlagLabel = root.dataset.usertimeoverrideFlagLabel ?? 'Time-related override';
        this.groupOverrideFlagLabel = root.dataset.groupoverrideFlagLabel ?? 'With group override';
    }

    /**
     * Current reactive state.
     *
     * @returns {object}
     */
    getState() {
        return this.reactive.stateManager.state;
    }

    /**
     * Watch monitor state fields.
     *
     * @returns {Array}
     */
    getWatchers() {
        return [
            {watch: 'meta.updatedat:updated', handler: this.renderLastUpdated},
            {watch: 'meta.stale:updated', handler: this.renderStaleIndicator},
            {watch: 'summary.notstarted:updated', handler: this.renderSummary},
            {watch: 'summary.idle:updated', handler: this.renderSummary},
            {watch: 'summary.inprogress:updated', handler: this.renderSummary},
            {watch: 'summary.completed:updated', handler: this.renderSummary},
            {watch: 'students:created', handler: this.renderStudents},
            {watch: 'students:deleted', handler: this.renderStudents},
            {watch: 'students:updated', handler: this.renderStudents},
            {watch: 'students.timeremaining:updated', handler: this.renderStudents},
            {watch: 'students.progresstext:updated', handler: this.renderStudents},
            {watch: 'students.progresspercent:updated', handler: this.renderStudents},
            {watch: 'students.progressbarclass:updated', handler: this.renderStudents},
            {watch: 'students.statuslabel:updated', handler: this.renderStudents},
            {watch: 'students.statusclass:updated', handler: this.renderStudents},
            {watch: 'students.status:updated', handler: this.renderStudents},
            {watch: 'students.canextend:updated', handler: this.renderStudents},
            {watch: 'students.attemptendat:updated', handler: this.renderStudents},
            {watch: 'students.hasnote:updated', handler: this.renderStudents},
            {watch: 'students.hasuseroverride:updated', handler: this.renderStudents},
            {watch: 'students.hasusertimeoverride:updated', handler: this.renderStudents},
            {watch: 'students.hasgroupoverride:updated', handler: this.renderStudents},
            {watch: 'students.hasgrouptimeoverride:updated', handler: this.renderStudents},
            {watch: 'students.hastimeoverride:updated', handler: this.renderStudents},
            {watch: 'students.isblocked:updated', handler: this.renderStudents},
            {watch: 'students.unblockactionenabled:updated', handler: this.renderStudents},
            {watch: 'meta.onesessionactive:updated', handler: this.renderStudents},
            {watch: 'meta.canunblock:updated', handler: this.renderStudents},
            {watch: 'meta.hasstudents:updated', handler: this.renderCohortLayout},
            {watch: 'summary.notstarted:updated', handler: this.renderFilterToolbar},
            {watch: 'summary.idle:updated', handler: this.renderFilterToolbar},
            {watch: 'summary.inprogress:updated', handler: this.renderFilterToolbar},
            {watch: 'summary.inprogress:updated', handler: this.renderBulkExtendButton},
            {watch: 'summary.completed:updated', handler: this.renderFilterToolbar},
            {watch: 'meta.totalstudents:updated', handler: this.renderFilterToolbar},
            {watch: 'meta.sortcolumn:updated', handler: this.renderSortIndicators},
            {watch: 'meta.sortdirection:updated', handler: this.renderSortIndicators},
            {watch: 'meta.useroverridecount:updated', handler: this.renderFilterToolbar},
            {watch: 'meta.groupoverridecount:updated', handler: this.renderFilterToolbar},
            {watch: 'meta.canviewoverrides:updated', handler: this.renderFilterToolbar},
        ];
    }

    /**
     * Start polling once state is ready.
     */
    stateReady() {
        this.bindFilterEvents();
        this.bindSortEvents();
        this.bindExtendEvents();
        this.bindNoteEvents();
        this.bindColumnToggleEvents();
        this.startPolling();
        this.startTimerTick();
        this.renderCohortLayout();
        this.renderFilterToolbar();
        this.renderBulkExtendButton();
        this.renderColumnVisibility();
    }

    /**
     * Bind clicks on the per-column +/- hide/show toggle buttons.
     */
    bindColumnToggleEvents() {
        this.addEventListener(this.element, 'click', this.handleColumnToggleClick);
    }

    /**
     * Toggle a column's hidden state, reflect it immediately, and persist it.
     *
     * @param {Event} event
     */
    handleColumnToggleClick(event) {
        const button = event.target.closest(this.selectors.COLUMNTOGGLE);
        if (!button || !this.element.contains(button)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();

        const column = button.dataset.column;
        if (!column) {
            return;
        }

        if (this.hiddenColumns.has(column)) {
            this.hiddenColumns.delete(column);
        } else {
            this.hiddenColumns.add(column);
        }

        this.renderColumnVisibility();

        saveHiddenColumns(this.cmid, this.hiddenColumns).catch((e) => {
            Notification.exception(e);
        });
    }

    /**
     * Re-apply hidden-column state to every column-aware element in the table.
     *
     * Called on init, after a toggle click, and after every reactive row
     * sync so newly inserted rows immediately respect the current
     * preference too.
     */
    renderColumnVisibility() {
        applyColumnVisibility(this.element, this.hiddenColumns);
    }

    /**
     * Bind bulk and individual extend controls.
     */
    bindExtendEvents() {
        this.addEventListener(this.element, 'click', this.handleActionMenuClick);
    }

    /**
     * Bind note menu clicks (same delegate as extend).
     */
    bindNoteEvents() {
        // Handled by handleActionMenuClick.
    }

    /**
     * Delegate row action menu clicks for extend and notes.
     *
     * @param {Event} event
     */
    handleActionMenuClick(event) {
        const noteLink = event.target.closest('[data-action="edit-note"]');
        if (noteLink && this.element.contains(noteLink)) {
            event.preventDefault();
            this.openStudentNoteModal(noteLink);
            return;
        }

        const passwordBtn = event.target.closest(this.selectors.SHOWPASSWORD);
        if (passwordBtn && this.element.contains(passwordBtn)) {
            event.preventDefault();
            this.openShowPasswordModal();
            return;
        }

        const bulkBtn = event.target.closest(this.selectors.EXTENDBULK);
        if (bulkBtn && this.element.contains(bulkBtn)) {
            event.preventDefault();
            if (bulkBtn.disabled) {
                return;
            }
            this.openBulkExtendModal();
            return;
        }

        const individualLink = event.target.closest('[data-action="extend-individual"]');
        if (individualLink && this.element.contains(individualLink)) {
            event.preventDefault();
            if (individualLink.classList.contains('disabled')
                || individualLink.getAttribute('aria-disabled') === 'true') {
                return;
            }
            this.openIndividualExtendModal(individualLink);
            return;
        }

        const unblockLink = event.target.closest('[data-action="unblock-student"]');
        if (unblockLink && this.element.contains(unblockLink)) {
            event.preventDefault();
            if (unblockLink.classList.contains('disabled')
                || unblockLink.getAttribute('aria-disabled') === 'true') {
                return;
            }
            this.openUnblockModal(unblockLink);
        }
    }

    /**
     * Open student note modal and refresh row label on success.
     *
     * @param {HTMLElement} trigger Action menu link element
     */
    async openStudentNoteModal(trigger) {
        const userid = parseInt(trigger.dataset.userid, 10);
        const response = await showStudentNoteModal({
            cmid: this.cmid,
            groupid: this.groupid,
            userid,
            studentname: trigger.dataset.studentname ?? '',
        });

        if (!response) {
            return;
        }

        const row = this.element.querySelector(`tr[data-userid="${userid}"]`);
        if (row) {
            const link = row.querySelector('[data-action="edit-note"]');
            if (link) {
                this.updateNoteActionLink(link, {hasnote: response.hasnote});
            }
        }

        this.poll();
    }

    /**
     * Open show-password modal and refresh on success.
     */
    async openShowPasswordModal() {
        const state = this.getState();
        await showPasswordModal({
            quizpassword: state.meta.quizpassword ?? ''
        });
    }

    /**
     * Open bulk extend modal and refresh on success.
     */
    async openBulkExtendModal() {
        const response = await showExtendModal({
            mode: 'bulk',
            cmid: this.cmid,
            groupid: this.groupid,
            inprogresscount: this.getInProgressCount(true),
        });
        if (response) {
            this.poll();
        }
    }

    /**
     * Fetch the inprogress count from the current state.
     *
     * @param {boolean} [checkmeta=false] - Whether to fall back to
     *   state.meta.inprogresscount when the summary counts are zero.
     * @returns {number} The resolved in-progress count, or 0 if none is available.
     */
    getInProgressCount(checkmeta) {
        // Shortcuts to state and summary objects.
        const state = this.getState() ?? {};
        const summary = state.summary ?? {};
        // If the summary counts are available, use those.
        const count = (summary.inprogress?.count ?? 0) + (summary.idle?.count ?? 0);
        if (count) {
            return count;
        }
        // If the meta values is available, use those.
        if (checkmeta) {
            return (state.meta?.inprogresscount ?? 0) + (state.meta?.idlecount ?? 0);
        }
        // No counts are available, return 0.
        return 0;
    }

    /**
     * Open individual extend modal for one student.
     *
     * @param {HTMLElement} trigger Action menu link element
     */
    async openIndividualExtendModal(trigger) {
        const response = await showExtendModal({
            mode: 'individual',
            cmid: this.cmid,
            groupid: this.groupid,
            userid: parseInt(trigger.dataset.userid, 10),
            studentname: trigger.dataset.studentname ?? '',
            attemptendat: parseInt(trigger.dataset.attemptendat, 10) || null,
        });
        if (response) {
            this.poll();
        }
    }

    /**
     * Open unblock confirmation modal and refresh row on success.
     *
     * @param {HTMLElement} trigger Action menu link element
     */
    async openUnblockModal(trigger) {
        const userid = parseInt(trigger.dataset.userid, 10);
        const attemptid = parseInt(trigger.dataset.attemptid, 10);
        const response = await showUnblockModal({
            cmid: this.cmid,
            userid,
            attemptid,
            studentname: trigger.dataset.studentname ?? '',
        });

        if (!response) {
            return;
        }

        this.updateRowUnblockState(userid);
        this.poll();
    }

    /**
     * Remove blocked flag and disable unblock menu item immediately after success.
     *
     * @param {number} userid Student user id
     */
    updateRowUnblockState(userid) {
        const row = this.element.querySelector(`tr[data-userid="${userid}"]`);
        if (!row) {
            return;
        }

        const flag = row.querySelector('.livequizmonitor-blocked-flag');
        if (flag) {
            flag.remove();
        }

        const unblockLink = row.querySelector('[data-action="unblock-student"]');
        if (unblockLink) {
            this.updateUnblockActionLink(unblockLink, {unblockactionenabled: false});
        }

        const students = this.getState()?.students;
        if (students instanceof Map && students.has(userid)) {
            const student = students.get(userid);
            student.isblocked = false;
            student.unblockactionenabled = false;
        }
    }

    /**
     * Enable or disable bulk extend button from reactive in-progress count.
     */
    renderBulkExtendButton() {
        const btn = this.getElement(this.selectors.EXTENDBULK);
        if (btn) {
            btn.disabled = this.getInProgressCount(false) === 0;
        }
    }

    /**
     * Bind search, status filter, and clear control events.
     */
    bindFilterEvents() {
        const searchInput = this.getElement(this.selectors.SEARCHINPUT);
        if (searchInput) {
            this.addEventListener(searchInput, 'input', this.handleSearchInput);
        }

        this.addEventListener(this.element, 'click', this.handleFilterClick);
        this.addEventListener(this.element, 'click', this.handleFlagFilterClick);

        const clearBtn = this.getElement(this.selectors.CLEARFILTERS);
        if (clearBtn) {
            this.addEventListener(clearBtn, 'click', this.handleClearFilters);
        }

        this.addEventListener(this.element, 'keydown', this.handleFilterKeydown);
    }

    /**
     * Bind sortable table header events.
     */
    bindSortEvents() {
        this.addEventListener(this.element, 'click', this.handleSortClick);
    }

    /**
     * Handle search input changes.
     *
     * @param {Event} event
     */
    handleSearchInput(event) {
        this.reactive.dispatch('setSearch', event.target.value);
        this.afterFilterChange();
    }

    /**
     * Delegate clicks on status chips and summary tiles.
     *
     * @param {Event} event
     */
    handleFilterClick(event) {
        const trigger = event.target.closest('[data-action="filter-status"]');
        if (!trigger || !this.element.contains(trigger)) {
            return;
        }
        const status = trigger.dataset.status;
        if (!status) {
            return;
        }
        event.preventDefault();
        this.reactive.dispatch('setStatusFilter', status);
        this.afterFilterChange();
    }

    /**
     * Delegate clicks on boolean flag toggle buttons (e.g. "Has extension").
     *
     * @param {Event} event
     */
    handleFlagFilterClick(event) {
        const trigger = event.target.closest('[data-action="filter-flag"]');
        if (!trigger || !this.element.contains(trigger)) {
            return;
        }
        const flag = trigger.dataset.flag;
        if (!flag) {
            return;
        }
        event.preventDefault();
        this.reactive.dispatch('setFlagFilter', flag);
        this.afterFilterChange();
    }

    /**
     * Support keyboard activation on summary tiles.
     *
     * @param {KeyboardEvent} event
     */
    handleFilterKeydown(event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        const tile = event.target.closest(this.selectors.SUMMARYTILE);
        if (!tile || !this.element.contains(tile)) {
            return;
        }
        event.preventDefault();
        const status = tile.dataset.status;
        if (status) {
            this.reactive.dispatch('setStatusFilter', status);
            this.afterFilterChange();
        }
    }

    /**
     * Reset all filters and restore the full student list.
     *
     * @param {Event} event
     */
    handleClearFilters(event) {
        event.preventDefault();
        this.reactive.dispatch('clearFilters');
        const searchInput = this.getElement(this.selectors.SEARCHINPUT);
        if (searchInput) {
            searchInput.value = '';
        }
        this.afterFilterChange();
    }

    /**
     * Re-apply all filter-driven DOM updates.
     */
    afterFilterChange() {
        this.renderFilterToolbar();
        this.applyRowVisibility();
        this.renderFilterEmpty();
    }

    /**
     * Delegate clicks on column headings.
     *
     * @param {Event} event
     */
    handleSortClick(event) {
        // Ignore clicks on the column hide/show toggle nested in the header.
        if (event.target.closest(this.selectors.COLUMNTOGGLE)) {
            return;
        }

        const trigger = event.target.closest('[data-action="sort-column"]');

        if (!trigger || !this.element.contains(trigger)) {
            return;
        }

        const column = trigger.dataset.sortColumn;

        if (!column) {
            return;
        }

        event.preventDefault();
        this.reactive.dispatch('setSort', column);
        this.poll();
    }

    /**
     * Clean up timers on destroy.
     */
    destroy() {
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
        }
        if (this.tickTimer) {
            clearInterval(this.tickTimer);
        }
        super.destroy();
    }

    /**
     * Begin Ajax polling loop.
     */
    startPolling() {
        this.poll();
        this.pollTimer = setInterval(() => this.poll(), POLL_INTERVAL_MS);
    }

    /**
     * Begin local timer countdown between polls.
     */
    startTimerTick() {
        this.tickTimer = setInterval(() => {
            this.reactive.dispatch('tickTimers');
        }, 1000);
    }

    /**
     * Poll server for fresh monitor state.
     *
     * @returns {Promise<void>}
     */
    async poll() {
        if (this.pollInFlight) {
            return;
        }

        this.pollInFlight = true;
        try {
            const state = this.getState();

            const response = await Ajax.call([{
                methodname: 'quiz_livequizmonitor_get_monitor_state',
                args: {
                    cmid: this.cmid,
                    groupid: this.groupid,
                    sortcolumn: state.meta.sortcolumn,
                    sortdirection: state.meta.sortdirection,
                },
            }])[0];

            if (response.onesessionactive !== undefined) {
                this.onesessionactive = !!response.onesessionactive;
            }

            if (response.canunblock !== undefined) {
                this.canunblock = !!response.canunblock;
            }

            this.reactive.dispatch('refreshState', response);
            this.hasReceivedPoll = true;
            this.renderCohortLayout();
            await this.syncStudentTable();
        } catch (e) {
            Notification.exception(e);
            this.reactive.dispatch('setStale', true);
        } finally {
            this.pollInFlight = false;
        }
    }

    /**
     * Update last-updated text.
     */
    renderLastUpdated() {
        const el = this.getElement(this.selectors.LASTUPDATED);
        if (!el) {
            return;
        }
        const updatedat = this.getState()?.meta?.updatedat;
        if (!updatedat) {
            return;
        }
        const date = new Date(updatedat * 1000);
        const formatted = date.toLocaleString();
        el.textContent = this.lastUpdatedPrefix ? `${this.lastUpdatedPrefix}${formatted}` : formatted;
    }

    /**
     * Toggle stale-data warning.
     */
    renderStaleIndicator() {
        const el = this.getElement(this.selectors.STALE);
        if (!el) {
            return;
        }
        el.classList.toggle('d-none', !this.getState()?.meta?.stale);
    }

    /**
     * Update summary tile counts and percentages.
     */
    renderSummary() {
        const summary = this.getState()?.summary;
        if (!summary) {
            return;
        }
        ['notstarted', 'idle', 'inprogress', 'completed'].forEach((key) => {
            const bucket = summary[key];
            if (!bucket) {
                return;
            }
            const countEl = this.element.querySelector(`[data-summary="${key}"]`);
            const pctEl = this.element.querySelector(`[data-summary-pct="${key}"]`);
            if (countEl) {
                countEl.textContent = bucket.count;
            }
            if (pctEl) {
                pctEl.textContent = `${bucket.percent}%`;
            }
        });
    }

    /**
     * Return student rows from reactive state, sorted by the active column.
     *
     * Note: the sorting algorithm here mirrors the one in "sort_student_rows()".
     * See "class/local/manager/monitor_manager.php".
     *
     * @returns {Array}
     */
    getStudentRows() {
        const state = this.getState();
        const students = state?.students;

        if (!students) {
            return [];
        }

        const rows = students instanceof Map ? [...students.values()] : [...students];

        const sortcolumn = state?.meta?.sortcolumn ?? 'status';
        const sortdirection = state?.meta?.sortdirection ?? 'asc';

        const sortable = {
            status: 'status',
            fullname: 'fullname',
            email: 'email',
            progress: 'progresspercent',
            timeremaining: 'timeremaining',
        };

        const field = sortable[sortcolumn] ?? 'status';

        const statusRank = {
            inprogress: 0,
            idle: 1,
            notstarted: 2,
            completed: 3,
        };

        rows.sort((a, b) => {
            let cmp;

            if (field === 'status') {
                cmp = (statusRank[a.status] ?? 99) - (statusRank[b.status] ?? 99);
            } else {
                const valuea = a[field] ?? null;
                const valueb = b[field] ?? null;

                if (valuea === valueb) {
                    cmp = 0;
                } else if (valuea === null) {
                    cmp = 1;
                } else if (valueb === null) {
                    cmp = -1;
                } else if (typeof valuea === 'number' && typeof valueb === 'number') {
                    cmp = valuea - valueb;
                } else {
                    cmp = String(valuea).localeCompare(String(valueb));
                }
            }

            if (sortdirection === 'desc') {
                cmp = -cmp;
            }

            // Name tie-break stays A→Z regardless of sort direction.
            if (cmp === 0) {
                cmp = String(a.fullname ?? '').localeCompare(String(b.fullname ?? ''));
            }

            return cmp;
        });

        return rows;
    }

    /**
     * Return the student table tbody element.
     *
     * @returns {HTMLElement|null}
     */
    getStudentTableBody() {
        const tableRegion = this.getElement(this.selectors.STUDENTTABLE);
        return tableRegion?.querySelector('tbody') ?? null;
    }

    /**
     * Update root column flags from the latest poll payload.
     */
    updateColumnFlags() {
        const students = this.getStudentRows();
        if (students.length === 0) {
            return;
        }
        this.showActionsColumn = true;
        this.element.dataset.showActions = '1';
        if (students[0].showemail) {
            this.showEmailColumn = true;
            this.element.dataset.showEmail = '1';
        }
    }

    /**
     * Build Mustache context for a single student row.
     *
     * @param {object} student Student state row
     * @returns {object}
     */
    buildStudentRowContext(student) {
        const canextend = !!(student.canextend ?? this.canextend);
        return {
            courseid: student.courseid ?? this.courseId,
            cmid: student.cmid ?? this.cmid,
            userid: student.userid ?? student.id,
            fullname: student.fullname,
            firstinitial: student.firstinitial,
            lastinitial: student.lastinitial,
            email: student.email ?? '',
            statusclass: student.statusclass ?? '',
            statuslabel: student.statuslabel ?? '',
            progresspercent: student.progresspercent ?? 0,
            progressbarclass: student.progressbarclass ?? '',
            progresstext: student.progresstext ?? '',
            hastimer: !!student.hastimer,
            timeremaining: student.timeremaining,
            timeremainingdisplay: student.timeremainingdisplay || formatDuration(student.timeremaining ?? 0),
            showemailcolumn: this.showEmailColumn || !!student.showemail,
            showactionscolumn: this.showActionsColumn,
            canextend,
            extendactionenabled: canextend && this.isExtendActionEnabled(student),
            onesessionactive: !!(student.onesessionactive ?? this.onesessionactive),
            unblockactionenabled: !!student.unblockactionenabled,
            isblocked: !!student.isblocked,
            hasnote: !!student.hasnote,
            hasuseroverride: !!student.hasuseroverride,
            useroverrideflaglabel: this.userOverrideFlagLabel,
            hasgroupoverride: !!student.hasgroupoverride,
            groupoverrideflaglabel: this.groupOverrideFlagLabel,
            hasusertimeoverride: !!student.hasusertimeoverride,
            hastimeoverride: !!student.hastimeoverride,
            usertimeoverrideflaglabel: this.userTimeOverrideFlagLabel,
            attemptendat: student.attemptendat ?? '',
            attemptid: student.attemptid ?? '',
            notelabel: student.hasnote ? this.noteEditLabel : this.noteAddLabel,
            extendrowlabel: this.extendRowLabel,
            unblocklabel: this.unblockRowLabel,
            blockedflaglabel: this.blockedFlagLabel,
            showattemptslabel: this.showAttemptsLabel,
            showlogslabel: this.showLogsLabel,
            canviewlogs: this.canviewlogs,
            actionsmenulabel: this.actionsMenuLabel,
            canviewattempts: this.canviewattempts,
        };
    }

    /**
     * Render and insert a student row at the given tbody index.
     *
     * @param {object} student Student state row
     * @param {number} insertIndex Target row index
     * @returns {Promise<HTMLElement|null>}
     */
    async insertStudentRow(student, insertIndex) {
        const tbody = this.getStudentTableBody();
        if (!tbody) {
            return null;
        }

        const {html, js} = await Templates.renderForPromise(
            'quiz_livequizmonitor/student_row',
            this.buildStudentRowContext(student)
        );
        if (js) {
            Templates.runTemplateJS(js);
        }

        const template = document.createElement('template');
        template.innerHTML = html.trim();
        let row = template.content.querySelector('tr[data-userid]');
        if (!row) {
            // Fallback parser for browsers that strip lone <tr> nodes.
            const table = document.createElement('table');
            table.innerHTML = html.trim();
            row = table.querySelector('tr[data-userid]');
        }
        if (!row) {
            return null;
        }

        const rows = tbody.querySelectorAll('tr[data-userid]');
        if (insertIndex >= rows.length) {
            tbody.appendChild(row);
        } else {
            tbody.insertBefore(row, rows[insertIndex]);
        }

        return row;
    }

    /**
     * Toggle empty-cohort vs cohort-content regions.
     */
    renderCohortLayout() {
        const hasstudents = !!this.getState()?.meta?.hasstudents;
        const emptyEl = this.getElement(this.selectors.EMPTYCOHORT);
        const cohortEl = this.getElement(this.selectors.COHORTCONTENT);

        if (emptyEl) {
            emptyEl.classList.toggle('d-none', hasstudents);
        }
        if (cohortEl) {
            cohortEl.classList.toggle('d-none', !hasstudents);
        }

        if (!hasstudents) {
            const tbody = this.getStudentTableBody();
            if (tbody) {
                tbody.innerHTML = '';
            }
        }
    }

    /* eslint max-depth: ["error", 5] */
    /**
     * Sync tbody rows with reactive student state (insert, remove, reorder, patch).
     *
     * @returns {Promise<void>}
     */
    async syncStudentTable() {
        if (this.syncInFlight) {
            this.syncQueued = true;
            return;
        }
        this.syncInFlight = true;
        try {
            this.updateColumnFlags();
            const students = this.getStudentRows();
            const tbody = this.getStudentTableBody();
            if (!tbody) {
                return;
            }

            const stateIds = new Set(students.map((student) => String(student.userid ?? student.id)));

            if (this.hasReceivedPoll) {
                tbody.querySelectorAll('tr[data-userid]').forEach((row) => {
                    if (!stateIds.has(row.dataset.userid)) {
                        row.remove();
                    }
                });
            }

            for (let index = 0; index < students.length; index++) {
                const student = students[index];
                const userid = String(student.userid ?? student.id);
                let row = tbody.querySelector(`tr[data-userid="${userid}"]`);

                if (!row) {
                    row = await this.insertStudentRow(student, index);
                } else {
                    const rows = [...tbody.querySelectorAll('tr[data-userid]')];
                    const currentIndex = rows.indexOf(row);
                    if (currentIndex !== index) {
                        const reference = rows[index] ?? null;
                        if (reference && reference !== row) {
                            tbody.insertBefore(row, reference);
                        } else if (!reference) {
                            tbody.appendChild(row);
                        }
                    }
                    this.updateStudentRow(row, student);
                }
            }

            this.applyRowVisibility();
            this.renderFilterEmpty();
            this.renderColumnVisibility();
        } finally {
            this.syncInFlight = false;
            if (this.syncQueued) {
                this.syncQueued = false;
                await this.syncStudentTable();
            }
        }
    }

    /**
     * Patch an existing student row from state.
     *
     * @param {HTMLElement} row Table row element
     * @param {object} student Student state row
     */
    updateStudentRow(row, student) {
        const badge = row.querySelector(this.selectors.STATUSBADGE);
        if (badge) {
            badge.textContent = student.statuslabel;
            badge.className = `badge ${student.statusclass}`;
        }
        const progress = row.querySelector(this.selectors.PROGRESS);
        if (progress) {
            const bar = row.querySelector(this.selectors.PROGRESSBAR);
            const container = row.querySelector(this.selectors.PROGRESSCONTAINER);
            const label = row.querySelector(this.selectors.PROGRESSLABEL);
            const percent = student.progresspercent ?? 0;

            if (bar) {
                bar.style.width = `${percent}%`;
                bar.className = `progress-bar ${student.progressbarclass ?? ''}`.trim();
            }
            if (container) {
                container.setAttribute('aria-valuenow', String(percent));
                if (student.progresstext) {
                    container.setAttribute('aria-label', student.progresstext);
                }
            }
            if (label) {
                label.textContent = student.progresstext ?? '';
                label.classList.toggle('d-none', !student.progresstext);
            }
        }
        const timer = row.querySelector(this.selectors.TIMER);
        if (timer) {
            if (student.hastimer && student.timeremaining !== null && student.timeremaining !== undefined) {
                timer.dataset.timeremaining = student.timeremaining;
                timer.textContent = student.timeremainingdisplay || formatDuration(student.timeremaining);
            } else {
                timer.removeAttribute('data-timeremaining');
                timer.textContent = '—';
            }
        }
        this.updateOverrideFlag(row, student);
        this.updateTimeOverrideFlag(row, student);
        this.renderRowActions(student, row);
    }

    /**
     * Toggle row visibility based on active filters.
     */
    applyRowVisibility() {
        const filters = this.getState()?.meta?.filters ?? {search: '', status: 'all'};
        this.getStudentRows().forEach((student) => {
            const userid = student.userid ?? student.id;
            const row = this.element.querySelector(`tr[data-userid="${userid}"]`);
            if (!row) {
                return;
            }
            row.classList.toggle('d-none', !matchesFilters(student, filters));
        });
        this.renderFilterEmpty();
    }

    /**
     * Sync chip/tile active state and chip count labels.
     */
    renderFilterToolbar() {
        const state = this.getState();
        if (!state) {
            return;
        }
        const activeStatus = state.meta?.filters?.status ?? 'all';
        const summary = state.summary ?? {};
        const counts = {
            all: state.meta?.totalstudents ?? 0,
            notstarted: summary.notstarted?.count ?? 0,
            idle: summary.idle?.count ?? 0,
            inprogress: summary.inprogress?.count ?? 0,
            completed: summary.completed?.count ?? 0,
        };

        this.element.querySelectorAll(this.selectors.FILTERCHIP).forEach((chip) => {
            const status = chip.dataset.status;
            const isActive = status === activeStatus;
            chip.classList.remove('active', 'livequizmonitor-filter-chip', 'btn-sm');
            chip.classList.toggle('btn-primary', isActive);
            chip.classList.toggle('btn-outline-secondary', !isActive);
            chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            const countEl = chip.querySelector(`[data-filter-count="${status}"]`);
            if (countEl && status in counts) {
                countEl.textContent = counts[status];
            }
        });

        const flagcounts = {
            useroverride: state.meta?.useroverridecount ?? 0,
            groupoverride: state.meta?.groupoverridecount ?? 0,
        };
        this.element.querySelectorAll(this.selectors.FILTERFLAG).forEach((flagbtn) => {
            const flag = flagbtn.dataset.flag;
            const isActive = !!state.meta?.filters?.[flag];
            flagbtn.classList.toggle('btn-primary', isActive);
            flagbtn.classList.toggle('btn-outline-secondary', !isActive);
            flagbtn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            if (flag in flagcounts) {
                const countEl = flagbtn.querySelector(`[data-filter-count="${flag}"]`);
                if (countEl) {
                    countEl.textContent = flagcounts[flag];
                }
            }
        });

        this.element.querySelectorAll(this.selectors.SUMMARYTILE).forEach((tile) => {
            const status = tile.dataset.status;
            const isActive = status === activeStatus;
            tile.classList.remove('btn-primary', 'btn-outline-secondary');
            tile.classList.toggle('livequizmonitor-tile-active', isActive);
            tile.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    /**
     * Show or hide the filtered-empty message vs the student table.
     */
    renderFilterEmpty() {
        const emptyEl = this.getElement(this.selectors.FILTEREMPTY);
        const tableEl = this.getElement(this.selectors.STUDENTTABLE);
        if (!emptyEl) {
            return;
        }
        const state = this.getState();
        const hasCohort = state?.meta?.hasstudents;
        const filters = state?.meta?.filters ?? {search: '', status: 'all'};
        const hasActiveFilter = filters.search !== '' || filters.status !== 'all';
        const visible = countVisible(state?.students ?? [], filters);
        const showEmpty = hasCohort && hasActiveFilter && visible === 0;

        emptyEl.classList.toggle('d-none', !showEmpty);
        if (tableEl) {
            tableEl.classList.toggle('d-none', showEmpty);
        }
    }

    /**
     * Update the sort indicators on the student table headers.
     */
    renderSortIndicators() {
        const state = this.getState();
        const sortcolumn = state?.meta?.sortcolumn;
        const sortdirection = state?.meta?.sortdirection;

        const table = this.getElement(this.selectors.STUDENTTABLE);
        if (!table) {
            return;
        }

        const headers = table.querySelectorAll(
            'th[data-sort-column]'
        );

        headers.forEach((header) => {
            const icon = header.querySelector('.icon');
            if (!icon) {
                return;
            }

            const active = header.dataset.sortColumn === sortcolumn;
            const descending = active && sortdirection === 'desc';

            icon.classList.toggle('text-primary', active);
            icon.classList.toggle('text-secondary', !active);

            icon.classList.toggle(
                'fa-arrow-up-short-wide',
                !descending
            );
            icon.classList.toggle(
                'fa-arrow-down-short-wide',
                descending
            );

            if (active) {
                const label = descending ? this.sortDescendingLabel : this.sortAscendingLabel;
                icon.setAttribute('title', label);
                icon.setAttribute('aria-label', label);
            } else {
                const label = icon.dataset.sortbyLabel;
                icon.setAttribute('title', label);
                icon.setAttribute('aria-label', label);
            }
        });
    }

    /**
     * Whether extend time is available for a student row.
     *
     * @param {object} student Student state row
     * @returns {boolean}
     */
    isExtendActionEnabled(student) {
        return student?.status === 'inprogress' || student?.status === 'idle';
    }

    /**
     * Escape text for safe HTML attribute or content insertion.
     *
     * @param {string} text Raw text
     * @returns {string}
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text ?? '';
        return div.innerHTML;
    }

    /**
     * Build row action menu markup matching row_action_menu.mustache.
     *
     * @param {object} student Student state row
     * @returns {string}
     */
    buildRowActionMenuHtml(student) {
        const userid = student.userid ?? student.id;
        const attemptendat = student.attemptendat ?? '';
        const attemptid = student.attemptid ?? '';
        const fullname = this.escapeHtml(student.fullname ?? '');
        const extendlabel = this.escapeHtml(this.extendRowLabel);
        const actionslabel = this.escapeHtml(this.actionsMenuLabel);
        const unblocklabel = this.escapeHtml(this.unblockRowLabel);
        const blockedflaglabel = this.escapeHtml(this.blockedFlagLabel);
        const logslabel = this.escapeHtml(this.showLogsLabel);
        const notelabel = this.escapeHtml(student.hasnote ? this.noteEditLabel : this.noteAddLabel);
        const hasnote = student.hasnote ? '1' : '0';
        const showextend = !!(student.canextend ?? this.canextend);
        const extendenabled = showextend && this.isExtendActionEnabled(student);
        const extenddisabledClass = extendenabled ? '' : ' disabled';
        const extenddisabledAttrs = extendenabled ? '' : ' aria-disabled="true" tabindex="-1"';
        const onesessionactive = !!(student.onesessionactive ?? this.onesessionactive);
        const unblockenabled = !!(student.unblockactionenabled);
        const unblockdisabledClass = unblockenabled ? '' : ' disabled';
        const unblockdisabledAttrs = unblockenabled ? '' : ' aria-disabled="true" tabindex="-1"';
        const isblocked = !!(student.isblocked);

        const extendItem = showextend ? `
                        <a href="#"
                            class="dropdown-item menu-action${extenddisabledClass}"
                            role="menuitem"
                            data-action="extend-individual"
                            data-userid="${userid}"
                            data-studentname="${fullname}"
                            data-attemptendat="${attemptendat}"${extenddisabledAttrs}>
                                <i class="fa-solid fa-clock" aria-hidden="true"></i>
                                <span class="menu-action-text">${extendlabel}</span>
                        </a>` : '';

        const unblockItem = onesessionactive ? `
                        <a href="#"
                            class="dropdown-item menu-action${unblockdisabledClass}"
                            role="menuitem"
                            data-action="unblock-student"
                            data-userid="${userid}"
                            data-studentname="${fullname}"
                            data-attemptid="${attemptid}"${unblockdisabledAttrs}>
                                <i class="fa-solid fa-unlock" aria-hidden="true"></i>
                                <span class="menu-action-text">${unblocklabel}</span>
                        </a>` : '';

        const flagHtml = isblocked
            ? `<i class="fa-solid fa-flag livequizmonitor-blocked-flag" title="${blockedflaglabel}" aria-hidden="true"></i>`
            : '';

        // To reduce code complexity, as measured by Grunt,
        // the attemptsItem html is built in a separate function.
        const attemptsItem = this.buildAttemptsItemHtml(student);

        const logsParams = new URLSearchParams({
            chooselog: 1,
            id: this.courseid,
            user: userid,
            modid: this.cmid,
            showusers: 0,
            showcourses: 0,
        });

        const logsItem = this.canviewlogs ? `
                        <a href="${M.cfg.wwwroot}/report/log/index.php?${logsParams.toString()}"
                           class="dropdown-item menu-action"
                           role="menuitem"
                           target="_blank">
                            <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
                            <span class="menu-action-text">${logslabel}</span>
                            <i class="fa-solid fa-up-right-from-square" aria-hidden="true"
                               title="${M.util.get_string('opensinnewwindow', 'core')}"></i>
                        </a>` : '';

        return `
            <div class="livequizmonitor-actions-inner">
                <div class="dropdown livequizmonitor-row-actions" data-region="row-actions">
                    <button type="button"
                            class="btn btn-icon dropdown-toggle no-caret d-flex align-items-center justify-content-center"
                            data-toggle="dropdown"
                            aria-haspopup="true"
                            aria-expanded="false"
                            title="${actionslabel}">
                        <i class="icon fa fa-ellipsis-vertical fa-fw" aria-hidden="true"></i>
                        <span class="sr-only">${actionslabel}</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a href="#"
                           class="dropdown-item menu-action"
                           role="menuitem"
                           data-action="edit-note"
                           data-userid="${userid}"
                           data-studentname="${fullname}"
                           data-hasnote="${hasnote}">
                            <i class="fa-solid fa-book" aria-hidden="true"></i>
                            <span class="menu-action-text">${notelabel}</span>
                        </a>${extendItem}${unblockItem}${attemptsItem}${logsItem}
                    </div>
                </div>${flagHtml}
            </div>
        `;
    }

    /**
     * Build the menu item for viewing student attempts.
     *
     * @param {object} student data about the current student.
     * @returns {string} HTML for the attempts menu item.
     */
    buildAttemptsItemHtml(student) {
        if (!this.canviewattempts) {
            return '';
        }

        const attemptsParams = new URLSearchParams({
            id: this.cmid,
            mode: 'overview',
        });

        if (student.firstinitial) {
            attemptsParams.set('tifirst', student.firstinitial);
        }

        if (student.lastinitial) {
            attemptsParams.set('tilast', student.lastinitial);
        }

        const attemptslabel = this.escapeHtml(this.showAttemptsLabel);

        return `
                        <a href="${M.cfg.wwwroot}/mod/quiz/report.php?${attemptsParams.toString()}"
                           class="dropdown-item menu-action"
                           role="menuitem"
                           target="_blank">
                            <i class="fa-solid fa-table-list" aria-hidden="true"></i>
                            <span class="menu-action-text">${attemptslabel}</span>
                            <i class="fa-solid fa-up-right-from-square" aria-hidden="true"
                               title="${M.util.get_string('opensinnewwindow', 'core')}"></i>
                        </a>`;
    }

    /**
     * Sync note menu label from hasnote state.
     *
     * @param {HTMLElement} link Note action menu item
     * @param {object} student Student state row
     */
    updateNoteActionLink(link, student) {
        const hasnote = !!student.hasnote;
        link.dataset.hasnote = hasnote ? '1' : '0';
        const labelEl = link.querySelector('.menu-action-text');
        if (labelEl) {
            labelEl.textContent = hasnote ? this.noteEditLabel : this.noteAddLabel;
        }
    }

    /**
     * Sync disabled state and data attributes on the extend menu item.
     *
     * @param {HTMLElement} link Extend action menu item
     * @param {object} student Student state row
     */
    updateExtendActionLink(link, student) {
        const enabled = (student.canextend ?? this.canextend) && this.isExtendActionEnabled(student);
        link.dataset.userid = String(student.userid ?? student.id);
        link.dataset.studentname = student.fullname ?? '';
        link.dataset.attemptendat = student.attemptendat ?? '';
        link.classList.toggle('disabled', !enabled);
        if (enabled) {
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
        } else {
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('tabindex', '-1');
        }
    }

    /**
     * Sync disabled state on the unblock menu item.
     *
     * @param {HTMLElement} link Unblock action menu item
     * @param {object} student Student state row
     */
    updateUnblockActionLink(link, student) {
        const enabled = !!(student.unblockactionenabled);
        link.dataset.userid = String(student.userid ?? student.id);
        link.dataset.studentname = student.fullname ?? '';
        link.dataset.attemptid = student.attemptid ?? '';
        link.classList.toggle('disabled', !enabled);
        if (enabled) {
            link.removeAttribute('aria-disabled');
            link.removeAttribute('tabindex');
        } else {
            link.setAttribute('aria-disabled', 'true');
            link.setAttribute('tabindex', '-1');
        }
    }

    /**
     * Show or hide the blocked flag beside the row action menu.
     *
     * @param {HTMLElement} row Table row element
     * @param {object} student Student state row
     */
    updateBlockedFlag(row, student) {
        const inner = row.querySelector('.livequizmonitor-actions-inner');
        if (!inner) {
            return;
        }

        let flag = inner.querySelector('.livequizmonitor-blocked-flag');
        if (student.isblocked) {
            if (!flag) {
                const flagTitle = this.escapeHtml(this.blockedFlagLabel);
                inner.insertAdjacentHTML('beforeend',
                    '<i class="fa-solid fa-flag livequizmonitor-blocked-flag" ' +
                    `title="${flagTitle}" aria-hidden="true"></i>`
                );
            }
        } else if (flag) {
            flag.remove();
        }
    }

    /**
     * Sync the override badges beside the student's name.
     *
     * Override precedence is resolved per setting, so a student can have
     * both a user override and a (relevant) group override at once, e.g. a
     * user override that sets only the close date alongside a group override
     * that sets only the password. Each badge is therefore shown or removed
     * on its own, and the user badge is kept ahead of the group badge to
     * match student_row.mustache.
     *
     * @param {HTMLElement} row Table row element
     * @param {object} student Student state row
     */
    updateOverrideFlag(row, student) {
        const nameCell = row.querySelector('[data-field="fullname"]');
        if (!nameCell) {
            return;
        }
        const badges = [
            {
                type: 'user',
                show: student.hasuseroverride,
                icon: 'fa-user-gear',
                label: this.userOverrideFlagLabel
            },
            {
                type: 'group',
                show: student.hasgroupoverride,
                icon: 'fa-users-gear',
                label: this.groupOverrideFlagLabel
            },
        ];
        badges.forEach(({type, show, icon, label}) => {
            const flag = nameCell.querySelector(
                `.livequizmonitor-override-flag[data-override-badge="${type}"]`
            );
            if (flag) {
                if (!show) {
                    // Flag is not required. Remove it.
                    flag.remove();
                }
                return;
            }
            // Flag doesn't exist. Show it if necessary.
            if (show) {
                const flagTitle = this.escapeHtml(label);
                const html = `<i
                    class="fa-solid ${icon} livequizmonitor-override-flag"
                    data-override-badge="${type}"
                    title="${flagTitle}"
                    aria-label="${flagTitle}"
                ></i>`;
                // Keep the user badge ahead of an existing group badge.
                const groupFlag = nameCell.querySelector(
                    '.livequizmonitor-override-flag[data-override-badge="group"]'
                );
                if (type === 'user' && groupFlag) {
                    groupFlag.insertAdjacentHTML('beforebegin', html);
                } else {
                    nameCell.insertAdjacentHTML('beforeend', html);
                }
            }
        });
    }

    /**
     * Show or hide the time-related override badge beside the timer.
     *
     * Generic: fires for a time-related override from either a user or a
     * (relevant) group override - the badge itself doesn't distinguish
     * which source it came from.
     *
     * @param {HTMLElement} row Table row element
     * @param {object} student Student state row
     */
    updateTimeOverrideFlag(row, student) {
        const timeCell = row.querySelector('[data-field="timeremaining"]');
        if (!timeCell) {
            return;
        }

        let flag = timeCell.querySelector('.livequizmonitor-override-flag-timer');
        if (!student.hastimeoverride) {
            flag?.remove();
            return;
        }

        if (!flag) {
            flag = document.createElement('i');
            flag.className = 'fa-solid fa-clock livequizmonitor-override-flag livequizmonitor-override-flag-timer';
            timeCell.append(flag);
        }

        // The label depends on whether the time override comes from a user override, a group override, or both.
        const label = student.timeoverrideflaglabel || this.userTimeOverrideFlagLabel;
        flag.setAttribute('title', label);
        flag.setAttribute('aria-label', label);
    }

    /**
     * Keep the row action menu visible and refresh extend item state.
     *
     * @param {object} student Student state row
     * @param {HTMLElement} row Table row element
     */
    renderRowActions(student, row) {
        const cell = row.querySelector('[data-field="actions"]');
        if (!cell) {
            return;
        }

        const onesessionactive = !!(student.onesessionactive ?? this.onesessionactive);
        let inner = cell.querySelector('.livequizmonitor-actions-inner');
        if (!inner) {
            cell.innerHTML = this.buildRowActionMenuHtml(student);
            return;
        }

        if (!onesessionactive) {
            const unblockLink = inner.querySelector('[data-action="unblock-student"]');
            if (unblockLink) {
                unblockLink.remove();
            }
            const flag = inner.querySelector('.livequizmonitor-blocked-flag');
            if (flag) {
                flag.remove();
            }
        } else {
            let unblockLink = inner.querySelector('[data-action="unblock-student"]');
            if (!unblockLink) {
                const menu = inner.querySelector('.dropdown-menu');
                if (menu) {
                    menu.insertAdjacentHTML('beforeend', this.buildUnblockMenuItemHtml(student));
                    unblockLink = inner.querySelector('[data-action="unblock-student"]');
                }
            }
            if (unblockLink) {
                this.updateUnblockActionLink(unblockLink, student);
            }
            this.updateBlockedFlag(row, student);
        }

        const link = inner.querySelector('[data-action="extend-individual"]');
        if (link) {
            this.updateExtendActionLink(link, student);
        }

        const notelink = inner.querySelector('[data-action="edit-note"]');
        if (notelink) {
            this.updateNoteActionLink(notelink, student);
        }
    }

    /**
     * Build only the unblock dropdown item markup.
     *
     * @param {object} student Student state row
     * @returns {string}
     */
    buildUnblockMenuItemHtml(student) {
        const userid = student.userid ?? student.id;
        const fullname = this.escapeHtml(student.fullname ?? '');
        const attemptid = student.attemptid ?? '';
        const unblocklabel = this.escapeHtml(this.unblockRowLabel);
        const enabled = !!(student.unblockactionenabled);
        const disabledClass = enabled ? '' : ' disabled';
        const disabledAttrs = enabled ? '' : ' aria-disabled="true" tabindex="-1"';

        return `
                    <a href="#"
                       class="dropdown-item menu-action${disabledClass}"
                       role="menuitem"
                       data-action="unblock-student"
                       data-userid="${userid}"
                       data-studentname="${fullname}"
                       data-attemptid="${attemptid}"${disabledAttrs}>
                        <i class="fa-solid fa-unlock" aria-hidden="true"></i>
                        <span class="menu-action-text">${unblocklabel}</span>
                    </a>`;
    }

    /**
     * Update student table rows from state.
     */
    renderStudents() {
        this.renderCohortLayout();
        this.syncStudentTable();
    }
}

/**
 * Initialise live monitor on a page region.
 *
 * @param {string} selector CSS selector for root element
 */
export const init = (selector) => {
    const root = document.querySelector(selector);
    if (!root) {
        return;
    }

    const reactive = createMonitorReactive(root);
    const cmid = root.dataset.cmid;
    const groupid = root.dataset.groupid || 0;

    new MonitorComponent({
        element: root,
        reactive,
        cmid,
        groupid,
    });
};

export default {init};

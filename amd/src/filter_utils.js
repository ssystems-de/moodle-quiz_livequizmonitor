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
 * Client-side filter helpers for the live quiz monitor.
 *
 * @module     quiz_livequizmonitor/filter_utils
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Normalise a search term for case-insensitive substring matching.
 *
 * @param {string} value Raw search input
 * @returns {string}
 */
export const normaliseSearch = (value) => {
    return (value ?? '').trim().toLowerCase();
};

/**
 * Whether a student row matches the active filter state.
 *
 * @param {object} student Student row from reactive state
 * @param {object} filters Filter state {search, status, useroverride, groupoverride}
 * @returns {boolean}
 */
export const matchesFilters = (student, filters) => {
    const search = normaliseSearch(filters?.search);
    const status = filters?.status ?? 'all';

    if (status !== 'all' && student.status !== status) {
        return false;
    }

    if (filters?.useroverride && !student.hasuseroverride) {
        return false;
    }

    if (filters?.groupoverride && !student.hasgroupoverride) {
        return false;
    }

    if (search !== '') {
        const haystack = student.searchtext ?? '';
        if (!haystack.includes(search)) {
            return false;
        }
    }

    return true;
};

/**
 * Count students visible under the current filters.
 *
 * @param {Array|Map} students Student collection
 * @param {object} filters Filter state
 * @returns {number}
 */
export const countVisible = (students, filters) => {
    const rows = students instanceof Map ? [...students.values()] : students;
    return rows.reduce((count, student) => count + (matchesFilters(student, filters) ? 1 : 0), 0);
};

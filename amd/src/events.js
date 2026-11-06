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
 * DOM events of the live quiz monitor, for scripts of other plugins.
 *
 * The events are dispatched on the monitor root element
 * ([data-region="live-quiz-monitor"]) and bubble.
 *
 * @module     quiz_livequizmonitor/events
 * @copyright  2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Event names.
 *
 * - stateRefreshed: a poll response was applied and the table is in sync; detail: {state}.
 * - rowRendered: a student row was inserted or its data changed; detail: {row, student}.
 *
 * @type {{stateRefreshed: string, rowRendered: string}}
 */
export const eventTypes = {
    stateRefreshed: 'quiz_livequizmonitor:staterefreshed',
    rowRendered: 'quiz_livequizmonitor:rowrendered',
};

/**
 * Tell listeners that the monitor applied a poll response.
 *
 * @param {HTMLElement} root Monitor root element
 * @param {object} state Read-only reactive state
 */
export const notifyStateRefreshed = (root, state) => {
    root.dispatchEvent(new CustomEvent(eventTypes.stateRefreshed, {
        bubbles: true,
        detail: {state},
    }));
};

/**
 * Tell listeners that a student row was inserted or updated.
 *
 * @param {HTMLElement} root Monitor root element
 * @param {HTMLElement} row Table row element
 * @param {object} student Student state row
 */
export const notifyRowRendered = (root, row, student) => {
    root.dispatchEvent(new CustomEvent(eventTypes.rowRendered, {
        bubbles: true,
        detail: {row, student},
    }));
};

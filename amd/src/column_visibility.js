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
 * Hide/show column helpers for the live quiz monitor table.
 *
 * This module knows nothing about which columns exist: it only ever works
 * off `data-column` / `data-colcontent` attributes already present in the
 * DOM, and a Set of hidden column ids. Adding a new column to the table
 * later needs no changes here.
 *
 * @module     quiz_livequizmonitor/column_visibility
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

const HIDDEN_COLUMNS_ATTR = 'data-hidden-columns';
const SHOWN_COLUMNS_ATTR = 'data-shown-columns';
const HIDDEN_CLASS = 'livequizmonitor-col-hidden';
const COLLAPSED_HEADER_CLASS = 'livequizmonitor-th-collapsed';

/**
 * Read the initial hidden-column preference the server embedded on the root element.
 *
 * @param {HTMLElement} root Monitor root element
 * @returns {Set<string>}
 */
export const parseHiddenColumns = (root) => parseColumnList(root, HIDDEN_COLUMNS_ATTR);

/**
 * Read the extension columns (hidden by default) the user has chosen to show.
 *
 * @param {HTMLElement} root Monitor root element
 * @returns {Set<string>}
 */
export const parseShownColumns = (root) => parseColumnList(root, SHOWN_COLUMNS_ATTR);

/**
 * Parse a JSON list of column ids from an attribute of the root element.
 *
 * @param {HTMLElement} root Monitor root element
 * @param {string} attribute Attribute name
 * @returns {Set<string>}
 */
const parseColumnList = (root, attribute) => {
    const raw = root.getAttribute(attribute) || '[]';
    try {
        const parsed = JSON.parse(raw);
        return new Set(Array.isArray(parsed) ? parsed : []);
    } catch (e) {
        return new Set();
    }
};

/**
 * Apply the current hidden-column set to every column-aware element found
 * under root: header labels, toggle buttons, and row cell content.
 *
 * Safe to call repeatedly (e.g. after each reactive row sync) since it only
 * toggles classes/attributes and never touches element structure or the
 * `data-field` hooks the reactive poller relies on.
 *
 * @param {HTMLElement} root Monitor root element
 * @param {Set<string>} hiddenColumns Column ids currently hidden
 */
export const applyColumnVisibility = (root, hiddenColumns) => {
    root.querySelectorAll('[data-colcontent]').forEach((el) => {
        el.classList.toggle(HIDDEN_CLASS, hiddenColumns.has(el.dataset.colcontent));
    });

    root.querySelectorAll('[data-action="toggle-column"]').forEach((button) => {
        const column = button.dataset.column;
        const isHidden = hiddenColumns.has(column);

        button.setAttribute('aria-pressed', isHidden ? 'false' : 'true');
        button.setAttribute('aria-expanded', isHidden ? 'false' : 'true');
        button.setAttribute('title', isHidden ? button.dataset.showlabel : button.dataset.hidelabel);

        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('fa-plus', isHidden);
            icon.classList.toggle('fa-minus', !isHidden);
        }
        const label = button.querySelector('.sr-only');
        if (label) {
            label.textContent = isHidden ? button.dataset.showlabel : button.dataset.hidelabel;
        }

        const header = button.closest('[data-column]');
        if (header) {
            header.classList.toggle(COLLAPSED_HEADER_CLASS, isHidden);
        }
    });
};

/**
 * Persist the given hidden-column set for the current user via AJAX.
 *
 * @param {number} cmid Course module id
 * @param {Set<string>} hiddenColumns Column ids to hide
 * @param {Set<string>} [shownColumns] Extension columns hidden by default that the user has shown
 * @returns {Promise<object>}
 */
export const saveHiddenColumns = (cmid, hiddenColumns, shownColumns = new Set()) => {
    return Ajax.call([{
        methodname: 'quiz_livequizmonitor_set_hidden_columns',
        args: {
            cmid,
            hidden: [...hiddenColumns],
            shown: [...shownColumns],
        },
    }])[0];
};

export default {parseHiddenColumns, parseShownColumns, applyColumnVisibility, saveHiddenColumns};

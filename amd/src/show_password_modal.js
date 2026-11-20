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
 * Show the quiz password or the Safe Exam Browser quit password in a modal popup.
 *
 * @module     quiz_livequizmonitor/student_note_modal
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Gordon Bateson <g.bateson@ucl.ac.uk>
 */

import ModalCancel from 'core/modal_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {getString} from 'core/str';

/**
 * Load the strings required by the password modal.
 *
 * @param {string} titlestringid The string id of the modal title.
 * @returns {Promise<Object>} The localised strings.
 * @property {string} modaltitle The modal title.
 * @property {string} closelabel The label for the close button.
 */
const loadStrings = async(titlestringid) => {
    const [
        modaltitle,
        closelabel,
    ] = await Promise.all([
        getString(titlestringid, 'quiz_livequizmonitor'),
        getString('closebuttontitle', 'moodle'),
    ]);

    return {
        modaltitle,
        closelabel,
    };
};

/**
 * Display a password in a modal dialog.
 *
 * The modal displays the password supplied in the configuration object and
 * provides a button to close the modal. The returned promise resolves when
 * the modal is closed or hidden.
 *
 * @param {Object} config Configuration for the password modal.
 * @param {string|null|undefined} config.password The password to display.
 * @param {string} [config.titlestringid] The string id of the modal title (default: quiz password).
 * @returns {Promise<null>} Resolves when the modal is closed or hidden.
 */
export const showPasswordModal = async(config) => {
    const strings = await loadStrings(config.titlestringid ?? 'showpassword:modaltitle');

    const {html, js} = await Templates.renderForPromise(
        'quiz_livequizmonitor/password_modal_body',
        {
            password: config.password ?? '',
        }
    );

    const modal = await ModalCancel.create({
        title: strings.modaltitle,
        body: html,
        buttons: {
            cancel: strings.closelabel,
        },
    });

    await modal.show();

    if (js) {
        Templates.runTemplateJS(js);
    }

    return new Promise((resolve) => {
        modal.getRoot().on(ModalEvents.cancel, () => {
            modal.destroy();
            resolve(null);
        });

        modal.getRoot().on(ModalEvents.hidden, () => {
            modal.destroy();
            resolve(null);
        });
    });
};

export default {showPasswordModal};

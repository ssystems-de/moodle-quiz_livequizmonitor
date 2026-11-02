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
 * Student supervision note modal.
 *
 * @module     quiz_livequizmonitor/student_note_modal
 * @copyright  2026 SSYSTEMS
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getString} from 'core/str';
import Templates from 'core/templates';

/**
 * Load modal language strings.
 *
 * @returns {Promise<object>}
 */
const loadStrings = async() => {
    const keys = [
        {key: 'notes:modaltitle', component: 'quiz_livequizmonitor'},
        {key: 'notes:modalbody', component: 'quiz_livequizmonitor'},
        {key: 'notes:save', component: 'quiz_livequizmonitor'},
        {key: 'notes:saved', component: 'quiz_livequizmonitor'},
        {key: 'notes:deleted', component: 'quiz_livequizmonitor'},
        {key: 'notes:errorload', component: 'quiz_livequizmonitor'},
    ];
    const [
        modaltitle,
        modalbody,
        save,
        saved,
        deleted,
        errorload,
    ] = await Promise.all(keys.map(({key, component}) => getString(key, component)));

    return {modaltitle, modalbody, save, saved, deleted, errorload};
};

/**
 * Fetch note content from the server.
 *
 * @param {object} config Modal configuration
 * @returns {Promise<object>}
 */
const fetchNote = async(config) => {
    return Ajax.call([{
        methodname: 'quiz_livequizmonitor_get_student_note',
        args: {
            cmid: config.cmid,
            groupid: config.groupid ?? 0,
            userid: config.userid,
        },
    }])[0];
};

/**
 * Show student note modal; returns save outcome or null when cancelled.
 *
 * @param {object} config
 * @param {number} config.cmid Course module id
 * @param {number} config.groupid Group id
 * @param {number} config.userid Target student user id
 * @param {string} config.studentname Student display name
 * @returns {Promise<object|null>}
 */
export const showStudentNoteModal = async(config) => {
    const strings = await loadStrings();
    let noteData;

    try {
        noteData = await fetchNote(config);
    } catch (error) {
        await Notification.addNotification({
            message: strings.errorload,
            type: 'error',
        });
        Notification.exception(error);
        return null;
    }

    const modaltitle = await getString('notes:modaltitle', 'quiz_livequizmonitor', config.studentname ?? '');
    const bodyContext = {
        modalbody: strings.modalbody,
        content: noteData.content ?? '',
        textareaaria: modaltitle,
        canmanage: !!noteData.canmanage,
    };

    const {html, js} = await Templates.renderForPromise(
        'quiz_livequizmonitor/student_note_modal_body',
        bodyContext
    );

    const modal = await ModalSaveCancel.create({
        title: modaltitle,
        body: html,
    });

    modal.setSaveButtonText(strings.save);
    if (!noteData.canmanage) {
        modal.setButtonDisabled('save', true);
    }
    await modal.show();

    const root = modal.getRoot()[0];
    if (js) {
        Templates.runTemplateJS(js);
    }

    const textarea = root.querySelector('[data-region="note-content"]');
    let finished = false;

    return new Promise((resolve) => {
        modal.getRoot().on(ModalEvents.save, async(event) => {
            // Keep the modal open until the request has finished, so that a failed request
            // can be retried and the result is only resolved once the server has answered.
            event.preventDefault();
            root.querySelector('[data-region="request-error"]').classList.add('d-none');
            modal.getRoot().find('[data-action="save"]').prop('disabled', true);
            const content = textarea ? textarea.value : '';

            try {
                const response = await Ajax.call([{
                    methodname: 'quiz_livequizmonitor_save_student_note',
                    args: {
                        cmid: config.cmid,
                        groupid: config.groupid ?? 0,
                        userid: config.userid,
                        content,
                    },
                }])[0];

                const message = response.hasnote ? strings.saved : strings.deleted;
                await Notification.addNotification({
                    message,
                    type: 'success',
                });

                finished = true;
                modal.destroy();
                resolve(response);
            } catch (error) {
                modal.getRoot().find('[data-action="save"]').prop('disabled', false);
                if (error && typeof error === 'object') {
                    Notification.exception(error);
                } else {
                    // The server could not be reached, so core/ajax rejected without an exception object.
                    root.querySelector('[data-region="request-error"]').classList.remove('d-none');
                }
            }
        });

        // Cancel or dismiss without saving — no AJAX, no notification.
        modal.getRoot().on(ModalEvents.hidden, () => {
            if (!finished) {
                resolve(null);
            }
        });
    });
};

export default {showStudentNoteModal};

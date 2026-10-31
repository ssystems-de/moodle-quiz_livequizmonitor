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
 * External API to save the live monitor's hidden-column preference.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use quiz_livequizmonitor\local\column_helper;

/**
 * Persists which monitor table columns the current user has hidden.
 */
class set_hidden_columns extends external_api {
    /**
     * Parameter description.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'hidden' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Hidden column id'),
                'Column ids the user has chosen to hide',
                VALUE_DEFAULT,
                []
            ),
            'shown' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Shown column id'),
                'Extension columns hidden by default that the user has chosen to show',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Save the hidden-column preference for the current user.
     *
     * @param int $cmid Course module id.
     * @param array $hidden Column ids to hide.
     * @param array $shown Default-hidden extension columns to show.
     * @return array
     */
    public static function execute(int $cmid, array $hidden, array $shown = []): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'hidden' => $hidden,
            'shown' => $shown,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('quiz/livequizmonitor:view', $context);

        $saved = column_helper::set_hidden_columns($params['hidden'], 0, $params['shown']);

        return ['hidden' => $saved, 'shown' => column_helper::get_shown_columns()];
    }

    /**
     * Return structure description.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'hidden' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Hidden column id'),
                'The saved (validated) set of hidden column ids'
            ),
            'shown' => new external_multiple_structure(
                new external_value(PARAM_ALPHANUMEXT, 'Shown column id'),
                'The saved set of shown default-hidden extension column ids'
            ),
        ]);
    }
}

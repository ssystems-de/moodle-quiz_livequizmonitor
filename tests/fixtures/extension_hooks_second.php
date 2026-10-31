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
 * Hook callbacks of the second test component (format of a db/hooks.php file).
 *
 * Registered with a lower priority than the first component, so its callbacks run second.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \quiz_livequizmonitor\hook\extend_columns::class,
        'callback' => [\quiz_livequizmonitor\tests\fixtures\extension_callbacks::class, 'extend_columns'],
        'priority' => 50,
    ],
    [
        'hook' => \quiz_livequizmonitor\hook\extend_row_highlights::class,
        'callback' => [\quiz_livequizmonitor\tests\fixtures\extension_callbacks::class, 'extend_row_highlights'],
        'priority' => 50,
    ],
    [
        'hook' => \quiz_livequizmonitor\hook\extend_row_actions::class,
        'callback' => [\quiz_livequizmonitor\tests\fixtures\extension_callbacks::class, 'extend_row_actions'],
        'priority' => 50,
    ],
];

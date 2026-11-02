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
 * External functions for live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'quiz_livequizmonitor_get_monitor_state' => [
        'classname' => 'quiz_livequizmonitor\\external\\get_monitor_state',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Returns live monitor state for a quiz.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'quiz/livequizmonitor:view',
    ],
    'quiz_livequizmonitor_extend_quiz_time' => [
        'classname' => 'quiz_livequizmonitor\\external\\extend_quiz_time',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Extends quiz time for in-progress students.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'quiz/livequizmonitor:view',
    ],
    'quiz_livequizmonitor_get_student_note' => [
        'classname' => 'quiz_livequizmonitor\\external\\get_student_note',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Returns a student supervision note for the live monitor.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'quiz/livequizmonitor:view',
    ],
    'quiz_livequizmonitor_save_student_note' => [
        'classname' => 'quiz_livequizmonitor\\external\\save_student_note',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Saves or deletes a student supervision note.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'quiz/livequizmonitor:view, quiz/livequizmonitor:managenotes',
    ],
    'quiz_livequizmonitor_unblock_student' => [
        'classname' => 'quiz_livequizmonitor\\external\\unblock_student',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Unblocks a student quiz attempt locked by onesession.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'quiz/livequizmonitor:view',
    ],
    'quiz_livequizmonitor_set_hidden_columns' => [
        'classname' => 'quiz_livequizmonitor\\external\\set_hidden_columns',
        'methodname' => 'execute',
        'classpath' => '',
        'description' => 'Saves the current user\'s hidden-column preference for the live monitor table.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'quiz/livequizmonitor:view',
    ],
];

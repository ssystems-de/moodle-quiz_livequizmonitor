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
 * Configurable extension hook callbacks for PHPUnit tests.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\tests;

use core\output\pix_icon;
use moodle_url;
use quiz_livequizmonitor\hook\before_monitor_page_render;
use quiz_livequizmonitor\hook\extend_rows;

/**
 * Callbacks whose behaviour each test sets per component in self::$config.
 *
 * Each test component registers the callbacks of its own class (see extension_callbacks_second),
 * so the callbacks know which configuration to use.
 *
 * Config per component:
 * - columns: list of [column id, label, defaulthidden]
 * - values: list of [column id, userid, value, sortvalue, ishtml]
 * - highlights: list of [userid, severity]
 * - actions: list of [userid, label, url path, withicon, newtab]
 * - throw: true to throw after the contributions were made
 */
class extension_callbacks {
    /** @var string Component whose configuration the callbacks of this class use. */
    public const COMPONENT = 'quiz_livequizmonitor';

    /** @var array[] Behaviour per component. */
    public static $config = [];

    /** @var array[] Recorded calls per hook class: list of ['component', 'userids']. */
    public static $calls = [];

    /** @var callable|null Callback run by the page hook. */
    public static $pagecallback = null;

    /**
     * Reset configuration and recorded calls.
     */
    public static function reset(): void {
        self::$config = [];
        self::$calls = [];
        self::$pagecallback = null;
    }

    /**
     * Row callback: add the configured columns, values, highlights and actions.
     *
     * @param extend_rows $hook Hook.
     */
    public static function extend_rows(extend_rows $hook): void {
        self::$calls[extend_rows::class][] = [
            'component' => static::COMPONENT,
            'userids' => $hook->get_userids(),
        ];
        $config = self::$config[static::COMPONENT] ?? [];

        foreach ($config['columns'] ?? [] as [$id, $label, $defaulthidden]) {
            $hook->add_column($id, $label, $defaulthidden);
        }
        foreach ($config['values'] ?? [] as [$id, $userid, $value, $sortvalue, $ishtml]) {
            if ($ishtml) {
                $hook->set_html_value($id, $userid, $value, $sortvalue);
            } else {
                $hook->set_value($id, $userid, $value, $sortvalue);
            }
        }
        foreach ($config['highlights'] ?? [] as [$userid, $severity]) {
            $hook->set_highlight($userid, $severity);
        }
        foreach ($config['actions'] ?? [] as [$userid, $label, $path, $withicon, $newtab]) {
            $icon = $withicon ? new pix_icon('i/info', '') : null;
            $hook->add_action($userid, $label, new moodle_url($path, ['id' => $userid]), $icon, $newtab);
        }

        if (!empty($config['throw'])) {
            throw new \coding_exception('Fixture failure in extend_rows');
        }
    }

    /**
     * Page callback.
     *
     * @param before_monitor_page_render $hook Hook.
     */
    public static function before_monitor_page_render(before_monitor_page_render $hook): void {
        self::$calls[before_monitor_page_render::class][] = ['component' => '', 'userids' => []];
        if (self::$pagecallback) {
            call_user_func(self::$pagecallback, $hook);
        }
    }
}

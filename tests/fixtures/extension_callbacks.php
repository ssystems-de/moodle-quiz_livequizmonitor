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

namespace quiz_livequizmonitor\tests\fixtures;

use core\output\pix_icon;
use moodle_url;
use quiz_livequizmonitor\hook\before_monitor_page_render;
use quiz_livequizmonitor\hook\extend_columns;
use quiz_livequizmonitor\hook\extend_row_actions;
use quiz_livequizmonitor\hook\extend_row_highlights;
use quiz_livequizmonitor\hook\row_hook_base;

/**
 * Callbacks whose behaviour each test sets per component in self::$config.
 *
 * Config per component:
 * - columns: list of [name, label, defaulthidden]
 * - values: list of [name, userid, value, sortvalue, ishtml]
 * - highlights: list of [userid, severity]
 * - actions: list of [userid, label, url path, withicon, newtab]
 * - throw: hook short name => true to throw after the contributions were made
 */
class extension_callbacks {
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
     * Record a row hook call and return the configuration of its component.
     *
     * @param row_hook_base $hook Hook.
     * @return array
     */
    protected static function start(row_hook_base $hook): array {
        self::$calls[get_class($hook)][] = [
            'component' => $hook->get_component(),
            'userids' => $hook->get_userids(),
        ];
        return self::$config[$hook->get_component()] ?? [];
    }

    /**
     * Throw if configured for this hook.
     *
     * @param array $config Component configuration.
     * @param string $hookname Hook short name.
     */
    protected static function maybe_throw(array $config, string $hookname): void {
        if (!empty($config['throw'][$hookname])) {
            throw new \coding_exception("Fixture failure in {$hookname}");
        }
    }

    /**
     * Columns callback.
     *
     * @param extend_columns $hook Hook.
     */
    public static function extend_columns(extend_columns $hook): void {
        $config = self::start($hook);
        foreach ($config['columns'] ?? [] as [$name, $label, $defaulthidden]) {
            $hook->add_column($name, $label, $defaulthidden);
        }
        foreach ($config['values'] ?? [] as [$name, $userid, $value, $sortvalue, $ishtml]) {
            if ($ishtml) {
                $hook->set_html_value($name, $userid, $value, $sortvalue);
            } else {
                $hook->set_value($name, $userid, $value, $sortvalue);
            }
        }
        self::maybe_throw($config, 'extend_columns');
    }

    /**
     * Highlights callback.
     *
     * @param extend_row_highlights $hook Hook.
     */
    public static function extend_row_highlights(extend_row_highlights $hook): void {
        $config = self::start($hook);
        foreach ($config['highlights'] ?? [] as [$userid, $severity]) {
            $hook->set_highlight($userid, $severity);
        }
        self::maybe_throw($config, 'extend_row_highlights');
    }

    /**
     * Actions callback.
     *
     * @param extend_row_actions $hook Hook.
     */
    public static function extend_row_actions(extend_row_actions $hook): void {
        $config = self::start($hook);
        foreach ($config['actions'] ?? [] as [$userid, $label, $path, $withicon, $newtab]) {
            $icon = $withicon ? new pix_icon('i/info', '') : null;
            $hook->add_action($userid, $label, new moodle_url($path, ['id' => $userid]), $icon, $newtab);
        }
        self::maybe_throw($config, 'extend_row_actions');
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

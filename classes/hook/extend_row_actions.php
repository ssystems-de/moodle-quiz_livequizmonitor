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
 * Hook to add row actions to the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

use core\output\pix_icon;
use moodle_url;

/**
 * Lets plugins add link entries to the per-student actions menu.
 *
 * Dispatched on page load and on every poll. The entries are shown after the built-in
 * entries, separated by a divider. Only plain links are supported.
 */
final class extend_row_actions extends row_hook_base {
    /** @var array[] Actions in the order they were added. */
    protected $actions = [];

    /**
     * Add a link to a student's actions menu.
     *
     * @param int $userid Student user id.
     * @param string $label Link text, already localised.
     * @param moodle_url $url Link target.
     * @param pix_icon|null $icon Optional icon shown before the label.
     * @param bool $newtab Whether the link opens in a new browser tab.
     */
    public function add_action(
        int $userid,
        string $label,
        moodle_url $url,
        ?pix_icon $icon = null,
        bool $newtab = false,
    ): void {
        if (!$this->has_component()) {
            return;
        }
        if (trim($label) === '') {
            debugging("Live quiz monitor row action from {$this->component} has no label", DEBUG_DEVELOPER);
            return;
        }
        if (!$this->is_known_user($userid)) {
            debugging("User {$userid} is not shown in the live quiz monitor", DEBUG_DEVELOPER);
            return;
        }

        $this->actions[] = [
            'component' => $this->component,
            'userid' => $userid,
            'label' => $label,
            'url' => $url->out(false),
            'iconkey' => $icon ? (string) $icon->pix : '',
            'iconcomponent' => $icon ? (string) $icon->component : '',
            'newtab' => $newtab,
        ];
    }

    /**
     * Remove the actions of a component.
     *
     * @param string $component Frankenstyle component name.
     */
    public function remove_contributions(string $component): void {
        $this->actions = array_values(array_filter(
            $this->actions,
            static fn(array $action): bool => $action['component'] !== $component
        ));
    }

    /**
     * Actions per student, in the order they were added.
     *
     * @return array[] User id => list of ['label', 'url', 'iconkey', 'iconcomponent', 'newtab'].
     */
    public function get_actions(): array {
        $result = [];
        foreach ($this->actions as $action) {
            $result[$action['userid']][] = [
                'label' => $action['label'],
                'url' => $action['url'],
                'iconkey' => $action['iconkey'],
                'iconcomponent' => $action['iconcomponent'],
                'newtab' => $action['newtab'],
            ];
        }
        return $result;
    }
}

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
 * Hook discovery for the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor;

/**
 * Lists the hooks other plugins can use to extend the live quiz monitor.
 */
class hooks implements \core\hook\discovery_agent {
    /**
     * Hooks of the live quiz monitor.
     *
     * @return array Hook class name => ['class', 'description', 'tags'].
     */
    public static function discover_hooks(): array {
        $hooks = [
            hook\extend_columns::class => 'Add columns with a value per student to the live quiz monitor.',
            hook\extend_row_highlights::class => 'Highlight student rows in the live quiz monitor with a severity.',
            hook\extend_row_actions::class => 'Add links to the per-student actions menu of the live quiz monitor.',
            hook\before_monitor_page_render::class => 'Load plugin JavaScript before the live quiz monitor page is rendered.',
        ];

        $result = [];
        foreach ($hooks as $class => $description) {
            $result[$class] = [
                'class' => $class,
                'description' => $description,
                'tags' => ['quiz_livequizmonitor'],
            ];
        }
        return $result;
    }
}

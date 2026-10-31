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
 * Hook to highlight student rows in the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

/**
 * Lets plugins highlight student rows with a severity.
 *
 * Dispatched on page load and on every poll. If several plugins set a severity for the
 * same student, the highest one wins (danger > warning > info).
 */
final class extend_row_highlights extends row_hook_base {
    /** @var string Informational highlight. */
    public const SEVERITY_INFO = 'info';

    /** @var string Warning highlight. */
    public const SEVERITY_WARNING = 'warning';

    /** @var string Danger highlight. */
    public const SEVERITY_DANGER = 'danger';

    /** @var int[] Rank of each severity, higher wins. */
    protected const RANKS = [
        self::SEVERITY_INFO => 1,
        self::SEVERITY_WARNING => 2,
        self::SEVERITY_DANGER => 3,
    ];

    /** @var array[] Severities indexed by component and user id. */
    protected $highlights = [];

    /**
     * Highlight a student's row.
     *
     * @param int $userid Student user id.
     * @param string $severity One of the SEVERITY_* constants.
     */
    public function set_highlight(int $userid, string $severity): void {
        if (!$this->has_component()) {
            return;
        }
        if (!isset(self::RANKS[$severity])) {
            debugging("Unknown live quiz monitor row severity '{$severity}' from {$this->component}", DEBUG_DEVELOPER);
            return;
        }
        if (!$this->is_known_user($userid)) {
            debugging("User {$userid} is not shown in the live quiz monitor", DEBUG_DEVELOPER);
            return;
        }

        $current = $this->highlights[$this->component][$userid] ?? null;
        if ($current === null || self::RANKS[$severity] > self::RANKS[$current]) {
            $this->highlights[$this->component][$userid] = $severity;
        }
    }

    /**
     * Remove the highlights of a component.
     *
     * @param string $component Frankenstyle component name.
     */
    public function remove_contributions(string $component): void {
        unset($this->highlights[$component]);
    }

    /**
     * Highest severity per student over all components.
     *
     * @return string[] User id => severity.
     */
    public function get_highlights(): array {
        $result = [];
        foreach ($this->highlights as $highlights) {
            foreach ($highlights as $userid => $severity) {
                if (!isset($result[$userid]) || self::RANKS[$severity] > self::RANKS[$result[$userid]]) {
                    $result[$userid] = $severity;
                }
            }
        }
        return $result;
    }
}

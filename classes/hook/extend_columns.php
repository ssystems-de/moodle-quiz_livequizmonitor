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
 * Hook to add columns to the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

use core_text;

/**
 * Lets plugins add columns with a value per student to the live quiz monitor.
 *
 * Dispatched on page load and on every poll. Columns are shown after the built-in data
 * columns and before the actions column, and can be sorted and hidden like built-in columns.
 * The full column id is "<component>-<name>", e.g. "local_sebstatus-battery".
 */
final class extend_columns extends row_hook_base {
    /** @var array[] Registered columns indexed by full column id, in registration order. */
    protected $columns = [];

    /** @var array[] Cells indexed by user id and full column id. */
    protected $cells = [];

    /**
     * Register a column.
     *
     * @param string $name Short column name, lowercase letters and digits only.
     * @param string $label Column header, already localised.
     * @param bool $defaulthidden Whether the column is hidden until the user shows it.
     */
    public function add_column(string $name, string $label, bool $defaulthidden = false): void {
        if (!$this->has_component()) {
            return;
        }
        if (!preg_match('/^[a-z0-9]+$/', $name)) {
            debugging("Invalid live quiz monitor column name '{$name}' from {$this->component}", DEBUG_DEVELOPER);
            return;
        }
        if (trim($label) === '') {
            debugging("Live quiz monitor column '{$name}' from {$this->component} has no label", DEBUG_DEVELOPER);
            return;
        }

        $id = $this->get_column_id($name);
        if (isset($this->columns[$id])) {
            debugging("Live quiz monitor column '{$id}' is already registered", DEBUG_DEVELOPER);
            return;
        }

        $this->columns[$id] = [
            'id' => $id,
            'component' => $this->component,
            'label' => $label,
            'defaulthidden' => $defaulthidden,
        ];
    }

    /**
     * Set a plain text value. The text is escaped.
     *
     * @param string $name Short column name, as passed to add_column().
     * @param int $userid Student user id.
     * @param string $text Plain text.
     * @param int|float|string|null $sortvalue Value to sort by; null sorts by the text.
     */
    public function set_value(string $name, int $userid, string $text, int|float|string|null $sortvalue = null): void {
        $this->add_cell($name, $userid, s($text), $sortvalue);
    }

    /**
     * Set an HTML value. The markup is cleaned, unsafe content (scripts, event handlers) is removed.
     *
     * @param string $name Short column name, as passed to add_column().
     * @param int $userid Student user id.
     * @param string $html HTML fragment.
     * @param int|float|string|null $sortvalue Value to sort by; null sorts by the plain text of the HTML.
     */
    public function set_html_value(string $name, int $userid, string $html, int|float|string|null $sortvalue = null): void {
        $display = format_text($html, FORMAT_HTML, ['context' => $this->get_context(), 'filter' => false]);
        $this->add_cell($name, $userid, $display, $sortvalue);
    }

    /**
     * Store a cleaned cell value.
     *
     * @param string $name Short column name.
     * @param int $userid Student user id.
     * @param string $display Safe HTML to render.
     * @param int|float|string|null $sortvalue Value to sort by.
     */
    protected function add_cell(string $name, int $userid, string $display, int|float|string|null $sortvalue): void {
        if (!$this->has_component()) {
            return;
        }
        $id = $this->get_column_id($name);
        if (!isset($this->columns[$id])) {
            debugging("Live quiz monitor column '{$id}' is not registered", DEBUG_DEVELOPER);
            return;
        }
        if (!$this->is_known_user($userid)) {
            debugging("User {$userid} is not shown in the live quiz monitor", DEBUG_DEVELOPER);
            return;
        }

        if ($sortvalue === null) {
            // Without an explicit sort value, sort by the visible text.
            $sortvalue = core_text::strtolower(trim(html_entity_decode(strip_tags($display), ENT_QUOTES, 'UTF-8')));
        }

        $this->cells[$userid][$id] = [
            'column' => $id,
            'display' => $display,
            'sortvalue' => (string) $sortvalue,
            'sortnumeric' => is_int($sortvalue) || is_float($sortvalue),
        ];
    }

    /**
     * Full column id for a short name of the current component.
     *
     * @param string $name Short column name.
     * @return string
     */
    protected function get_column_id(string $name): string {
        return $this->component . '-' . $name;
    }

    /**
     * Remove the columns and values of a component.
     *
     * @param string $component Frankenstyle component name.
     */
    public function remove_contributions(string $component): void {
        foreach ($this->columns as $id => $column) {
            if ($column['component'] !== $component) {
                continue;
            }
            unset($this->columns[$id]);
            foreach ($this->cells as $userid => $cells) {
                unset($this->cells[$userid][$id]);
            }
        }
    }

    /**
     * Registered columns in registration order.
     *
     * @return array[] List of ['id' => string, 'label' => string, 'defaulthidden' => bool].
     */
    public function get_columns(): array {
        return array_values(array_map(static fn(array $column): array => [
            'id' => $column['id'],
            'label' => $column['label'],
            'defaulthidden' => $column['defaulthidden'],
        ], $this->columns));
    }

    /**
     * Cell values per student, in column order.
     *
     * @return array[] User id => list of ['column', 'display', 'sortvalue', 'sortnumeric'].
     */
    public function get_cells(): array {
        $result = [];
        foreach ($this->cells as $userid => $cells) {
            foreach (array_keys($this->columns) as $id) {
                if (isset($cells[$id])) {
                    $result[$userid][] = $cells[$id];
                }
            }
        }
        return $result;
    }
}

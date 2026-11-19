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
 * Hook to declare filters for the filter toolbar of the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

use cm_info;
use context_module;
use core\output\pix_icon;
use quiz_livequizmonitor\local\column_helper;
use stdClass;

/**
 * Lets plugins declare filters for the filter toolbar of the live quiz monitor.
 *
 * Dispatched once per monitor state build (page load and every poll), right before extend_rows.
 * Callbacks get the course, the course module and the quiz, but no students: declare the filters
 * that make sense for this quiz, without loading per-student data. Which students match a filter
 * is set in the extend_rows hook with add_filter_match(), together with columns and highlights.
 *
 * - Each plugin gets one group in the toolbar. Declare it with add_filter_group() before its filters.
 * - Filter ids are "<component>-<name>" like column ids, e.g. "local_sebstatus-lowbattery". The
 *   component part says which group the filter belongs to.
 * - Groups and filters are shown in declaration order. New groups and filters appear after a page
 *   reload; counts and matches update on every poll.
 * - Programming errors that do not depend on data (invalid component or id, a second group for a
 *   component, a duplicate id, a filter without a group) throw a coding_exception.
 * - Empty labels may come from data, so they are ignored with a developer debugging message: the
 *   group or filter is dropped, and so are the filters of a dropped group.
 *
 * Columns are not declared here.
 */
#[\core\attribute\label('Declare filters for the filter toolbar of the live quiz monitor.')]
#[\core\attribute\tags('quiz_livequizmonitor')]
final class declare_filters {
    /**
     * @var string Pattern for the component of a filter group, e.g. "local_sebstatus".
     * Ends with \z, because "$" would accept a trailing newline.
     */
    protected const COMPONENT_PATTERN = '/^[a-z][a-z0-9_]*\z/';

    /** @var stdClass Course record. */
    protected $course;

    /** @var cm_info Quiz course module. */
    protected $cm;

    /** @var stdClass Quiz record. */
    protected $quiz;

    /** @var array[] Declared groups indexed by component, in declaration order. */
    protected $groups = [];

    /** @var array[] Declared filters indexed by id, in declaration order. */
    protected $filters = [];

    /** @var bool[] Components whose group was dropped because of an empty label, as keys. */
    protected $droppedgroups = [];

    /** @var bool[] Ids of filters dropped because of an empty label (own or group), as keys. */
    protected $droppedfilters = [];

    /**
     * Constructor.
     *
     * @param stdClass $course Course record.
     * @param cm_info $cm Quiz course module.
     * @param stdClass $quiz Quiz record.
     */
    public function __construct(stdClass $course, cm_info $cm, stdClass $quiz) {
        $this->course = $course;
        $this->cm = $cm;
        $this->quiz = $quiz;
    }

    // Monitor data.

    /**
     * Course of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_course(): stdClass {
        return $this->course;
    }

    /**
     * Course module of the monitored quiz.
     *
     * @return cm_info
     */
    public function get_cm(): cm_info {
        return $this->cm;
    }

    /**
     * Record of the monitored quiz.
     *
     * @return stdClass
     */
    public function get_quiz(): stdClass {
        return $this->quiz;
    }

    /**
     * Module context of the monitored quiz.
     *
     * @return context_module
     */
    public function get_context(): context_module {
        return context_module::instance($this->cm->id);
    }

    // Filters.

    /**
     * Declare the toolbar group of a plugin.
     *
     * @param string $component Component of the plugin, e.g. "local_sebstatus".
     * @param string $label Group label, already localised.
     * @throws \coding_exception If the component is invalid or already has a group.
     */
    public function add_filter_group(string $component, string $label): void {
        if (!preg_match(self::COMPONENT_PATTERN, $component)) {
            throw new \coding_exception("Invalid live quiz monitor filter group '{$component}', expected a component name");
        }
        if (isset($this->groups[$component]) || isset($this->droppedgroups[$component])) {
            throw new \coding_exception("Live quiz monitor filter group '{$component}' is already declared");
        }
        if (trim($label) === '') {
            // The label may come from data, so this does not break the monitor.
            debugging("Live quiz monitor filter group '{$component}' has no label and is not shown", DEBUG_DEVELOPER);
            $this->droppedgroups[$component] = true;
            return;
        }

        $this->groups[$component] = [
            'component' => $component,
            'label' => $label,
        ];
    }

    /**
     * Declare a filter in the group of the id's component.
     *
     * @param string $id Filter id "<component>-<name>", e.g. "local_sebstatus-lowbattery".
     * @param string $label Filter label, already localised.
     * @param pix_icon|null $icon Optional icon shown before the label.
     * @throws \coding_exception If the id is invalid or already declared, or its component has no group.
     */
    public function add_filter(string $id, string $label, ?pix_icon $icon = null): void {
        if (!column_helper::is_extension_column_id($id)) {
            throw new \coding_exception("Invalid live quiz monitor filter id '{$id}', expected '<component>-<name>'");
        }
        if (isset($this->filters[$id]) || isset($this->droppedfilters[$id])) {
            throw new \coding_exception("Live quiz monitor filter '{$id}' is already declared");
        }

        // The component part of the id decides the group, as for column ids.
        $component = self::get_component($id);
        if (isset($this->droppedgroups[$component])) {
            // Already reported when the group was declared.
            $this->droppedfilters[$id] = true;
            return;
        }
        if (!isset($this->groups[$component])) {
            throw new \coding_exception("Live quiz monitor filter '{$id}' has no group, declare '{$component}' first");
        }
        if (trim($label) === '') {
            // The label may come from data, so this does not break the monitor.
            debugging("Live quiz monitor filter '{$id}' has no label and is not shown", DEBUG_DEVELOPER);
            $this->droppedfilters[$id] = true;
            return;
        }

        $this->filters[$id] = [
            'id' => $id,
            'label' => $label,
            'iconkey' => $icon ? (string) $icon->pix : '',
            'iconcomponent' => $icon ? (string) $icon->component : '',
        ];
    }

    // Results, read by the monitor and by extend_rows.

    /**
     * Declared groups with their filters, in declaration order. Groups without filters are left out.
     *
     * @return array[] List of ['component', 'label', 'filters' => list of ['id', 'label', 'iconkey', 'iconcomponent']].
     */
    public function get_filter_groups(): array {
        $result = [];
        foreach ($this->groups as $component => $group) {
            $filters = array_values(array_filter(
                $this->filters,
                static fn(array $filter): bool => self::get_component($filter['id']) === $component
            ));
            if ($filters) {
                $result[] = $group + ['filters' => $filters];
            }
        }
        return $result;
    }

    /**
     * Declared filters of all groups, in declaration order.
     *
     * @return array[] Filter id => ['id' => string, 'label' => string].
     */
    public function get_filters(): array {
        return array_map(
            static fn(array $filter): array => ['id' => $filter['id'], 'label' => $filter['label']],
            $this->filters
        );
    }

    /**
     * Whether a filter is declared (and not dropped).
     *
     * @param string $id Filter id.
     * @return bool
     */
    public function is_declared_filter(string $id): bool {
        return isset($this->filters[$id]);
    }

    /**
     * Whether a filter was dropped because of an empty label, its own or its group's.
     *
     * @param string $id Filter id.
     * @return bool
     */
    public function is_dropped_filter(string $id): bool {
        return isset($this->droppedfilters[$id]);
    }

    /**
     * Component part of a filter id.
     *
     * @param string $id Valid filter id "<component>-<name>".
     * @return string
     */
    protected static function get_component(string $id): string {
        return substr($id, 0, strrpos($id, '-'));
    }
}

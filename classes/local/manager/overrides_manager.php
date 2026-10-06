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
 * Reads core {quiz_overrides} rows for the live quiz monitor.
 *
 * Shared between the user-override filter (CTP-6723) and the
 * group-override filter (CTP-6724), since both read the same table -
 * they differ only in which column (userid vs groupid) is set.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use context_module;

/**
 * Manager for {quiz_overrides} lookups.
 */
class overrides_manager {
    /**
     * Columns on {quiz_overrides} that represent an actual override value.
     *
     * A row only counts as "having an override" if at least one of these
     * is non-null. (In practice core's override form never saves a row
     * with all of these empty, but we check explicitly rather than relying
     * on that as an implementation detail of core.)
     *
     * @var string[]
     */
    protected const VALUE_COLUMNS = ['timeopen', 'timeclose', 'timelimit', 'attempts', 'password'];

    /**
     * Columns on {quiz_overrides} that relate to attempt timing.
     *
     * @var string[]
     */
    protected const TIME_COLUMNS = ['timeopen', 'timeclose', 'timelimit'];

    /**
     * Whether the viewer may see override information for this quiz.
     *
     * @param context_module $context Module context.
     * @return bool
     */
    public static function user_can_view_overrides(context_module $context): bool {
        return has_any_capability(['mod/quiz:viewoverrides', 'mod/quiz:manageoverrides'], $context);
    }

    /**
     * Load has-override flags for a set of users, keyed by userid.
     *
     * As in core, precedence is resolved per setting: a user override takes
     * precedence over group overrides only for the settings it actually sets.
     * Any setting the user override leaves unset falls through to the overrides
     * of every group the student belongs to.
     *
     * @param int $quizid Quiz instance id.
     * @param int[] $userids User ids to check.
     * @return array<int, bool|\stdClass> Map userid => false, or an object of override flags.
     */
    public static function get_override_map(int $quizid, array $userids): array {
        global $DB;

        $map = array_fill_keys($userids, false);
        if ($map === []) {
            return $map;
        }

        // Split this quiz's overrides into user overrides (for displayed students only) and group overrides.
        $useroverrides = [];
        $groupoverrides = [];
        foreach ($DB->get_records('quiz_overrides', ['quiz' => $quizid]) as $override) {
            if (!empty($override->userid)) {
                $userid = (int) $override->userid;
                if (array_key_exists($userid, $map)) {
                    $useroverrides[$userid] = $override;
                }
            } else if (!empty($override->groupid)) {
                $groupoverrides[(int) $override->groupid] = $override;
            }
        }

        $usergroups = self::get_override_group_memberships(array_keys($groupoverrides), $userids);

        foreach (array_keys($map) as $userid) {
            $usercolumns = isset($useroverrides[$userid]) ? self::get_overridden_columns($useroverrides[$userid]) : [];

            // Collect the settings overridden by any of the student's groups ...
            $groupcolumns = [];
            foreach ($usergroups[$userid] ?? [] as $groupid) {
                $groupcolumns = array_merge($groupcolumns, self::get_overridden_columns($groupoverrides[$groupid]));
            }
            // ... except those already set by the user override, which takes precedence.
            $groupcolumns = array_values(array_diff(array_unique($groupcolumns), $usercolumns));

            if ($usercolumns === [] && $groupcolumns === []) {
                continue;
            }

            $effectivecolumns = array_merge($usercolumns, $groupcolumns);
            $map[$userid] = (object) [
                'hasuseroverride' => $usercolumns !== [],
                'hasgroupoverride' => $groupcolumns !== [],
                'hastimeoverride' => array_intersect(self::TIME_COLUMNS, $effectivecolumns) !== [],
                'hasusertimeoverride' => array_intersect(self::TIME_COLUMNS, $usercolumns) !== [],
                'hasgrouptimeoverride' => array_intersect(self::TIME_COLUMNS, $groupcolumns) !== [],
            ];
        }

        return $map;
    }

    /**
     * Get the settings that an override record actually overrides.
     *
     * Core stores null for any setting the override leaves unchanged. Other values,
     * including 0 (e.g. "no time limit" or "no close date"), are genuine overrides.
     *
     * @param \stdClass $override A {quiz_overrides} record.
     * @return string[] Names of the overridden columns.
     */
    protected static function get_overridden_columns(\stdClass $override): array {
        return array_values(array_filter(
            self::VALUE_COLUMNS,
            static fn(string $column): bool => $override->$column !== null
        ));
    }

    /**
     * Get the override groups that each of the given users belongs to.
     *
     * @param int[] $groupids Ids of groups that have an override for this quiz.
     * @param int[] $userids User ids to check.
     * @return array<int, int[]> Map userid => list of groupids.
     */
    protected static function get_override_group_memberships(array $groupids, array $userids): array {
        global $DB;

        if ($groupids === [] || $userids === []) {
            return [];
        }

        [$groupsql, $groupparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED, 'groupid');
        [$usersql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');

        $memberships = [];
        $rs = $DB->get_recordset_select(
            'groups_members',
            "groupid $groupsql AND userid $usersql",
            $groupparams + $userparams,
            '',
            'id, groupid, userid'
        );
        foreach ($rs as $row) {
            $memberships[(int) $row->userid][] = (int) $row->groupid;
        }
        $rs->close();

        return $memberships;
    }
}

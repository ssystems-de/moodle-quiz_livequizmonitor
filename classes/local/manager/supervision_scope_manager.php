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
 * Supervision scope validation for Live Monitor external endpoints.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local\manager;

use cm_info;
use context_module;
use mod_quiz\local\reports\report_base;
use moodle_exception;
use stdClass;

/**
 * Shared group and cohort checks for monitor supervision actions.
 *
 * All group ids that reach the monitor (page, polling, notes, extensions, unblock)
 * must pass through {@see self::resolve_group()} so separate-groups isolation is
 * enforced in one place.
 */
class supervision_scope_manager {
    /** @var int Separate groups mode and the viewer has no accessible group. */
    public const NO_GROUPS_ALLOWED = report_base::NO_GROUPS_ALLOWED;

    /**
     * Resolve the group the current user may supervise.
     *
     * - A positive group id must be one of the viewer's allowed activity groups.
     * - 0 resolves to the viewer's active activity group (0 = all groups when permitted).
     * - In separate groups mode a viewer without a group and without accessallgroups
     *   resolves to {@see self::NO_GROUPS_ALLOWED} instead of "all groups".
     *
     * The method is idempotent: passing a resolved value returns the same value.
     *
     * @param int $groupid Requested group id.
     * @param cm_info|stdClass $cm Course module.
     * @param context_module $context Module context.
     * @return int Resolved group id, 0 for all groups, or NO_GROUPS_ALLOWED.
     * @throws moodle_exception When an explicit group is not visible to the current user.
     */
    public static function resolve_group(int $groupid, cm_info|stdClass $cm, context_module $context): int {
        if ($groupid === self::NO_GROUPS_ALLOWED) {
            return self::NO_GROUPS_ALLOWED;
        }

        if ($groupid > 0) {
            $allowedgroups = groups_get_activity_allowed_groups($cm);
            if (!isset($allowedgroups[$groupid])) {
                throw new moodle_exception('error:groupnotvisible', 'quiz_livequizmonitor');
            }
            return $groupid;
        }

        $activegroup = (int) groups_get_activity_group($cm, true);
        if (
            !$activegroup
            && groups_get_activity_groupmode($cm) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $context)
        ) {
            return self::NO_GROUPS_ALLOWED;
        }

        return $activegroup;
    }

    /**
     * Check whether a user is in the obliged cohort visible under the group filter.
     *
     * @param int $userid Target student user id.
     * @param context_module $context Module context.
     * @param int $groupid Requested group id (resolved via {@see self::resolve_group()}).
     * @param cm_info|stdClass $cm Course module.
     * @return bool
     * @throws moodle_exception When an explicit group is not visible to the current user.
     */
    public static function is_user_in_cohort(
        int $userid,
        context_module $context,
        int $groupid,
        cm_info|stdClass $cm
    ): bool {
        $groupid = self::resolve_group($groupid, $cm, $context);
        if ($groupid === self::NO_GROUPS_ALLOWED) {
            return false;
        }

        $students = monitor_manager::get_allowed_students($cm, $context, $groupid, $userid);
        return isset($students[$userid]);
    }
}

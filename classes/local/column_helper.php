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
 * Column registry and hide/show preference handling for the live quiz monitor table.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local;

/**
 * Defines the monitor table's known columns, and reads/writes the per-user
 * hide/show preference for them.
 *
 * This class is deliberately the single place that knows the set of columns.
 * Everything else (renderer, templates, JS, external API) works generically
 * off {@see self::get_columns()} and the persisted preference, so adding a
 * new column later only means adding one entry here (plus its markup).
 */
class column_helper {
    /** @var string Name of the user preference that stores hidden column ids. */
    const PREFERENCE_NAME = 'quiz_livequizmonitor_hiddencolumns';

    /**
     * Ordered registry of columns known to the monitor table.
     *
     * 'locked' columns (status, actions) can never be hidden by the user,
     * matching the always-visible columns used by other quiz reports.
     *
     * @return array<string, array{langkey: string, locked: bool}> Column id => definition.
     */
    public static function get_columns(): array {
        return [
            'status' => ['langkey' => 'table:status', 'locked' => true],
            'student' => ['langkey' => 'table:student', 'locked' => false],
            'email' => ['langkey' => 'table:email', 'locked' => false],
            'progress' => ['langkey' => 'table:progress', 'locked' => false],
            'timeremaining' => ['langkey' => 'table:timeremaining', 'locked' => false],
            'endtime' => ['langkey' => 'table:endtime', 'locked' => false],
            'actions' => ['langkey' => 'table:actions', 'locked' => true],
        ];
    }

    /**
     * Column ids that are allowed to be hidden (i.e. not locked).
     *
     * @return string[]
     */
    public static function get_toggleable_column_ids(): array {
        return array_keys(array_filter(self::get_columns(), function (array $column): bool {
            return empty($column['locked']);
        }));
    }

    /**
     * Whether the given column id is a known, toggleable column.
     *
     * @param string $columnid Column id.
     * @return bool
     */
    public static function is_toggleable_column(string $columnid): bool {
        return in_array($columnid, self::get_toggleable_column_ids(), true);
    }

    /**
     * Read the current user's hidden-column preference.
     *
     * Unknown or locked column ids are filtered out, so a stale preference
     * (e.g. left over from a since-removed column) never leaks through.
     *
     * @param int $userid User id, defaults to the current user.
     * @return string[] Hidden column ids.
     */
    public static function get_hidden_columns(int $userid = 0): array {
        global $USER;

        $userid = $userid ?: (int) $USER->id;
        $raw = get_user_preferences(self::PREFERENCE_NAME, '', $userid);
        $hidden = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($hidden)) {
            $hidden = [];
        }

        return self::filter_valid_columns($hidden);
    }

    /**
     * Persist the current user's hidden-column preference.
     *
     * @param string[] $hidden Column ids to hide.
     * @param int $userid User id, defaults to the current user.
     * @return string[] The saved (validated) hidden column ids.
     */
    public static function set_hidden_columns(array $hidden, int $userid = 0): array {
        global $USER;

        $userid = $userid ?: (int) $USER->id;
        $hidden = self::filter_valid_columns($hidden);

        set_user_preference(self::PREFERENCE_NAME, json_encode(array_values($hidden)), $userid);

        return $hidden;
    }

    /**
     * Keep only column ids that exist and are not locked.
     *
     * @param array $columnids Candidate column ids.
     * @return string[]
     */
    protected static function filter_valid_columns(array $columnids): array {
        $toggleable = self::get_toggleable_column_ids();

        $valid = array_filter($columnids, function ($columnid) use ($toggleable): bool {
            return is_string($columnid) && in_array($columnid, $toggleable, true);
        });

        return array_values(array_unique($valid));
    }
}

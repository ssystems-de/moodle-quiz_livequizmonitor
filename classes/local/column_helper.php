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
 * This class is deliberately the single place that knows the set of built-in columns.
 * Everything else (renderer, templates, JS, external API) works generically
 * off {@see self::get_columns()} and the persisted preference, so adding a
 * new column later only means adding one entry here (plus its markup).
 *
 * Other plugins add extension columns via the \quiz_livequizmonitor\hook\extend_columns hook.
 * Their ids ("<component>-<name>") are only known per request, so they are passed in where needed.
 */
class column_helper {
    /** @var string Name of the user preference that stores hidden column ids. */
    const PREFERENCE_NAME = 'quiz_livequizmonitor_hiddencolumns';

    /** @var string Name of the user preference that stores default-hidden extension columns the user showed. */
    const SHOWN_PREFERENCE_NAME = 'quiz_livequizmonitor_showncolumns';

    /** @var string Pattern of extension column ids: "<component>-<name>". */
    const EXTENSION_COLUMN_PATTERN = '/^[a-z][a-z0-9_]*-[a-z0-9]+$/';

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
     * Whether the id has the format of an extension column id ("<component>-<name>").
     *
     * @param string $columnid Column id.
     * @return bool
     */
    public static function is_extension_column_id(string $columnid): bool {
        return (bool) preg_match(self::EXTENSION_COLUMN_PATTERN, $columnid);
    }

    /**
     * Read the current user's effective set of hidden columns.
     *
     * Unknown or locked column ids are filtered out, so a stale preference
     * (e.g. left over from a since-removed column, or an extension column of an
     * uninstalled plugin) never leaks through. Extension columns that are hidden by
     * default stay hidden until the user shows them.
     *
     * @param int $userid User id, defaults to the current user.
     * @param array[] $extensioncolumns Extension columns of this request (['id', 'label', 'defaulthidden']).
     * @return string[] Hidden column ids.
     */
    public static function get_hidden_columns(int $userid = 0, array $extensioncolumns = []): array {
        $extensionids = array_column($extensioncolumns, 'id');
        $allowed = array_merge(self::get_toggleable_column_ids(), $extensionids);

        $hidden = array_filter(
            self::read_preference(self::PREFERENCE_NAME, $userid),
            static fn($columnid): bool => in_array($columnid, $allowed, true)
        );

        $shown = self::get_shown_columns($userid);
        foreach ($extensioncolumns as $column) {
            if (!empty($column['defaulthidden']) && !in_array($column['id'], $shown, true)) {
                $hidden[] = $column['id'];
            }
        }

        return array_values(array_unique($hidden));
    }

    /**
     * Read the default-hidden extension columns the user has explicitly shown.
     *
     * @param int $userid User id, defaults to the current user.
     * @return string[] Column ids.
     */
    public static function get_shown_columns(int $userid = 0): array {
        return array_values(array_filter(
            self::read_preference(self::SHOWN_PREFERENCE_NAME, $userid),
            static fn($columnid): bool => is_string($columnid) && self::is_extension_column_id($columnid)
        ));
    }

    /**
     * Persist the current user's hidden-column preference.
     *
     * Extension column ids are accepted by format only; ids of columns that no longer
     * exist are dropped when the preference is read.
     *
     * @param string[] $hidden Column ids to hide.
     * @param int $userid User id, defaults to the current user.
     * @param string[] $shown Default-hidden extension columns the user has shown.
     * @return string[] The saved (validated) hidden column ids.
     */
    public static function set_hidden_columns(array $hidden, int $userid = 0, array $shown = []): array {
        global $USER;

        $userid = $userid ?: (int) $USER->id;
        $hidden = self::filter_valid_columns($hidden);
        $shown = array_values(array_unique(array_filter(
            $shown,
            static fn($columnid): bool => is_string($columnid) && self::is_extension_column_id($columnid)
        )));

        set_user_preference(self::PREFERENCE_NAME, json_encode(array_values($hidden)), $userid);
        set_user_preference(self::SHOWN_PREFERENCE_NAME, json_encode($shown), $userid);

        return $hidden;
    }

    /**
     * Read a JSON list preference.
     *
     * @param string $name Preference name.
     * @param int $userid User id, defaults to the current user.
     * @return array Decoded list, [] if unset or invalid.
     */
    protected static function read_preference(string $name, int $userid = 0): array {
        global $USER;

        $userid = $userid ?: (int) $USER->id;
        $raw = get_user_preferences($name, '', $userid);
        $values = $raw !== '' ? json_decode($raw, true) : [];

        return is_array($values) ? $values : [];
    }

    /**
     * Keep only column ids that are toggleable built-in columns or extension column ids.
     *
     * @param array $columnids Candidate column ids.
     * @return string[]
     */
    protected static function filter_valid_columns(array $columnids): array {
        $toggleable = self::get_toggleable_column_ids();

        $valid = array_filter($columnids, function ($columnid) use ($toggleable): bool {
            return is_string($columnid)
                && (in_array($columnid, $toggleable, true) || self::is_extension_column_id($columnid));
        });

        return array_values(array_unique($valid));
    }
}

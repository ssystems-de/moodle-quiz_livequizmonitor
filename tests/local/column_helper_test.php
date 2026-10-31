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
 * Unit tests for column_helper.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\local;

use advanced_testcase;

/**
 * Tests for column_helper.
 *
 * @covers \quiz_livequizmonitor\local\column_helper
 */
final class column_helper_test extends advanced_testcase {
    /**
     * The status and actions columns are locked; everything else is not.
     */
    public function test_get_columns_locked_flags(): void {
        $columns = column_helper::get_columns();

        $this->assertTrue($columns['status']['locked']);
        $this->assertTrue($columns['actions']['locked']);
        $this->assertFalse($columns['student']['locked']);
        $this->assertFalse($columns['email']['locked']);
        $this->assertFalse($columns['progress']['locked']);
        $this->assertFalse($columns['timeremaining']['locked']);
    }

    /**
     * Toggleable ids exclude the locked columns.
     */
    public function test_get_toggleable_column_ids_excludes_locked(): void {
        $toggleable = column_helper::get_toggleable_column_ids();

        $this->assertNotContains('status', $toggleable);
        $this->assertNotContains('actions', $toggleable);
        $this->assertContains('email', $toggleable);
    }

    /**
     * A user with no saved preference has no hidden columns.
     */
    public function test_get_hidden_columns_defaults_to_empty(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertSame([], column_helper::get_hidden_columns((int) $user->id));
    }

    /**
     * Hidden columns round-trip through set/get for a given user.
     */
    public function test_set_and_get_hidden_columns_round_trip(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $saved = column_helper::set_hidden_columns(['email', 'timeremaining'], (int) $user->id);

        $this->assertEqualsCanonicalizing(['email', 'timeremaining'], $saved);
        $this->assertEqualsCanonicalizing(
            ['email', 'timeremaining'],
            column_helper::get_hidden_columns((int) $user->id)
        );
    }

    /**
     * Locked and unknown column ids are silently dropped, never persisted.
     */
    public function test_set_hidden_columns_filters_locked_and_unknown(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $saved = column_helper::set_hidden_columns(
            ['status', 'actions', 'boguscolumn', 'email'],
            (int) $user->id
        );

        $this->assertSame(['email'], $saved);
        $this->assertSame(['email'], column_helper::get_hidden_columns((int) $user->id));
    }

    /**
     * Saving an empty list clears a previously-saved preference.
     */
    public function test_set_hidden_columns_can_clear_preference(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        column_helper::set_hidden_columns(['email'], (int) $user->id);
        column_helper::set_hidden_columns([], (int) $user->id);

        $this->assertSame([], column_helper::get_hidden_columns((int) $user->id));
    }

    /**
     * Extension column ids are saved by format; ids of columns that no longer exist are dropped on read.
     */
    public function test_extension_columns_in_hidden_preference(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $saved = column_helper::set_hidden_columns(
            ['email', 'local_sebstatus-battery', 'local_gone-old', 'Bad-Id', 'nohyphen'],
            (int) $user->id
        );
        $this->assertSame(['email', 'local_sebstatus-battery', 'local_gone-old'], $saved);

        $extensioncolumns = [['id' => 'local_sebstatus-battery', 'label' => 'Battery', 'defaulthidden' => false]];
        $this->assertSame(
            ['email', 'local_sebstatus-battery'],
            column_helper::get_hidden_columns((int) $user->id, $extensioncolumns)
        );
        // Without the extension column in the request, its id is not reported as hidden.
        $this->assertSame(['email'], column_helper::get_hidden_columns((int) $user->id));
    }

    /**
     * A default-hidden extension column is hidden until the user shows it.
     */
    public function test_default_hidden_extension_column(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $extensioncolumns = [['id' => 'local_sebstatus-wifi', 'label' => 'Wi-Fi', 'defaulthidden' => true]];

        $this->assertSame(['local_sebstatus-wifi'], column_helper::get_hidden_columns((int) $user->id, $extensioncolumns));

        column_helper::set_hidden_columns([], (int) $user->id, ['local_sebstatus-wifi', 'email']);

        $this->assertSame(['local_sebstatus-wifi'], column_helper::get_shown_columns((int) $user->id));
        $this->assertSame([], column_helper::get_hidden_columns((int) $user->id, $extensioncolumns));
    }
}

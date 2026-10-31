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
 * Shared base of the per-row extension hooks of the live quiz monitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 Dennis Pfahl, ssystems GmbH <dpfahl@ssystems.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_livequizmonitor\hook;

use cm_info;
use context_module;
use stdClass;

/**
 * Base class of the hooks that are dispatched with the monitor's student list.
 *
 * The hooks are dispatched once per monitor state build (page load and every poll) with all
 * students the current user may see, so callbacks can load their data in one batch.
 *
 * Every contribution is recorded together with the component of the callback that made it.
 * The component is set by \quiz_livequizmonitor\local\extension\extension_dispatcher before
 * each callback, which lets the dispatcher drop the contributions of a callback that fails.
 */
abstract class row_hook_base {
    /** @var stdClass Course record. */
    protected $course;

    /** @var cm_info Quiz course module. */
    protected $cm;

    /** @var stdClass Quiz record. */
    protected $quiz;

    /** @var int Active group id (0 = all groups). */
    protected $groupid;

    /** @var stdClass[] Student data indexed by user id. */
    protected $students;

    /** @var string Component of the callback that is currently running. */
    protected $component = '';

    /**
     * Constructor.
     *
     * @param stdClass $course Course record.
     * @param cm_info $cm Quiz course module.
     * @param stdClass $quiz Quiz record.
     * @param int $groupid Active group id (0 = all groups).
     * @param stdClass[] $students Student data indexed by user id.
     */
    public function __construct(stdClass $course, cm_info $cm, stdClass $quiz, int $groupid, array $students) {
        $this->course = $course;
        $this->cm = $cm;
        $this->quiz = $quiz;
        $this->groupid = $groupid;
        $this->students = $students;
    }

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

    /**
     * Active group of the monitor (0 = all groups).
     *
     * @return int
     */
    public function get_groupid(): int {
        return $this->groupid;
    }

    /**
     * Students shown in the monitor for the current user, indexed by user id.
     *
     * Available fields: userid, fullname, email ('' if the user may not see it), status,
     * attemptid, attemptendat, timeremaining, progressanswered, progresstotal.
     * Note, override and blocked flags are set after the hooks and are not available.
     *
     * @return stdClass[]
     */
    public function get_students(): array {
        // Hand out copies, so one callback cannot change what the next one sees.
        return array_map(static fn(stdClass $student): stdClass => clone $student, $this->students);
    }

    /**
     * User ids of the students shown in the monitor.
     *
     * @return int[]
     */
    public function get_userids(): array {
        return array_keys($this->students);
    }

    /**
     * Set the component of the callback that runs next.
     *
     * Internal: called by the extension dispatcher before each callback.
     *
     * @param string $component Frankenstyle component name.
     */
    public function set_component(string $component): void {
        $this->component = $component;
    }

    /**
     * Component of the callback that is currently running.
     *
     * @return string Frankenstyle component name, '' outside a callback.
     */
    public function get_component(): string {
        return $this->component;
    }

    /**
     * Remove everything the given component contributed to this hook.
     *
     * Internal: called by the extension dispatcher when a callback of the component fails.
     *
     * @param string $component Frankenstyle component name.
     */
    abstract public function remove_contributions(string $component): void;

    /**
     * Whether the user id belongs to a student shown in the monitor.
     *
     * @param int $userid User id.
     * @return bool
     */
    protected function is_known_user(int $userid): bool {
        return isset($this->students[$userid]);
    }

    /**
     * Check that a contribution is made from within a dispatched callback.
     *
     * @return bool True if the current component is known.
     */
    protected function has_component(): bool {
        if ($this->component === '') {
            debugging(static::class . ' contributions are only accepted from hook callbacks', DEBUG_DEVELOPER);
            return false;
        }
        return true;
    }
}

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
 * English language strings for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['attempts:showlabel'] = 'Show attempts';
$string['columns:hidecolumn'] = 'Hide {$a} column';
$string['columns:showcolumn'] = 'Show {$a} column';
$string['emptycohort'] = 'No eligible students were found for this quiz.';
$string['error:groupnotvisible'] = 'You do not have permission to view that group in this monitor.';
$string['error:usernotvisible'] = 'The selected student is not visible in this monitor view.';
$string['extend:addtime'] = 'Add time';
$string['extend:bulklabel'] = 'Extend time';
$string['extend:confirm'] = 'Confirm — add {$a} min';
$string['extend:errornoinprogress'] = 'No students are currently in progress (or idle).';
$string['extend:errornopermission'] = 'You do not have permission to extend quiz time.';
$string['extend:mineach'] = '+{$a} min each';
$string['extend:modalbodybulk'] = 'Add time to all {$a->count} students currently taking the quiz. The quiz close time stays the same.';
$string['extend:modalbodyindividual'] = 'Grant {$a->name} extra time to finish their attempt. They will be notified instantly.';
$string['extend:modaltitle'] = 'Extend quiz time';
$string['extend:newdeadlinebulk'] = 'New deadline for {$a->count} students';
$string['extend:newdeadlineindividual'] = 'New deadline for this student';
$string['extend:rowaction'] = 'Extend time';
$string['extend:successbulk'] = 'Added {$a->minutes} minutes for {$a->count} students.';
$string['extend:successindividual'] = 'Added {$a->minutes} minutes for {$a->name}.';
$string['filter:all'] = 'All';
$string['filter:empty'] = 'No students match the current filters.';
$string['filter:filterslabel'] = 'Filters';
$string['filter:groupoverride'] = 'With group override';
$string['filter:groupoverrideflag'] = 'This student has a group override';
$string['filter:grouptimeoverrideflag'] = 'This student has a time-related group override';
$string['filter:namelabel'] = 'Name';
$string['filter:overridesgrouplabel'] = 'Overrides';
$string['filter:resetall'] = 'Reset all filters';
$string['filter:searchplaceholder'] = 'Search students…';
$string['filter:statuslabel'] = 'Status';
$string['filter:userandgrouptimeoverrideflag'] = 'This student has time-related user and group overrides';
$string['filter:useroverride'] = 'With user override';
$string['filter:useroverrideflag'] = 'This student has a user override';
$string['filter:usertimeoverrideflag'] = 'This student has a time-related user override';
$string['invalidminutes'] = 'Invalid extension duration.';
$string['invalidscope'] = 'Invalid extend scope.';
$string['lastupdated'] = 'Last updated: {$a}';
$string['liveindicator'] = 'Live';
$string['livequizmonitor'] = 'Live monitor';
$string['livequizmonitor:view'] = 'View the live quiz monitor report';
$string['livequizmonitorreport'] = 'Live monitor';
$string['logs:showlabel'] = 'Show logs';
$string['message:timeextendedbody'] = 'Your teacher added {$a->minutes} minutes to your attempt for the quiz "{$a->quizname}".';
$string['message:timeextendedsmall'] = '+{$a} min added to your quiz attempt';
$string['message:timeextendedsubject'] = 'Extra time granted for {$a}';
$string['messageprovider:timeextended'] = 'Quiz time extended notification';
$string['missinguserid'] = 'A student must be selected for individual extend.';
$string['noattempttoextend'] = 'No in-progress attempt to extend for {$a}.';
$string['noextendablelimit'] = 'Cannot extend time for {$a} — no time limit applies.';
$string['notes:addlabel'] = 'Add note';
$string['notes:cancel'] = 'Cancel';
$string['notes:deleted'] = 'Note removed.';
$string['notes:editlabel'] = 'Edit note';
$string['notes:errorload'] = 'Could not load note.';
$string['notes:errorsave'] = 'Could not save note.';
$string['notes:errortoolong'] = 'Note must be 2000 characters or fewer.';
$string['notes:modalbody'] = 'Add a supervision note for this student.';
$string['notes:modaltitle'] = 'Note for {$a}';
$string['notes:save'] = 'Save';
$string['notes:saved'] = 'Note saved.';
$string['onesession:blockedflag'] = 'Blocked by concurrent session rule';
$string['onesession:errnotinprogress'] = 'Only in-progress attempts can be unblocked.';
$string['onesession:notactive'] = 'Concurrent session rule is not enabled for this quiz.';
$string['onesession:unblockcancel'] = 'Cancel';
$string['onesession:unblockconfirm'] = 'Unblock';
$string['onesession:unblocklabel'] = 'Unblock user';
$string['onesession:unblockmodalbody'] = 'Allow this student to continue the quiz attempt on another device or browser.';
$string['onesession:unblockmodaltitle'] = 'Unblock {$a}';
$string['onesession:unblocksuccess'] = 'Student unblocked.';
$string['pluginname'] = 'Live quiz monitor';
$string['privacy:metadata'] = 'The live quiz monitor report stores supervision notes linked to students and quizzes.';
$string['privacy:metadata:notes'] = 'Student supervision notes written from the live monitor report.';
$string['privacy:metadata:notes:content'] = 'The note text.';
$string['privacy:metadata:notes:timemodified'] = 'When the note was last modified.';
$string['privacy:metadata:notes:userid'] = 'The student the note is about.';
$string['privacy:metadata:notes:usermodified'] = 'The user who last edited the note.';
$string['privacy:metadata:preference:hiddencolumns'] = 'The set of live monitor table columns you have chosen to hide.';
$string['privacy:metadata:preference:hiddencolumns_desc'] = 'Hidden live monitor columns: {$a}';
$string['progressanswered'] = '{$a->answered} of {$a->total} answered';
$string['showpassword:label'] = 'Show quiz password';
$string['showpassword:modaltitle'] = 'Quiz password';
$string['sortby'] = 'Sort by {$a}';
$string['staleindicator'] = 'Updates paused — showing last known data';
$string['status:completed'] = 'Completed';
$string['status:idle'] = 'Idle';
$string['status:inprogress'] = 'In progress';
$string['status:notstarted'] = 'Not started';
$string['summary:completed'] = 'Completed';
$string['summary:idle'] = 'Idle';
$string['summary:inprogress'] = 'In progress';
$string['summary:notstarted'] = 'Not started';
$string['table:actions'] = 'Actions';
$string['table:email'] = 'Email';
$string['table:progress'] = 'Progress';
$string['table:status'] = 'Status';
$string['table:student'] = 'Student';
$string['table:timeremaining'] = 'Time left';
$string['timeup'] = 'Time up';

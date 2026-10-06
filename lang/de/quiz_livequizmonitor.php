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
 * German language strings for quiz_livequizmonitor.
 *
 * @package   quiz_livequizmonitor
 * @copyright 2026 SSYSTEMS
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['attempts:showlabel'] = 'Versuche anzeigen';
$string['columns:hidecolumn'] = 'Spalte „{$a}“ ausblenden';
$string['columns:showcolumn'] = 'Spalte „{$a}“ einblenden';
$string['emptycohort'] = 'Für dieses Quiz wurden keine berechtigten Teilnehmenden gefunden.';
$string['error:groupnotvisible'] = 'Sie haben keine Berechtigung, diese Gruppe in diesem Monitor anzuzeigen.';
$string['error:usernotvisible'] = 'Die ausgewählte Person ist in dieser Monitor-Ansicht nicht sichtbar.';
$string['extend:addtime'] = 'Zeit hinzufügen';
$string['extend:bulklabel'] = 'Zeit verlängern';
$string['extend:confirm'] = 'Bestätigen — {$a} Min. hinzufügen';
$string['extend:errornoinprogress'] = 'Derzeit sind keine Studierenden in Bearbeitung (oder im Leerlauf).';
$string['extend:errornopermission'] = 'Sie haben keine Berechtigung, die Quiz-Zeit zu verlängern.';
$string['extend:mineach'] = '+{$a} Min. je Person';
$string['extend:modalbodybulk'] = 'Allen {$a->count} Teilnehmenden, die das Quiz gerade bearbeiten, wird Zeit hinzugefügt. Die Quiz-Schließzeit bleibt unverändert.';
$string['extend:modalbodyindividual'] = '{$a->name} erhält zusätzliche Zeit zum Abschluss des Versuchs. Die Person wird sofort benachrichtigt.';
$string['extend:modaltitle'] = 'Quiz-Zeit verlängern';
$string['extend:newdeadlinebulk'] = 'Neue Frist für {$a->count} Teilnehmende';
$string['extend:newdeadlineindividual'] = 'Neue Frist für diese Person';
$string['extend:rowaction'] = 'Zeit verlängern';
$string['extend:successbulk'] = '{$a->minutes} Minuten für {$a->count} Teilnehmende hinzugefügt.';
$string['extend:successindividual'] = '{$a->minutes} Minuten für {$a->name} hinzugefügt.';
$string['filter:all'] = 'Alle';
$string['filter:empty'] = 'Keine Teilnehmenden entsprechen den aktuellen Filtern.';
$string['filter:filterslabel'] = 'Filter';
$string['filter:groupoverride'] = 'Mit Gruppenänderung';
$string['filter:groupoverrideflag'] = 'Für diese Person gilt eine Gruppenänderung';
$string['filter:grouptimeoverrideflag'] = 'Für diese Person gilt eine zeitbezogene Gruppenänderung';
$string['filter:namelabel'] = 'Name';
$string['filter:overridesgrouplabel'] = 'Änderungen';
$string['filter:resetall'] = 'Alle Filter zurücksetzen';
$string['filter:searchplaceholder'] = 'Teilnehmende suchen…';
$string['filter:statuslabel'] = 'Status';
$string['filter:userandgrouptimeoverrideflag'] = 'Für diese Person gelten zeitbezogene Nutzer- und Gruppenänderungen';
$string['filter:useroverride'] = 'Mit Nutzeränderung';
$string['filter:useroverrideflag'] = 'Für diese Person gilt eine Nutzeränderung';
$string['filter:usertimeoverrideflag'] = 'Für diese Person gilt eine zeitbezogene Nutzeränderung';
$string['invalidminutes'] = 'Ungültige Verlängerungsdauer.';
$string['invalidscope'] = 'Ungültiger Verlängerungsumfang.';
$string['lastupdated'] = 'Zuletzt aktualisiert: {$a}';
$string['liveindicator'] = 'Live';
$string['livequizmonitor'] = 'Live-Monitor';
$string['livequizmonitor:view'] = 'Live-Quiz-Monitor-Bericht anzeigen';
$string['livequizmonitorreport'] = 'Live-Monitor';
$string['logs:showlabel'] = 'Logs anzeigen';
$string['message:timeextendedbody'] = 'Ihre Lehrperson hat {$a->minutes} Minuten zu Ihrem Versuch für das Quiz „{$a->quizname}“ hinzugefügt.';
$string['message:timeextendedsmall'] = '+{$a} Min. zu Ihrem Quiz-Versuch hinzugefügt';
$string['message:timeextendedsubject'] = 'Zusätzliche Zeit für {$a}';
$string['messageprovider:timeextended'] = 'Benachrichtigung über verlängerte Quiz-Zeit';
$string['missinguserid'] = 'Für die Einzelverlängerung muss eine Person ausgewählt werden.';
$string['noattempttoextend'] = 'Kein laufender Versuch zum Verlängern für {$a}.';
$string['noextendablelimit'] = 'Zeit kann für {$a} nicht verlängert werden — es gilt kein Zeitlimit.';
$string['notes:addlabel'] = 'Notiz hinzufügen';
$string['notes:cancel'] = 'Abbrechen';
$string['notes:deleted'] = 'Notiz entfernt.';
$string['notes:editlabel'] = 'Notiz bearbeiten';
$string['notes:errorload'] = 'Notiz konnte nicht geladen werden.';
$string['notes:errorsave'] = 'Notiz konnte nicht gespeichert werden.';
$string['notes:errortoolong'] = 'Die Notiz darf höchstens 2000 Zeichen lang sein.';
$string['notes:modalbody'] = 'Supervisionsnotiz für diese Person hinzufügen.';
$string['notes:modaltitle'] = 'Notiz für {$a}';
$string['notes:save'] = 'Speichern';
$string['notes:saved'] = 'Notiz gespeichert.';
$string['onesession:blockedflag'] = 'Durch Regel für gleichzeitige Sitzungen blockiert';
$string['onesession:errnotinprogress'] = 'Nur laufende Versuche können entsperrt werden.';
$string['onesession:notactive'] = 'Die Regel für gleichzeitige Sitzungen ist für dieses Quiz nicht aktiv.';
$string['onesession:unblockcancel'] = 'Abbrechen';
$string['onesession:unblockconfirm'] = 'Entsperren';
$string['onesession:unblocklabel'] = 'Nutzer entsperren';
$string['onesession:unblockmodalbody'] = 'Diese Person darf den Quiz-Versuch auf einem anderen Gerät oder Browser fortsetzen.';
$string['onesession:unblockmodaltitle'] = '{$a} entsperren';
$string['onesession:unblocksuccess'] = 'Person entsperrt.';
$string['pluginname'] = 'Live-Quiz-Monitor';
$string['privacy:metadata'] = 'Der Live-Quiz-Monitor speichert Supervisionsnotizen zu Teilnehmenden und Quizzes.';
$string['privacy:metadata:notes'] = 'Supervisionsnotizen aus dem Live-Monitor-Bericht.';
$string['privacy:metadata:notes:content'] = 'Der Notiztext.';
$string['privacy:metadata:notes:timemodified'] = 'Zeitpunkt der letzten Änderung.';
$string['privacy:metadata:notes:userid'] = 'Die Person, auf die sich die Notiz bezieht.';
$string['privacy:metadata:notes:usermodified'] = 'Die Person, die die Notiz zuletzt bearbeitet hat.';
$string['privacy:metadata:preference:hiddencolumns'] = 'Die Tabellenspalten des Live-Monitors, die Sie ausgeblendet haben.';
$string['privacy:metadata:preference:hiddencolumns_desc'] = 'Ausgeblendete Spalten des Live-Monitors: {$a}';
$string['progressanswered'] = '{$a->answered} von {$a->total} beantwortet';
$string['showpassword:label'] = 'Quiz-Passwort anzeigen';
$string['showpassword:modaltitle'] = 'Quiz-Passwort';
$string['sortby'] = 'Sortieren nach {$a}';
$string['staleindicator'] = 'Aktualisierung pausiert — letzte bekannte Daten werden angezeigt';
$string['status:completed'] = 'Abgeschlossen';
$string['status:idle'] = 'Inaktiv';
$string['status:inprogress'] = 'In Bearbeitung';
$string['status:notstarted'] = 'Nicht begonnen';
$string['summary:completed'] = 'Abgeschlossen';
$string['summary:idle'] = 'Inaktiv';
$string['summary:inprogress'] = 'In Bearbeitung';
$string['summary:notstarted'] = 'Nicht begonnen';
$string['table:actions'] = 'Aktionen';
$string['table:email'] = 'E-Mail';
$string['table:progress'] = 'Fortschritt';
$string['table:status'] = 'Status';
$string['table:student'] = 'Teilnehmende';
$string['table:timeremaining'] = 'Verbleibende Zeit';
$string['timeup'] = 'Zeit abgelaufen';

moodle-quiz_livequizmonitor
===========================

[![Moodle Plugin CI](https://github.com/ssystems-de/moodle-quiz_livequizmonitor/actions/workflows/moodle-plugin-ci.yml/badge.svg?branch=main)](https://github.com/ssystems-de/moodle-quiz_livequizmonitor/actions?query=workflow%3A%22Moodle+Plugin+CI%22+branch%3Amain)

Moodle quiz report subplugin that gives teachers a real-time view of quiz attempts in progress — who is attempting, live progress, time remaining, and per-student supervision tools (notes, extend time, and related actions).

![The Live Monitor report showing attempt status counts and a table of students with their live progress and time remaining](docs/screenshot.png)


Requirements
------------

This plugin requires Moodle 4.5+


Motivation for this plugin
--------------------------

During supervised or high-stakes quizzes, teachers need a fast overview of who has started, who is still working, who may need help, and how much time is left — without opening every attempt individually. Moodle's built-in quiz reports are strong for after-the-fact analysis, but less suited to live invigilation.

This plugin adds a Live Monitor report that refreshes while the quiz runs, so supervisors can follow progress, filter the cohort, leave short notes, and take support actions (such as extending time) from one place.


Installation
------------

Install the plugin like any other plugin to folder
/mod/quiz/report/livequizmonitor

See http://docs.moodle.org/en/Installing_plugins for details on installing Moodle plugins


Usage & Settings
----------------

After installing the plugin, it is ready to use without the need for any configuration.

1. Open a quiz activity.
2. Go to the quiz **Results** tabs.
3. Select **Live Monitor**.

The report shows the current activity (and selected group, if group mode is used) and refreshes as students progress.

If you want to learn more about using quiz report plugins in Moodle, please see https://docs.moodle.org/en/Quiz_reports.


Capabilities
------------

This plugin also introduces these additional capabilities:

### quiz/livequizmonitor:view

Allows viewing the Live Monitor report for a quiz. It is assigned to the teacher, editing teacher, and manager roles by default (cloned from `mod/quiz:viewreports`). The capability carries the `RISK_PERSONAL` flag because the report exposes personal information about students.


Scheduled Tasks
---------------

This plugin does not add any additional scheduled tasks.


How this plugin works
---------------------

The Live Monitor is a quiz report page backed by AJAX/web services. On load and on a short poll interval it fetches a monitor state for the quiz (and optional group): status summary tiles, a filterable student table, progress, and time remaining.

Typical statuses include not started, in progress, idle (no recent attempt activity), and completed. From each student row, authorised users can open actions such as writing a supervision note or extending quiz time. Optional integrations (for example One Session unblock, or showing the quiz password) appear when the related plugins/settings are available.


Extending the monitor
---------------------

Other plugins can add information and links to the Live Monitor without patching it, using the Moodle Hooks API. All hooks are listed under *Site administration > Development > Hooks overview* (tag `quiz_livequizmonitor`).

| Hook | Runs | Lets you |
|------|------|----------|
| `\quiz_livequizmonitor\hook\extend_columns` | page load and every poll | add columns with a value per student |
| `\quiz_livequizmonitor\hook\extend_row_highlights` | page load and every poll | highlight rows as `info`, `warning` or `danger` |
| `\quiz_livequizmonitor\hook\extend_row_actions` | page load and every poll | add links to the per-student actions menu |
| `\quiz_livequizmonitor\hook\before_monitor_page_render` | page load | load your own JavaScript on the monitor page |

The three row hooks give you `get_course()`, `get_cm()`, `get_quiz()`, `get_context()`, `get_groupid()`, `get_userids()` and `get_students()`. The students are only those the current user may see (groups and access restrictions are applied). Available student fields: `userid`, `fullname`, `email` (empty if the user may not see it), `status`, `attemptid`, `attemptendat`, `timeremaining`, `progressanswered`, `progresstotal`.

### Minimal example

```php
// local/sebstatus/db/hooks.php
$callbacks = [
    [
        'hook' => \quiz_livequizmonitor\hook\extend_columns::class,
        'callback' => [\local_sebstatus\monitor_callbacks::class, 'extend_columns'],
    ],
];
```

```php
// local/sebstatus/classes/monitor_callbacks.php
namespace local_sebstatus;

class monitor_callbacks {
    public static function extend_columns(\quiz_livequizmonitor\hook\extend_columns $hook): void {
        $hook->add_column('battery', get_string('battery', 'local_sebstatus'));
        // One query for all students of this request.
        foreach (store::get_battery_levels($hook->get_userids()) as $userid => $level) {
            $hook->set_value('battery', $userid, $level . ' %', $level);
        }
    }
}
```

Highlights and actions work the same way:

```php
$hook->set_highlight($userid, \quiz_livequizmonitor\hook\extend_row_highlights::SEVERITY_DANGER);

$hook->add_action($userid, get_string('connectionlog', 'local_sebstatus'),
    new \moodle_url('/local/sebstatus/log.php', ['userid' => $userid]),
    new \core\output\pix_icon('i/info', ''), true);
```

### Rules

- **Column ids** are `<component>-<name>`, e.g. `local_sebstatus-battery`. `name` may only contain lowercase letters and digits. Columns appear after the built-in columns, before *Actions*, and can be sorted and hidden like built-in columns. Pass `defaulthidden: true` to `add_column()` to hide a column until the user shows it.
- **Values**: `set_value()` takes plain text (escaped). `set_html_value()` takes HTML, which is cleaned (no scripts or event handlers). The optional sort value can be a number or text; without it, rows sort by the visible text. Rows without a value sort last.
- **Highlights**: if several plugins highlight the same row, the highest severity wins (`danger` > `warning` > `info`).
- **Actions** are plain links (`moodle_url`), shown after the built-in entries. Interactive actions are not supported; use the page hook and your own JavaScript instead.
- **Errors**: if your callback throws an exception, everything it added in that hook is dropped and a developer debugging message is shown. Other plugins and the monitor keep working.
- **Performance**: the row hooks run on every poll, about every 5 seconds for every open monitor. Load your data for all students in one batch and cache external data.
- **Permissions**: hooks only run for users with `quiz/livequizmonitor:view`. Add your own capability checks inside your callback if needed.
- **New columns** appear after a page reload; the table header is not rebuilt during polling.

### JavaScript

Load your module in the page hook:

```php
public static function before_monitor_page_render(\quiz_livequizmonitor\hook\before_monitor_page_render $hook): void {
    $hook->get_page()->requires->js_call_amd('local_sebstatus/monitor_gauge', 'init');
}
```

The monitor dispatches these events on its root element `[data-region="live-quiz-monitor"]`:

| Event | When | `event.detail` |
|-------|------|----------------|
| `quiz_livequizmonitor:staterefreshed` | after each poll, once the table is in sync | `{state}` |
| `quiz_livequizmonitor:rowrendered` | after a row was inserted or its data changed | `{row, student}` |

```js
import {eventTypes} from 'quiz_livequizmonitor/events';

export const init = () => {
    const root = document.querySelector('[data-region="live-quiz-monitor"]');
    root?.addEventListener(eventTypes.rowRendered, ({detail}) => {
        const cell = detail.row.querySelector('[data-colcontent="local_sebstatus-battery"]');
        // Decorate your own cell.
    });
};
```

For watchers on the monitor's reactive state, use `getReactive()` from `quiz_livequizmonitor/monitor`. It returns a promise that resolves once the monitor is initialised. Treat the state as read-only and do not change the monitor's own markup.

Theme support
-------------

This plugin is developed and tested on Moodle Core's Boost theme.
It should also work with Boost child themes, including Moodle Core's Classic theme. However, we can't support any other theme than Boost.


Plugin repositories
-------------------

This plugin is published and regularly updated in the Moodle plugins repository:
https://marketplace.moodle.com/plugins/quiz_livequizmonitor

The latest development version can be found on Github:
https://github.com/ssystems-de/moodle-quiz_livequizmonitor


Bug and problem reports
-----------------------

This plugin is carefully developed and thoroughly tested, but bugs and problems can always appear.

Please report bugs and problems on Github:
https://github.com/ssystems-de/moodle-quiz_livequizmonitor/issues


Community feature proposals
---------------------------

The functionality of this plugin is primarily implemented for the needs of our clients and published as-is to the community. We are aware that members of the community will have other needs and would love to see them solved by this plugin.

Please issue feature proposals on Github:
https://github.com/ssystems-de/moodle-quiz_livequizmonitor/issues

Please create pull requests on Github:
https://github.com/ssystems-de/moodle-quiz_livequizmonitor/pulls


Paid support
------------

We are always interested to read about your issues and feature proposals or even get a pull request from you on Github. However, please note that our time for working on community Github issues is limited.

As solution provider, we also offer paid support for this plugin. If you are interested, please have a look at our services on [ssystems.de](https://www.ssystems.de/) or get in touch with us directly via vertrieb@ssystems.de.


Moodle release support
----------------------

This plugin is only maintained for the most recent major release of Moodle as well as the most recent LTS release of Moodle. Bugfixes are backported to the LTS release. However, new features and improvements are not necessarily backported to the LTS release.

Apart from these maintained releases, previous versions of this plugin which work in legacy major releases of Moodle are still available as-is without any further updates in the Moodle Plugins repository.

There may be several weeks after a new major release of Moodle has been published until we can do a compatibility check and fix problems if necessary. If you encounter problems with a new major release of Moodle - or can confirm that this plugin still works with a new major release - please let us know on Github.

If you are running a legacy version of Moodle, but want or need to run the latest version of this plugin, you can get the latest version of the plugin, remove the line starting with $plugin->requires from version.php and use this latest plugin version then on your legacy Moodle. However, please note that you will run this setup completely at your own risk. We can't support this approach in any way and there is an undeniable risk for erratic behavior.


Translating this plugin
-----------------------

This Moodle plugin is shipped with an english language pack only. All translations into other languages must be managed through AMOS (https://lang.moodle.org) by what they will become part of Moodle's official language pack.

As the plugin creator, we manage the translation into german for our own local needs on AMOS. Please contribute your translation into all other languages in AMOS where they will be reviewed by the official language pack maintainers for Moodle.


Right-to-left support
---------------------

This plugin has not been tested with Moodle's support for right-to-left (RTL) languages.
If you want to use this plugin with a RTL language and it doesn't work as-is, you are free to send us a pull request on Github with modifications.


Credits
-------

This plugin was developed as a MoodleMoot DACH 2026 project and won first prize.

The project team was formed of people from [ssystems GmbH](https://www.ssystems.de/) and [UCL](https://www.ucl.ac.uk/).


Maintainers
-----------

The plugin is maintained by\
ssystems GmbH


Copyright
---------

The copyright of this plugin is held by\
ssystems GmbH

Individual copyrights of individual developers are tracked in PHPDoc comments and Git commits.

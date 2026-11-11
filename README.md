# Kimai MCP server

An MCP server inside [Kimai](https://www.kimai.org), so Claude and other MCP clients can read logged time, budgets and planning, and make the changes you approve, with Kimai's own permissions deciding what each caller may see and do.

**Status: in development.** Read tools for time, absence, utilisation and budgets, write tools for logging time and absence and archiving customers and projects, and Weekly Planner tools work. Not released yet.

## How it works

- The endpoint is `POST /api/mcp`, behind Kimai's API firewall: callers authenticate with a normal Kimai API token (`Authorization: Bearer …`), and every tool runs as that user.
- Built on the official [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk). Kimai never installs a plugin's Composer dependencies, so the SDK ships inside the plugin in `vendor-prefixed/`, with every namespace prefixed by [Strauss](https://github.com/BrianHenryIE/strauss) so it cannot clash with Kimai's own libraries.
- Sessions are kept in the database, so the server works behind a load balancer without sticky sessions. A session belongs to the user whose token started it.
- Two permissions, given to super admins by default: `mcp_access` (use the endpoint) and `mcp_write` (tools that change data).

## Tools

Every tool only shows what the calling user may see in Kimai itself, through Kimai's own queries and permission checks. Without permission to view other people's timesheets, a user only ever sees their own time. Dates are `YYYY-MM-DD` and times are local to the user's timezone.

| Tool | Answers | Needs |
| :--- | :--- | :--- |
| `whoami` | Who the connection acts as, their timezone, and the tools they may call | |
| `find_customers` | Customers by name, number or company, with their IDs and, where you may see details, custom fields | |
| `find_projects` | Projects by name, optionally within customers, with dates and budget type | |
| `find_activities` | Activities of projects, global activities, or the absence types | |
| `find_users` | People by name, username or email, with their teams | `view_user` |
| `search_timesheets` | Individual entries, filtered and paged, with totals for everything that matched | `view_own_timesheet` or `view_other_timesheet` |
| `time_summary` | Hours grouped by customer, project, activity, user, date, week, month or category, split into billable, exported and not yet invoiced | as above |
| `uninvoiced_time` | Billable time not yet exported, per customer and project | `view_other_timesheet` |
| `absence` | Absence per person and type in a period | `view_own_timesheet` or `view_other_timesheet` |
| `utilisation` | Logged time against each person's work contract (or the organisation's default week, where a plugin supplies one): absence, worked and billable hours and their shares | as above |
| `project_budget`, `customer_budget` | A budget, used and remaining, monthly or in total, as on the entity's page in Kimai; a recurring budget also shows its current interval | the budget permission (global or team) |
| `budget_overview` | Every project with a budget, closest to running out first (a recurring budget by its current interval) | as above |

Amounts appear only where you may see rates, and only per customer currency.

### Tools that change data

Every write tool also needs `mcp_write`, and the server enforces confirmation for every client: called without `user_confirmed: true`, a write tool changes nothing and returns a preview of exactly what would happen, for the model to describe and ask about. Everything is checked before the preview, Kimai's own validation included, so a preview that reads fine is a change that goes through. No tool sets or clears the invoiced (exported) flag.

| Tool | Does | Needs |
| :--- | :--- | :--- |
| `log_time` | Creates one entry, for yourself or someone else, through Kimai's own timesheet service (locked weeks, overlaps, required custom fields and rates all apply) | `create_own_timesheet`, or `create_other_timesheet` for others |
| `log_absence` | Logs absence for a day or a range, one entry per working day at the hours the person was expected to work, on their own absence project; the type is given by name. Days already holding absence are skipped, so a repeat logs nothing twice | as above |
| `archive_customer` | Hides a customer and its visible projects. Refuses while billable time is not yet invoiced, unless told to go ahead | `edit_customer`, `edit_project` |
| `archive_project` | The same for one project | `edit_project` |

### Weekly Planner tools

Registered only when [PlannerBundle](https://github.com/happiness/PlannerBundle) is installed. They see and change exactly what the planner's own rules allow: its user list (including plugins that leave people out) and its voter, so reserved time still needs `plan_reservations_planner`.

| Tool | Does | Needs |
| :--- | :--- | :--- |
| `planned_capacity` | Per person and day: contract hours, time off, capacity, confirmed and tentative planned hours and what is free, sorted by most free hours, as on the planner screen | `view_planner` |
| `planned_work` | The plannings in a period, by person, customer or project, with the IDs the write tools take | `view_planner` |
| `set_planned_hours` | Creates a planning (project or customer work, reserved time by name, or time off), or changes one's period, hours, tentative flag, comment or person | `create_planner` or `edit_planner`, plus `mcp_write` |
| `remove_planned_work` | Clears a person's planning for a period: removes plannings inside it, shortens or splits those reaching past it. Leaves time off alone unless asked | `delete_planner`, plus `mcp_write` |

### Time categories and absence

Kimai has no built-in notion of absence. The tools ask a `TimeCategoryProviderInterface`: by default, the projects picked under *System › Settings › MCP server › Absence projects* count as absence, and their activities are the absence types. Another plugin can replace the default by implementing the interface (for example to add internal and maintenance categories).

### Extending from another plugin

Implement one of these in any plugin's service; autoconfiguration picks it up, and for the providers the first one registered is used.

| Interface | What it adds |
| :--- | :--- |
| `Tool\ToolInterface` | A tool, offered to clients subject to the permissions it declares |
| `Category\TimeCategoryProviderInterface` | Time categories, replacing the absence/other default (see above) |
| `Capacity\WorkingCapacityProviderInterface` | What someone without a work contract is expected to work. Kimai expects nothing of them, so without a provider `utilisation` shows zero contract hours and flags them `no_work_contract`; with one they are counted on its answer and flagged `default_week` |
| `Budget\RecurringBudgetProviderInterface` | Recurring budgets (credited again every interval, as RecurringBudgetBundle does). Kimai keeps those as one running total set against all time ever logged, so the budget tools add the current interval: credit, used, remaining and whether unused credit expires |

## Call log and settings

Every tool call is recorded in `kimai2_mcp_call_log`: who, which client, which tool, with which arguments, whether it worked and how long it took. Tools that change data are always recorded; read tools unless switched off. *System › Settings › MCP server* holds the read logging switch and how many days to keep (default 90).

```sh
bin/console kimai:mcp:log --limit=50      # the latest calls, newest first
bin/console kimai:mcp:prune               # remove rows past retention and expired sessions; run nightly
```

## Development

```sh
composer install -d tools/phpunit
KIMAI_DIR=/path/to/kimai tools/phpunit/vendor/bin/phpunit
```

## Building the bundled libraries

`vendor-prefixed/` is committed, so the plugin works straight from a git tag or a download, like any other Kimai plugin. Rebuild it only when the libraries change (edit and update `tools/deps/composer.json`, whose lock file pins exactly what goes in), and commit the result:

```sh
cd tools/deps
composer install
composer prefix      # runs Strauss: writes ../../vendor-prefixed/
```

## Installing (development)

Symlink the repository into Kimai's `var/plugins/McpServerBundle`, then:

```sh
bin/console kimai:reload
bin/console kimai:plugins --install
```

## Licence

AGPL-3.0-or-later. The bundled libraries keep their own licences (Apache-2.0 and MIT), included in `vendor-prefixed/`.

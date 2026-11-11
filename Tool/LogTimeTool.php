<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;

final class LogTimeTool extends AbstractWriteTool
{
    public function __construct(private readonly TimesheetWriter $writer)
    {
    }

    public function getName(): string
    {
        return 'log_time';
    }

    public function getDescription(): string
    {
        return 'Creates one timesheet entry: a project, an activity, a day, a start time and either hours or an end '
            . 'time. For yourself, or for someone else (user_id) if your Kimai role may log time for others. Kimai\'s '
            . 'own rules apply (locked weeks, overlaps, required custom fields, rates). For absence such as sick days '
            . 'or leave, use log_absence instead.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'user_id' => Schema::id('Whose time it is (ID from find_users). Default: yours'),
            'project_id' => Schema::id('The project (ID from find_projects)'),
            'activity_id' => Schema::id('The activity (ID from find_activities for that project)'),
            'date' => Schema::date('The day, in the person\'s own timezone'),
            'start' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$', 'description' => 'Start time, HH:MM. Default: Kimai\'s configured start of day'],
            'hours' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 24, 'description' => 'Duration in hours, e.g. 1.5. Give this or "end"'],
            'end' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$', 'description' => 'End time, HH:MM, on the same day. Give this or "hours"'],
            'description' => ['type' => 'string', 'description' => 'What was done'],
            'billable' => ['type' => 'boolean', 'description' => 'Only if the person said so; otherwise leave it out and Kimai decides from the customer, project and activity'],
            'custom_fields' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean']], 'description' => 'Custom field values by field name, e.g. {"youtrack_issue_key": "AN-176"}'],
        ], ['project_id', 'activity_id', 'date']);
    }

    public function getRequiredPermissions(): array
    {
        return ['create_own_timesheet|create_other_timesheet'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $owner = $this->writer->owner($arguments, $user);
        $project = $this->writer->project((int) Args::int($arguments, 'project_id'));
        $activity = $this->writer->activity((int) Args::int($arguments, 'activity_id'), $project);

        $date = (string) Args::string($arguments, 'date');
        $begin = $this->writer->at($owner, $date, Args::string($arguments, 'start') ?? $this->writer->defaultStart());

        $hours = $arguments['hours'] ?? null;
        $end = Args::string($arguments, 'end');
        if (is_numeric($hours) === ($end !== null)) {
            throw new ToolException('Give either "hours" or "end", not both and not neither.');
        }
        $seconds = is_numeric($hours)
            ? (int) round((float) $hours * 3600)
            : $this->writer->at($owner, $date, (string) $end)->getTimestamp() - $begin->getTimestamp();
        if ($seconds <= 0) {
            throw new ToolException('The entry has to end after it starts, on the same day.');
        }

        /** @var array<string, mixed> $fields */
        $fields = \is_array($arguments['custom_fields'] ?? null) ? $arguments['custom_fields'] : [];
        $timesheet = $this->writer->build(
            $owner,
            $project,
            $activity,
            $begin,
            $seconds,
            Args::string($arguments, 'description'),
            Args::bool($arguments, 'billable'),
            $fields,
        );

        return new WritePlan(
            ['create_entry' => $this->writer->describe($timesheet)],
            function () use ($timesheet): array {
                $this->writer->save($timesheet);

                return [
                    'entry' => $this->writer->describe($timesheet),
                    ToolHandlerAdapter::CHANGED => ['timesheet:' . $timesheet->getId()],
                ];
            }
        );
    }
}

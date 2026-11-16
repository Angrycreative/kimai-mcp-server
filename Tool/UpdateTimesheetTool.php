<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\Timesheet;
use App\Entity\User;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Corrects one of the caller's own entries: the project or activity, the day, the times, the description, billable
 * or custom fields. Only what is given changes. Invoiced entries and other people's are out of reach, whatever the
 * role allows (TimesheetWriter::ownEntry()).
 */
final class UpdateTimesheetTool extends AbstractWriteTool
{
    public function __construct(
        private readonly TimesheetWriter $writer,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'update_timesheet';
    }

    public function getDescription(): string
    {
        return 'Corrects one of your own timesheet entries that has not been invoiced: another project or activity, '
            . 'another day, other times or hours, the description, billable or custom fields. Only what you give '
            . 'changes: a new date keeps the start time and hours, a new start keeps the hours. Find the entry\'s ID '
            . 'with search_timesheets. Kimai\'s own rules apply (locked weeks, overlaps, required custom fields).';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'id' => Schema::id('The entry (ID from search_timesheets)'),
            'project_id' => Schema::id('Move it to this project (ID from find_projects). Give activity_id too unless its activity is a global one'),
            'activity_id' => Schema::id('Change the activity (ID from find_activities for the entry\'s project)'),
            'date' => Schema::date('Move it to this day, keeping its start time unless "start" is given'),
            'start' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$', 'description' => 'New start time, HH:MM. Keeps the hours unless "hours" or "end" is given'],
            'hours' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 24, 'description' => 'New duration in hours, e.g. 1.5, from the start. Give this or "end"'],
            'end' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$', 'description' => 'New end time, HH:MM, on the same day. Give this or "hours"'],
            'description' => ['type' => 'string', 'description' => 'The new description. An empty string clears it'],
            'billable' => ['type' => 'boolean', 'description' => 'Only if the person asked to change it'],
            'custom_fields' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean']], 'description' => 'Custom field values to set, by field name, e.g. {"youtrack_issue_key": "AN-176"}'],
        ], ['id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['edit_own_timesheet'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $entry = $this->writer->ownEntry((int) Args::int($arguments, 'id'), $user, 'edit');
        /** @var array<string, mixed> $fields */
        $fields = \is_array($arguments['custom_fields'] ?? null) ? $arguments['custom_fields'] : [];

        $before = $this->describe($entry, $arguments, $fields);
        $restore = $this->writer->snapshot($entry);
        try {
            $this->change($entry, $arguments, $user, $fields);
            $this->writer->validate($entry);
            $after = $this->describe($entry, $arguments, $fields);
        } finally {
            $restore();
        }

        $changed = array_keys(array_filter($after, static fn (mixed $value, string $key): bool => $value !== $before[$key], \ARRAY_FILTER_USE_BOTH));
        if ($changed === []) {
            throw new ToolException('That changes nothing: the entry already reads like this.');
        }

        return new WritePlan(
            ['change_entry' => ['before' => $before, 'after' => $after, 'changes' => $changed]],
            function () use ($entry, $arguments, $user, $fields): array {
                $this->change($entry, $arguments, $user, $fields);
                $this->writer->save($entry);

                return [
                    'entry' => $this->describe($entry, $arguments, $fields),
                    ToolHandlerAdapter::CHANGED => ['timesheet:' . $entry->getId()],
                ];
            }
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $fields
     * @throws ToolException
     */
    private function change(Timesheet $entry, array $arguments, User $owner, array $fields): void
    {
        $projectId = Args::int($arguments, 'project_id');
        $activityId = Args::int($arguments, 'activity_id');
        if ($projectId !== null && $projectId !== $entry->getProject()?->getId()) {
            $project = $this->writer->project($projectId);
            if ($activityId !== null) {
                $activity = $this->writer->activity($activityId, $project);
            } else {
                $activity = $entry->getActivity();
                if ($activity === null || $activity->getProject() !== null) {
                    throw new ToolException(\sprintf(
                        'Activity "%s" belongs to the entry\'s current project. Give activity_id for one of the new project\'s activities (find_activities).',
                        $activity?->getName()
                    ));
                }
            }
            $entry->setProject($project);
            $entry->setActivity($activity);
        } elseif ($activityId !== null && $entry->getProject() !== null) {
            $entry->setActivity($this->writer->activity($activityId, $entry->getProject()));
        }

        $this->moveTimes($entry, $arguments, $owner);

        if (\array_key_exists('description', $arguments)) {
            $description = Args::string($arguments, 'description');
            $entry->setDescription($description === '' ? null : $description);
        }

        $billable = Args::bool($arguments, 'billable');
        if ($billable !== null) {
            if (!$this->auth->isGranted('edit_billable', $entry)) {
                throw new ToolException('You may not choose whether an entry is billable; leave "billable" out.');
            }
            $entry->setBillableMode($billable ? Timesheet::BILLABLE_YES : Timesheet::BILLABLE_NO);
            $entry->setBillable($billable);
        }

        $this->writer->setCustomFields($entry, $fields);
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolException
     */
    private function moveTimes(Timesheet $entry, array $arguments, User $owner): void
    {
        $date = Args::string($arguments, 'date');
        $start = Args::string($arguments, 'start');
        $hours = $arguments['hours'] ?? null;
        $end = Args::string($arguments, 'end');
        if (is_numeric($hours) && $end !== null) {
            throw new ToolException('Give either "hours" or "end", not both.');
        }
        if ($date === null && $start === null && !is_numeric($hours) && $end === null) {
            return;
        }

        $old = $entry->getBegin();
        if ($old === null) {
            throw new ToolException('This entry has no start time to move from.');
        }
        // the person's own clock, which is how they see the entry in Kimai
        $old = (clone $old)->setTimezone(new \DateTimeZone($owner->getTimezone()));
        $date ??= $old->format('Y-m-d');

        if ($start !== null) {
            $begin = $this->writer->at($owner, $date, $start);
        } else {
            $begin = $this->writer->at($owner, $date, '00:00');
            $begin->setTime((int) $old->format('H'), (int) $old->format('i'), (int) $old->format('s'));
        }

        // Kimai counts the time worked as end - begin - break, and works it out again on save: the break stays, and
        // the hours are the time worked
        $break = $entry->getBreak();
        $seconds = match (true) {
            is_numeric($hours) => (int) round((float) $hours * 3600),
            $end !== null => $this->writer->at($owner, $date, $end)->getTimestamp() - $begin->getTimestamp() - $break,
            default => (int) $entry->getDuration(),
        };
        if ($seconds <= 0) {
            throw new ToolException($break > 0
                ? 'The entry has to end after it starts and its break, on the same day.'
                : 'The entry has to end after it starts, on the same day.');
        }

        $entry->setBegin($begin);
        $entry->setEnd((clone $begin)->modify('+' . ($seconds + $break) . ' seconds'));
        $entry->setDuration($seconds);
    }

    /**
     * The entry as the preview shows it, plus billable and the custom fields when they are being changed.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function describe(Timesheet $entry, array $arguments, array $fields): array
    {
        $out = $this->writer->describe($entry);
        if (Args::bool($arguments, 'billable') !== null) {
            $out['billable'] = $entry->isBillable();
        }
        if ($fields !== []) {
            $values = [];
            foreach (array_keys($fields) as $name) {
                $values[$name] = $entry->getMetaField($name)?->getValue();
            }
            $out['custom_fields'] = $values;
        }

        return $out;
    }
}

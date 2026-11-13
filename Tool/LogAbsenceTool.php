<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\TimesheetRepository;
use KimaiPlugin\McpServerBundle\Capacity\ExpectedHours;
use KimaiPlugin\McpServerBundle\Category\TimeCategories;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Format;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;

final class LogAbsenceTool extends AbstractWriteTool
{
    private const MAX_DAYS = 92;

    public function __construct(
        private readonly TimesheetWriter $writer,
        private readonly TimeCategories $categories,
        private readonly ExpectedHours $expected,
        private readonly TimesheetRepository $timesheets,
        private readonly ActivityRepository $activities,
    ) {
    }

    public function getName(): string
    {
        return 'log_absence';
    }

    public function getDescription(): string
    {
        return 'Logs absence (sick, leave and so on) for a day or a range: one entry per working day on the person\'s '
            . 'absence project and the absence type given, at the hours they were expected to work that day (their '
            . 'work contract, or the organisation\'s default week), unless "hours" says otherwise. Days they were not '
            . 'expected to work, and days that already have absence logged, are skipped, so repeating a call logs '
            . 'nothing twice. For yourself, or someone else (user_id) if your Kimai role may log time for others. '
            . 'The absence type is given by name and matched in the person\'s own absence project. Public holidays '
            . 'the person has off are skipped where the Weekly Planner is installed (their holiday calendar there); '
            . 'without it Kimai does not know public holidays, so leave them out of the range where the person does '
            . 'not work them. The preview lists every day skipped and why.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'user_id' => Schema::id('Whose absence it is (ID from find_users). Default: yours'),
            'type' => ['type' => 'string', 'description' => 'The absence type by name, e.g. "Sick leave" (the absence activities find_activities lists). It is matched in the person\'s own absence project'],
            'from' => Schema::date('First day'),
            'to' => Schema::date('Last day, inclusive. Default: the same as "from"'),
            'hours' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 24, 'description' => 'Hours per day, e.g. 4 for a half day. Default: what the person was expected to work that day'],
            'description' => ['type' => 'string', 'description' => 'An optional note on every entry'],
        ], ['type', 'from']);
    }

    public function getRequiredPermissions(): array
    {
        return ['create_own_timesheet|create_other_timesheet'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $owner = $this->writer->owner($arguments, $user);
        $provider = $this->categories->provider();
        $project = $provider->getAbsenceProjectFor($owner);
        if ($project === null) {
            throw new ToolException(\sprintf('Kimai cannot tell which absence project is %s\'s: either none is set up in Kimai\'s settings, or there are several and they have never logged absence on one. Log their first absence in Kimai itself.', $owner->getDisplayName()));
        }
        $project = $this->writer->project((int) $project->getId());
        $activity = $this->type($project, (string) Args::string($arguments, 'type'));

        $from = Args::date($arguments, 'from', $owner);
        $to = Args::date($arguments, 'to', $owner) ?? $from;
        if ($from === null || $to === null || $to < $from) {
            throw new ToolException('"to" cannot be before "from".');
        }
        if ($from->diff($to)->days >= self::MAX_DAYS) {
            throw new ToolException(\sprintf('At most %d days at a time.', self::MAX_DAYS));
        }

        $hours = $arguments['hours'] ?? null;
        $fixed = is_numeric($hours) ? (int) round((float) $hours * 3600) : null;
        $already = $this->daysWithAbsence($owner, $provider->getAbsenceProjectIds(), $from, $to);
        $start = $this->writer->defaultStart();
        $description = Args::string($arguments, 'description');

        /** @var list<Timesheet> $entries */
        $entries = [];
        $skipped = [];
        for ($day = $from->setTime(12, 0); $day <= $to->setTime(12, 0); $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            if (isset($already[$key])) {
                $skipped[] = ['date' => $key, 'reason' => 'absence already logged'];
                continue;
            }
            $holiday = $this->expected->holiday($owner, $day);
            if ($holiday !== null) {
                $skipped[] = ['date' => $key, 'reason' => 'public holiday: ' . $holiday];
                continue;
            }
            [$expected] = $this->expected->forDay($owner, $day);
            if ($expected === null && $fixed === null) {
                throw new ToolException(\sprintf('%s has no work contract, so give "hours" per day.', $owner->getDisplayName()));
            }
            // without a contract, "hours" applies to Monday to Friday
            $working = $expected === null ? (int) $day->format('N') <= 5 : $expected > 0;
            if (!$working) {
                $skipped[] = ['date' => $key, 'reason' => 'not a working day'];
                continue;
            }

            $entries[] = $this->writer->build(
                $owner,
                $project,
                $activity,
                $this->writer->at($owner, $key, $start),
                $fixed ?? (int) $expected,
                $description,
            );
        }

        $summary = [
            'user' => ['id' => $owner->getId(), 'name' => $owner->getDisplayName()],
            'absence' => ['customer' => $project->getCustomer()?->getName(), 'project' => $project->getName(), 'type' => $activity->getName()],
            'entries' => array_map(fn (Timesheet $entry): array => ['date' => $entry->getBegin()?->format('Y-m-d'), 'from' => $entry->getBegin()?->format('H:i'), 'hours' => Format::hours((int) $entry->getDuration())], $entries),
            'total_hours' => Format::hours(array_sum(array_map(static fn (Timesheet $entry): int => (int) $entry->getDuration(), $entries))),
        ];
        if ($skipped !== []) {
            $summary['skipped'] = $skipped;
        }
        if ($entries === []) {
            $summary['note'] = 'Nothing to log.';
        }

        return new WritePlan($summary, function () use ($entries, $summary): array {
            $changed = [];
            foreach ($entries as $entry) {
                $this->writer->save($entry);
                $changed[] = 'timesheet:' . $entry->getId();
            }

            return ['logged' => $summary, ToolHandlerAdapter::CHANGED => $changed];
        });
    }

    /**
     * The absence project's activity of that name. Every company's absence project uses the same names, so a type
     * picked from find_activities finds the right one for this person.
     *
     * @throws ToolException
     */
    private function type(Project $project, string $name): Activity
    {
        $available = [];
        foreach ($this->activities->findBy(['project' => $project, 'visible' => true]) as $activity) {
            if (mb_strtolower(trim((string) $activity->getName())) === mb_strtolower(trim($name))) {
                return $this->writer->activity((int) $activity->getId(), $project);
            }
            $available[] = $activity->getName();
        }

        throw new ToolException(\sprintf('No absence type "%s" in %s. Available: %s.', $name, $project->getName(), implode(', ', $available)));
    }

    /**
     * Days in the range that already hold an entry on any absence project.
     *
     * @param list<int> $projectIds
     * @return array<string, true>
     */
    private function daysWithAbsence(User $owner, array $projectIds, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($projectIds === []) {
            return [];
        }

        $rows = $this->timesheets->createQueryBuilder('t')
            ->select('DISTINCT t.date AS day')
            ->where('t.user = :user')
            ->andWhere('t.project IN (:projects)')
            ->andWhere('t.date BETWEEN :from AND :to')
            ->setParameter('user', $owner)
            ->setParameter('projects', $projectIds)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()
            ->getArrayResult();

        $days = [];
        foreach ($rows as $row) {
            $value = \is_array($row) ? ($row['day'] ?? null) : null;
            if ($value instanceof \DateTimeInterface) {
                $days[$value->format('Y-m-d')] = true;
            } elseif (\is_string($value)) {
                $days[substr($value, 0, 10)] = true;
            }
        }

        return $days;
    }
}

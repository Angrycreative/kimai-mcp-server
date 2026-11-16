<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Configuration\SystemConfiguration;
use App\Entity\Activity;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Event\TimesheetMetaDefinitionEvent;
use App\Event\TimesheetStopPostEvent;
use App\Event\TimesheetStopPreEvent;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\Timesheet\RoundingService;
use App\Timesheet\TimesheetService;
use App\Validator\ValidationFailedException;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Creates, changes and deletes timesheet entries the way Kimai's own API does: through TimesheetService, so its
 * voters, validation (lockdown, overlaps, required custom fields, project and activity rules), rates and events all
 * apply. Entries are built and checked first and saved only on confirmation, so a preview that passes is an entry
 * that will save.
 */
final class TimesheetWriter
{
    private const FALLBACK_START = '08:00';

    public function __construct(
        private readonly TimesheetService $service,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly ActivityRepository $activities,
        private readonly SystemConfiguration $configuration,
        private readonly TimesheetRepository $timesheets,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly RoundingService $roundings,
    ) {
    }

    /**
     * One of the caller's own entries that they may still change ("edit") or delete ("delete"). Other people's
     * entries are out of reach whatever the role allows, and so is invoiced (exported) time: correcting it would
     * change an invoice that has gone out.
     *
     * @param 'edit'|'delete' $attribute
     * @throws ToolException
     */
    public function ownEntry(int $id, User $caller, string $attribute): Timesheet
    {
        $entry = $this->timesheets->find($id);
        // someone else's entry reads as missing, so its existence is not given away
        if (!$entry instanceof Timesheet || $entry->getUser()?->getId() !== $caller->getId()) {
            throw new ToolException('You have no entry with that ID. Use search_timesheets to find the ID of the entry.');
        }
        if ($entry->isExported()) {
            throw new ToolException('This entry has been invoiced (exported), so it cannot be changed here: that would change an invoice. Ask whoever handles invoicing.');
        }
        if ($attribute === 'edit' && $entry->isRunning()) {
            throw new ToolException('This entry is still running. Stop it with stop_timer, which can also set when it ended and its description, then correct it.');
        }
        if (!$this->auth->isGranted($attribute, $entry)) {
            throw new ToolException(\sprintf(
                'Kimai does not allow %s this entry: the period of %s may be locked, or your role may not %s its own time.',
                $attribute === 'edit' ? 'changing' : 'deleting',
                $entry->getBegin()?->format('Y-m-d'),
                $attribute === 'edit' ? 'edit' : 'delete'
            ));
        }

        return $entry;
    }

    /**
     * Remembers what an entry holds now, and returns what puts it back: a preview changes the entry itself, to have
     * Kimai validate exactly the result, and must leave it as it was. Kimai's timesheets are tracked explicitly, so
     * nothing reaches the database unless saved, but the entry must not look changed to the rest of the request.
     *
     * @return \Closure(): void
     */
    public function snapshot(Timesheet $entry): \Closure
    {
        $project = $entry->getProject();
        $activity = $entry->getActivity();
        $begin = $entry->getBegin() !== null ? clone $entry->getBegin() : null;
        $end = $entry->getEnd() !== null ? clone $entry->getEnd() : null;
        $duration = $entry->getDuration(false);
        $timezone = $entry->getTimezone();
        $description = $entry->getDescription();
        $billable = $entry->isBillable();
        $billableMode = $entry->getBillableMode();
        $meta = [];
        foreach ($entry->getMetaFields() as $field) {
            $meta[spl_object_id($field)] = $field->getValue();
        }

        return static function () use ($entry, $project, $activity, $begin, $end, $duration, $timezone, $description, $billable, $billableMode, $meta): void {
            if ($project !== null) {
                $entry->setProject($project);
            }
            if ($activity !== null) {
                $entry->setActivity($activity);
            }
            if ($begin !== null) {
                $entry->setBegin(clone $begin);
            }
            $entry->setEnd($end !== null ? clone $end : null);
            $entry->setDuration($duration);
            if ($timezone !== null) {
                $entry->setTimezone($timezone);
            }
            $entry->setDescription($description);
            $entry->setBillable($billable);
            $entry->setBillableMode($billableMode);
            $added = [];
            foreach ($entry->getMetaFields() as $field) {
                if (\array_key_exists(spl_object_id($field), $meta)) {
                    $field->setValue($meta[spl_object_id($field)]);
                } else {
                    $added[] = $field;
                }
            }
            // added for this change only (with its default, if it has one): gone again, so the confirmed change adds
            // it afresh with the same default the preview was validated with
            foreach ($added as $field) {
                $entry->getMetaFields()->removeElement($field);
            }
        };
    }

    /**
     * Sets custom field values by name, refusing names the entry does not have.
     *
     * @param array<string, mixed> $values custom field name => value
     * @throws ToolException
     */
    public function setCustomFields(Timesheet $entry, array $values): void
    {
        if ($values === []) {
            return;
        }
        if ($entry->getId() !== null) {
            // a saved entry only carries the fields it has values for; this adds the others, as the API does
            $this->dispatcher->dispatch(new TimesheetMetaDefinitionEvent($entry));
        }
        foreach ($values as $name => $value) {
            $field = $entry->getMetaField($name);
            if ($field === null || !$field->isVisible()) {
                throw new ToolException(\sprintf('Entries have no custom field "%s".', $name));
            }
            $field->setValue(\is_scalar($value) ? (string) $value : null);
        }
    }

    /**
     * Checks a changed entry as Kimai would on save. Whether the caller may change it at all was decided by
     * ownEntry(), on the entry as it was; the validation covers where it is going, a locked week included.
     *
     * @throws ToolException
     */
    public function validate(Timesheet $entry): void
    {
        try {
            $this->service->validateTimesheet($entry);
        } catch (ValidationFailedException $e) {
            throw new ToolException(\sprintf('Kimai would refuse the change on %s: %s', $entry->getBegin()?->format('Y-m-d'), $this->violations($e)));
        }
    }

    public function delete(Timesheet $entry): void
    {
        $this->service->deleteTimesheet($entry);
    }

    /**
     * Whose entry it is: the caller, or someone else when "user_id" names them and the caller may log time for others.
     *
     * @param array<string, mixed> $args
     * @throws ToolException
     */
    public function owner(array $args, User $caller): User
    {
        $id = Args::int($args, 'user_id');
        if ($id === null || $id === $caller->getId()) {
            return $caller;
        }
        if (!$this->auth->isGranted('create_other_timesheet')) {
            throw new ToolException('You can only log your own time: your Kimai role lacks the permission to log time for other people.');
        }
        $owner = $this->users->find($id);
        if (!$owner instanceof User || !$owner->isEnabled()) {
            throw new ToolException('No active user with that ID.');
        }

        return $owner;
    }

    /**
     * A project the caller may log time to. "access" is Kimai's team check, the one its timesheet form applies;
     * "view" would be the project administration permission, which most people do not hold. Everything else
     * (visible, not ended, the activity, lockdown) is the "create" voter's and the validator's job in build().
     *
     * @throws ToolException
     */
    public function project(int $id): Project
    {
        $project = $this->projects->find($id);
        if (!$project instanceof Project || !$this->auth->isGranted('access', $project)) {
            throw new ToolException('No project with that ID that you may see.');
        }

        return $project;
    }

    /**
     * @throws ToolException
     */
    public function activity(int $id, Project $project): Activity
    {
        $activity = $this->activities->find($id);
        if (!$activity instanceof Activity) {
            throw new ToolException('No activity with that ID.');
        }
        $own = $activity->getProject();
        if ($own !== null && $own->getId() !== $project->getId()) {
            throw new ToolException(\sprintf('Activity "%s" belongs to another project. Use find_activities with this project to see its activities.', $activity->getName()));
        }

        return $activity;
    }

    /**
     * The start time to use when none was given: Kimai's configured default, or 08:00 when that is "now" or unset.
     */
    public function defaultStart(): string
    {
        $configured = $this->configuration->getTimesheetDefaultBeginTime();

        return preg_match('/^\d{1,2}:\d{2}$/', $configured) === 1 ? $configured : self::FALLBACK_START;
    }

    /**
     * A day and time in the owner's own timezone, which is how Kimai stores and shows their entries.
     *
     * @throws ToolException
     */
    public function at(User $owner, string $date, string $time): \DateTime
    {
        $value = \DateTime::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, new \DateTimeZone($owner->getTimezone()));
        if ($value === false || $value->format('Y-m-d') !== $date) {
            throw new ToolException(\sprintf('"%s %s" is not a valid date and time: use YYYY-MM-DD and HH:MM.', $date, $time));
        }

        return $value;
    }

    /**
     * Builds an entry and checks it as Kimai would on save, without saving it.
     *
     * @param array<string, mixed> $customFields custom field name => value
     * @throws ToolException
     */
    public function build(
        User $owner,
        Project $project,
        Activity $activity,
        \DateTime $begin,
        int $seconds,
        ?string $description = null,
        ?bool $billable = null,
        array $customFields = [],
    ): Timesheet {
        if ($seconds <= 0) {
            throw new ToolException('The duration must be more than zero.');
        }

        $timesheet = $this->compose($owner, $project, $activity, $begin, $description, $customFields);
        $timesheet->setEnd((clone $begin)->modify('+' . $seconds . ' seconds'));
        $timesheet->setDuration($seconds);

        if ($billable !== null) {
            if (!$this->auth->isGranted('edit_billable', $timesheet)) {
                throw new ToolException('You may not choose whether an entry is billable; leave "billable" out and Kimai decides.');
            }
            $timesheet->setBillableMode($billable ? Timesheet::BILLABLE_YES : Timesheet::BILLABLE_NO);
            $timesheet->setBillable($billable);
        }

        $this->check($timesheet);

        return $timesheet;
    }

    /**
     * The person's running entries (timers), newest first.
     *
     * @return list<Timesheet>
     */
    public function running(User $user): array
    {
        return array_values($this->timesheets->getActiveEntries($user));
    }

    /**
     * The person's finished entries starting from $from up to $until, when Kimai refuses overlapping entries. A timer
     * running from $from overlaps each of them once it is stopped after its start, and Kimai's own overlap check does
     * not see that coming, as it passes a running entry.
     *
     * @return list<Timesheet> by start
     */
    public function entriesBetween(User $user, \DateTime $from, \DateTime $until): array
    {
        if ($this->configuration->isTimesheetAllowOverlappingRecords()) {
            return [];
        }

        /** @var list<Timesheet> $entries */
        $entries = $this->timesheets->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.end IS NOT NULL')
            ->andWhere('t.begin >= :from')
            ->andWhere('t.begin < :until')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->orderBy('t.begin', 'ASC')
            ->getQuery()
            ->getResult();

        return $entries;
    }

    /**
     * How many running entries Kimai lets a person have. Starting one more stops the oldest, after the new one is
     * saved (TimesheetService::stopActiveEntries()).
     */
    public function runningLimit(): int
    {
        return max(1, $this->configuration->getTimesheetActiveEntriesHardLimit());
    }

    /**
     * Builds a running entry (a timer) and checks it as Kimai would on start, without saving it.
     *
     * @param array<string, mixed> $customFields custom field name => value
     * @throws ToolException
     */
    public function timer(User $owner, Project $project, Activity $activity, \DateTime $begin, ?string $description = null, array $customFields = []): Timesheet
    {
        $timesheet = $this->compose($owner, $project, $activity, $begin, $description, $customFields);

        if (!$this->auth->isGranted('start', $timesheet)) {
            throw new ToolException(\sprintf(
                'Kimai does not let you start a timer on %s: the period may be locked, or your role may not start timers there.',
                $project->getName()
            ));
        }
        try {
            $this->service->validateTimesheet($timesheet);
        } catch (ValidationFailedException $e) {
            throw new ToolException('Kimai would refuse the timer: ' . $this->violations($e));
        }

        return $timesheet;
    }

    /** Whether Kimai's voter lets the caller stop this running entry. */
    public function mayStop(Timesheet $timesheet): bool
    {
        return $this->auth->isGranted('stop', $timesheet);
    }

    /** Whether Kimai's voter lets the caller edit this entry (not in a locked period, and their role may). */
    public function mayEdit(Timesheet $timesheet): bool
    {
        return $this->auth->isGranted('edit', $timesheet);
    }

    /**
     * The minute that has begun, in the person's timezone: when a timer started or stopped "now" starts or stops.
     *
     * Kimai's own stop takes the second and rounds an end up to the minute, while a start is rounded down. A timer
     * started in the same minute another is stopped then overlaps it, and can never be stopped itself ("You already
     * have an entry for this time"). On the minute that has begun, an end and the next start are the same moment,
     * and nothing is in the future.
     */
    public function minute(User $user): \DateTime
    {
        $now = new \DateTime('now', new \DateTimeZone($user->getTimezone()));

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'));
    }

    /**
     * Checks stopping a running entry at $end as Kimai would, without stopping it, and returns the end Kimai would
     * store: its rounding rules can move an end later than the moment it was stopped.
     *
     * @throws ToolException
     */
    public function trialStop(Timesheet $timesheet, \DateTime $end): \DateTime
    {
        $begin = $timesheet->getBegin();
        // stopped in the minute it started: no time, or less than none when it started on a second Kimai kept
        $sameMinute = $begin !== null && intdiv($end->getTimestamp(), 60) === intdiv($begin->getTimestamp(), 60);
        if ($sameMinute && ($end < $begin || !$this->configuration->isTimesheetAllowZeroDuration())) {
            throw new ToolException(\sprintf(
                'The timer on %s started at %s, so stopping it at %s would leave an entry of no time, which Kimai does not allow here. Stop it a minute later, or delete it with delete_timesheet once stopped.',
                $timesheet->getProject()?->getName(),
                $begin->format('H:i'),
                $end->format('H:i')
            ));
        }

        $restore = $this->snapshot($timesheet);
        try {
            $timesheet->setEnd(clone $end);
            try {
                $this->service->validateTimesheet($timesheet);
            } catch (ValidationFailedException $e) {
                throw new ToolException(\sprintf('Kimai would refuse to stop the timer on %s at %s: %s', $timesheet->getProject()?->getName(), $end->format('H:i'), $this->violations($e)));
            }
            $this->roundings->applyRoundings($timesheet);

            return clone ($timesheet->getEnd() ?? $end);
        } finally {
            $restore();
        }
    }

    /**
     * Stops a running entry at $end, as Kimai's stop does (validated, its stop events dispatched, so plugins that
     * act on a stop see one), but at the given moment rather than the current second.
     *
     * @throws ToolException
     */
    public function stopAt(Timesheet $timesheet, \DateTime $end): void
    {
        $timesheet->setEnd(clone $end);
        try {
            $this->service->validateTimesheet($timesheet);
        } catch (ValidationFailedException $e) {
            throw new ToolException('Kimai refused to stop the timer: ' . $this->violations($e));
        }

        $this->dispatcher->dispatch(new TimesheetStopPreEvent($timesheet));
        $this->timesheets->save($timesheet);
        $this->dispatcher->dispatch(new TimesheetStopPostEvent($timesheet));
    }

    /**
     * @throws ToolException
     */
    public function save(Timesheet $timesheet): void
    {
        try {
            $this->service->saveTimesheet($timesheet);
        } catch (ValidationFailedException $e) {
            throw new ToolException('Kimai refused the entry: ' . $this->violations($e));
        }
    }

    /**
     * The entry as the preview and the result show it.
     *
     * @return array<string, mixed>
     */
    public function describe(Timesheet $timesheet): array
    {
        $begin = $timesheet->getBegin();
        $end = $timesheet->getEnd();
        $project = $timesheet->getProject();
        $user = $timesheet->getUser();

        $out = [];
        if ($timesheet->getId() !== null) {
            $out['id'] = $timesheet->getId();
        }

        $out += [
            'user' => ['id' => $user?->getId(), 'name' => $user?->getDisplayName()],
            'date' => $begin?->format('Y-m-d'),
            'from' => $begin?->format('H:i'),
        ];
        // a running entry has no end and no duration yet; its hours so far are left out, so a preview stays the same
        // from one minute to the next
        $out += $end === null ? ['running' => true] : ['to' => $end->format('H:i'), 'hours' => Format::hours((int) $timesheet->getDuration())];

        return $out + [
            'customer' => $project?->getCustomer()?->getName(),
            'project' => ['id' => $project?->getId(), 'name' => $project?->getName()],
            'activity' => ['id' => $timesheet->getActivity()?->getId(), 'name' => $timesheet->getActivity()?->getName()],
            'description' => $timesheet->getDescription(),
        ];
    }

    /**
     * A new entry for $owner, as Kimai prepares one, with what log_time and start_timer both set.
     *
     * @param array<string, mixed> $customFields custom field name => value
     * @throws ToolException
     */
    private function compose(User $owner, Project $project, Activity $activity, \DateTime $begin, ?string $description, array $customFields): Timesheet
    {
        $timesheet = $this->service->createNewTimesheet($owner);
        $this->service->prepareNewTimesheet($timesheet);
        $timesheet->setProject($project);
        $timesheet->setActivity($activity);
        $timesheet->setBegin(clone $begin);
        if ($description !== null && $description !== '') {
            $timesheet->setDescription($description);
        }
        $this->setCustomFields($timesheet, $customFields);

        return $timesheet;
    }

    /**
     * @throws ToolException
     */
    private function check(Timesheet $timesheet): void
    {
        if (!$this->auth->isGranted('create', $timesheet)) {
            throw new ToolException(\sprintf(
                'Kimai does not allow this entry for %s on %s: the period may be locked, or your role may not log time there.',
                $timesheet->getUser()?->getDisplayName(),
                $timesheet->getBegin()?->format('Y-m-d')
            ));
        }

        try {
            $this->service->validateTimesheet($timesheet);
        } catch (ValidationFailedException $e) {
            throw new ToolException(\sprintf('Kimai would refuse the entry on %s: %s', $timesheet->getBegin()?->format('Y-m-d'), $this->violations($e)));
        }
    }

    private function violations(ValidationFailedException $e): string
    {
        $messages = [];
        foreach ($e->getViolations() as $violation) {
            $path = $violation->getPropertyPath();
            $messages[] = ($path !== '' ? $path . ': ' : '') . $violation->getMessage();
        }

        return $messages === [] ? $e->getMessage() : implode('; ', array_unique($messages));
    }
}

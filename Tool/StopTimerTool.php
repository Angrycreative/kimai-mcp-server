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

/**
 * Stops one of the caller's running timers. Without "end" it stops now, as Kimai's stop button does, at the moment
 * the person confirms. With "end" (the timer was forgotten) or a new description, it is an edit of the entry, as in
 * Kimai's edit form, so it needs the edit permission and is refused in a locked period.
 */
final class StopTimerTool extends AbstractWriteTool
{
    public function __construct(private readonly TimesheetWriter $writer)
    {
    }

    public function getName(): string
    {
        return 'stop_timer';
    }

    public function getDescription(): string
    {
        return 'Stops your running timer, now or at an earlier time ("I forgot, it ended at 17:00"), optionally with '
            . 'a new description. With more than one running, give its id (running_timers).';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'id' => Schema::id('The running entry, from running_timers. Default: your only running timer'),
            'end' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$', 'description' => 'When it ended, HH:MM in your timezone: the first such time after it started, so 01:00 for a timer started at 22:00 is the next day. Default: now'],
            'description' => ['type' => 'string', 'description' => 'A new description for the entry'],
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['stop_own_timesheet'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $entry = $this->target($arguments, $user);
        $end = Args::string($arguments, 'end');
        $description = Args::string($arguments, 'description');
        $edits = $end !== null || ($description !== null && $description !== '');

        if (!$this->writer->mayStop($entry)) {
            throw new ToolException('Kimai does not let you stop this timer: your role may not stop its own timers.');
        }
        if ($edits && !$this->writer->mayEdit($entry)) {
            throw new ToolException('Kimai does not let you change this entry, so it can only be stopped now, as it is: the period may be locked, or your role may not edit its own time.');
        }

        $endAt = null;
        if ($end !== null) {
            $begin = $entry->getBegin() ?? throw new ToolException('This entry has no start.');
            $timezone = new \DateTimeZone($user->getTimezone());
            // the first $end after it started: a timer started at 22:00 and stopped at 01:00 ended the next day
            $endAt = $this->writer->at($user, (clone $begin)->setTimezone($timezone)->format('Y-m-d'), $end);
            while ($endAt <= $begin) {
                $endAt->modify('+1 day');
            }
            if ($endAt > new \DateTime('now', $timezone)) {
                throw new ToolException(\sprintf('The timer started at %s on %s, and %s after that has not come yet. Leave "end" out to stop it now.', $begin->format('H:i'), $begin->format('Y-m-d'), $end));
            }
        }

        $summary = ['stop_timer' => $this->writer->describe($entry)];
        unset($summary['stop_timer']['running']);
        $summary['stop_timer']['to'] = 'now, when you confirm';
        if ($endAt !== null) {
            $summary['stop_timer']['to'] = $endAt->format($endAt->format('Y-m-d') === ($summary['stop_timer']['date'] ?? null) ? 'H:i' : 'Y-m-d H:i');
        }
        if ($description !== null && $description !== '') {
            $summary['stop_timer']['description'] = $description;
        }

        // Kimai's rules are run on the result now; stopping now is checked by the clock of the preview, so a timer
        // running too long, or one with no time yet, is refused here rather than on confirm
        $restore = $this->writer->snapshot($entry);
        try {
            if ($description !== null && $description !== '') {
                $entry->setDescription($description);
            }
            $this->writer->trialStop($entry, $endAt ?? $this->writer->minute($user));
        } finally {
            $restore();
        }

        return new WritePlan($summary, function () use ($entry, $endAt, $description, $user): array {
            if ($description !== null && $description !== '') {
                $entry->setDescription($description);
            }
            // "now" is the minute of the confirmation (see TimesheetWriter::minute())
            $this->writer->stopAt($entry, $endAt ?? $this->writer->minute($user));

            return ['stopped' => $this->writer->describe($entry), ToolHandlerAdapter::CHANGED => ['timesheet:' . $entry->getId()]];
        });
    }

    /**
     * @param array<string, mixed> $arguments
     * @throws ToolException
     */
    private function target(array $arguments, User $user): Timesheet
    {
        $running = $this->writer->running($user);
        $id = Args::int($arguments, 'id');
        if ($id !== null) {
            foreach ($running as $entry) {
                if ($entry->getId() === $id) {
                    return $entry;
                }
            }
            throw new ToolException('You have no running timer with that ID. running_timers lists them.');
        }
        if ($running === []) {
            throw new ToolException('No timer is running.');
        }
        if (\count($running) > 1) {
            throw new ToolException(\sprintf('%d timers are running. Give the id of the one to stop (running_timers lists them).', \count($running)));
        }

        return $running[0];
    }
}

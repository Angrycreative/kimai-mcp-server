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
 * Starts a timer for the caller, as Kimai's start button does: a running entry, now or from a time earlier today.
 * Kimai stops the oldest running timers beyond its limit (usually one at a time), and the preview says which.
 *
 * The start time of "now" is taken when the person confirms, not when the preview was made, so the preview does not
 * name a time it would not keep.
 */
final class StartTimerTool extends AbstractWriteTool
{
    public function __construct(private readonly TimesheetWriter $writer)
    {
    }

    public function getName(): string
    {
        return 'start_timer';
    }

    public function getDescription(): string
    {
        return 'Starts a timer for you on a project and activity, now or from a time earlier today ("I started at '
            . '9"). Kimai allows a limited number of running timers, usually one, so starting a new one stops the '
            . 'oldest: the preview says which. Stop it with stop_timer, see it with running_timers.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'project_id' => Schema::id('The project (ID from find_projects)'),
            'activity_id' => Schema::id('The activity (ID from find_activities for that project)'),
            'start' => ['type' => 'string', 'pattern' => '^\d{1,2}:\d{2}$', 'description' => 'When it started, HH:MM today, in your timezone. Default: now'],
            'description' => ['type' => 'string', 'description' => 'What you are working on'],
            'custom_fields' => Schema::customFields(),
        ], ['project_id', 'activity_id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['start_own_timesheet'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $project = $this->writer->project((int) Args::int($arguments, 'project_id'));
        $activity = $this->writer->activity((int) Args::int($arguments, 'activity_id'), $project);
        $description = Args::string($arguments, 'description');
        $fields = Args::customFields($arguments);

        $timezone = new \DateTimeZone($user->getTimezone());
        $now = new \DateTime('now', $timezone);
        $start = Args::string($arguments, 'start');
        $begin = null;
        if ($start !== null) {
            $begin = $this->writer->at($user, $now->format('Y-m-d'), $start);
            if ($begin > $now) {
                throw new ToolException(\sprintf('%s is later than now. A timer starts now or earlier today; to plan ahead, use log_time.', $start));
            }
        }

        $running = $this->writer->running($user);
        $stops = $this->stops($running);
        if ($begin !== null) {
            // Kimai's overlap check passes a running entry and only bites when it is stopped, which would leave a
            // timer nobody can stop. So whatever it would overlap by then is refused now: the timers it stops, and
            // those it leaves running when Kimai allows more than one.
            foreach ($running as $entry) {
                $started = $entry->getBegin();
                if ($started !== null && $started > $begin) {
                    throw new ToolException(\sprintf('Your running timer on %s started at %s, after %s, so the two would overlap. Stop it at an earlier time with stop_timer first, or start from %s or later.', $entry->getProject()?->getName(), $started->format('H:i'), $start, $started->format('H:i')));
                }
            }
            $earlier = $this->writer->entriesBetween($user, $begin, $now);
            if ($earlier !== []) {
                throw new ToolException(\sprintf('You have an entry from %s to %s today (%s), so a timer from %s would overlap it. Start it after %s, or log the time with log_time.', $earlier[0]->getBegin()?->format('H:i'), $earlier[0]->getEnd()?->format('H:i'), $earlier[0]->getProject()?->getName(), $start, $earlier[\count($earlier) - 1]->getEnd()?->format('H:i')));
            }
        }

        // stopping each is checked as Kimai would, "now" by the clock of the preview: a timer in a locked period, one
        // running too long, or one Kimai's rounding would end after the new one starts, is refused here, not on confirm
        $at = $begin ?? $this->writer->minute($user);
        foreach ($stops as $entry) {
            $this->checkStop($entry, $at);
        }

        // checked as Kimai would on start, from the time it would really start
        $timer = $this->writer->timer($user, $project, $activity, $at, $description, $fields);

        $summary = ['start_timer' => $this->writer->describe($timer)];
        if ($start === null) {
            $summary['start_timer']['from'] = 'now, when you confirm';
            unset($summary['start_timer']['date']);
        }
        $later = $this->writer->entriesBetween($user, $now, (clone $now)->modify('tomorrow'));
        if ($later !== []) {
            $summary['warning'] = \sprintf(
                'You have an entry from %s to %s today (%s). Stop this timer by %s: Kimai refuses to stop a timer once it overlaps another entry.',
                $later[0]->getBegin()?->format('H:i'),
                $later[0]->getEnd()?->format('H:i'),
                $later[0]->getProject()?->getName(),
                $later[0]->getBegin()?->format('H:i')
            );
        }
        if ($stops !== []) {
            $summary['stops'] = array_map(function (Timesheet $entry): array {
                $row = $this->writer->describe($entry);
                unset($row['user'], $row['running']);

                return $row;
            }, $stops);
            $summary['note'] = \sprintf(
                'Kimai allows %d running timer(s), so starting this one stops the oldest %s.',
                $this->writer->runningLimit(),
                $start !== null ? 'at ' . $start : 'at the moment it starts'
            );
        }

        return new WritePlan($summary, function () use ($user, $project, $activity, $begin, $description, $fields): array {
            // "now" is the minute of the confirmation. The timers beyond Kimai's limit end exactly where this one
            // starts, stopped first: left to Kimai, they would be stopped after it is saved, at the current second
            // rounded up, overlapping it (see TimesheetWriter::minute()).
            $at = $begin ?? $this->writer->minute($user);
            $stopped = [];
            foreach ($this->stops($this->writer->running($user)) as $entry) {
                $this->checkStop($entry, $at);
                $this->writer->stopAt($entry, $at);
                // a plugin's rounding, applied as it saves, is only seen afterwards; refusing rolls the stop back
                $end = $entry->getEnd();
                if ($end !== null && $end > $at) {
                    throw $this->roundedPast($entry, $end, $at);
                }
                $stopped[] = (int) $entry->getId();
            }
            $timer = $this->writer->timer($user, $project, $activity, $at, $description, $fields);
            $this->writer->save($timer);

            $out = ['timer' => $this->writer->describe($timer)];
            if ($stopped !== []) {
                $out['stopped'] = $stopped;
            }

            return $out + [ToolHandlerAdapter::CHANGED => array_map(static fn (int $id): string => 'timesheet:' . $id, [(int) $timer->getId(), ...$stopped])];
        });
    }

    /**
     * The running timers starting one more would take past Kimai's limit, oldest first.
     *
     * @param list<Timesheet> $running newest first
     * @return list<Timesheet>
     */
    private function stops(array $running): array
    {
        return \array_slice(array_reverse($running), 0, max(0, \count($running) + 1 - $this->writer->runningLimit()));
    }

    /**
     * Refuses stopping $entry at $at where Kimai would: its own rules, and an end its rounding moves past $at, which
     * the new timer would overlap and so never be stopped.
     *
     * @throws ToolException
     */
    private function checkStop(Timesheet $entry, \DateTime $at): void
    {
        $end = $this->writer->trialStop($entry, $at);
        if ($end > $at) {
            throw $this->roundedPast($entry, $end, $at);
        }
    }

    private function roundedPast(Timesheet $entry, \DateTimeInterface $end, \DateTime $at): ToolException
    {
        return new ToolException(\sprintf(
            'Kimai rounds the end of your running timer on %s up to %s, so it would overlap a new timer starting at %s, which could then never be stopped. Stop it with stop_timer first, then start the new one from %s, or log the time with log_time.',
            $entry->getProject()?->getName(),
            $end->format('H:i'),
            $at->format('H:i'),
            $end->format('H:i')
        ));
    }
}

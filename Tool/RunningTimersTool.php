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
use KimaiPlugin\McpServerBundle\Support\Format;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;

/**
 * What the caller is tracking right now: their running entries, with the hours so far.
 */
final class RunningTimersTool extends AbstractReadTool
{
    public function __construct(private readonly TimesheetWriter $writer)
    {
    }

    public function getName(): string
    {
        return 'running_timers';
    }

    public function getDescription(): string
    {
        return 'Your running timers: what you are tracking right now, since when, and the hours so far. Start one '
            . 'with start_timer and stop it with stop_timer.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([]);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_own_timesheet'];
    }

    public function call(array $arguments, User $user): array
    {
        $now = time();
        $timers = array_map(function (Timesheet $entry) use ($now): array {
            $row = $this->writer->describeTimer($entry);
            $row['hours_so_far'] = Format::hours(max(0, $now - (int) $entry->getBegin()?->getTimestamp()));

            return $row;
        }, $this->writer->running($user));

        $out = ['timers' => $timers, 'count' => \count($timers)];
        if ($timers === []) {
            $out['note'] = 'No timer is running.';
        }

        return $out;
    }
}

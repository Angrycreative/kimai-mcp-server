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

/**
 * Deletes one of the caller's own entries, for a duplicate or time logged by mistake. Invoiced entries and other
 * people's are out of reach, whatever the role allows (TimesheetWriter::ownEntry()).
 */
final class DeleteTimesheetTool extends AbstractWriteTool
{
    public function __construct(private readonly TimesheetWriter $writer)
    {
    }

    public function getName(): string
    {
        return 'delete_timesheet';
    }

    public function getDescription(): string
    {
        return 'Deletes one of your own timesheet entries that has not been invoiced, such as a duplicate or time '
            . 'logged by mistake. Find the entry\'s ID with search_timesheets. To fix an entry rather than remove '
            . 'it, use update_timesheet. Kimai\'s own rules apply (locked weeks).';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'id' => Schema::id('The entry (ID from search_timesheets)'),
        ], ['id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['delete_own_timesheet'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $entry = $this->writer->ownEntry((int) Args::int($arguments, 'id'), $user, 'delete');
        $described = $this->writer->describe($entry);

        return new WritePlan(
            ['delete_entry' => $described],
            function () use ($entry, $described): array {
                $id = $entry->getId();
                $this->writer->delete($entry);

                return [
                    'deleted' => $described,
                    ToolHandlerAdapter::CHANGED => ['timesheet:' . $id],
                ];
            }
        );
    }
}

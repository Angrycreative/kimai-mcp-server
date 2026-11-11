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
use KimaiPlugin\McpServerBundle\Category\TimeCategories;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimeSummary;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;

final class AbsenceTool extends AbstractReadTool
{
    public function __construct(
        private readonly TimesheetScope $scope,
        private readonly TimeSummary $summary,
        private readonly TimeCategories $categories,
    ) {
    }

    public function getName(): string
    {
        return 'absence';
    }

    public function getDescription(): string
    {
        return 'Absence logged per person in a period, by type (the activity, such as Holiday or Sick leave), in '
            . 'hours and days with absence. Absence is the time on the projects this Kimai counts as absence.';
    }

    public function getInputSchema(): array
    {
        $filters = TimesheetScope::filterSchema();

        return Schema::object([
            'from' => $filters['from'],
            'to' => $filters['to'],
            'user_ids' => $filters['user_ids'],
            'activity_ids' => Schema::ids('Only these absence types (IDs from find_activities with absence: true)'),
        ], ['from', 'to']);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_own_timesheet|view_other_timesheet'];
    }

    public function call(array $arguments, User $user): array
    {
        $projectIds = $this->categories->provider()->getAbsenceProjectIds();
        if ($projectIds === []) {
            throw new ToolException('No absence projects are configured in this Kimai (System > Settings > MCP server).');
        }

        $arguments['project_ids'] = $projectIds;
        unset($arguments['customer_ids'], $arguments['billable'], $arguments['exported'], $arguments['search']);
        $query = $this->scope->query($arguments, $user);

        $people = [];
        foreach ($this->summary->summarise($query, ['user', 'activity'], false)['rows'] as $row) {
            $id = (string) ($row['user']['id'] ?? '');
            $people[$id] ??= ['user' => $row['user'], 'hours' => 0.0, 'types' => []];
            $people[$id]['hours'] = round($people[$id]['hours'] + $row['hours'], 2);
            $people[$id]['types'][] = [
                'type' => $row['activity']['name'],
                'activity_id' => $row['activity']['id'],
                'hours' => $row['hours'],
                'days' => $row['days'],
                'first_date' => $row['first_date'],
                'last_date' => $row['last_date'],
            ];
        }

        usort($people, static fn (array $a, array $b): int => $b['hours'] <=> $a['hours']);

        $out = ['people' => array_values($people), 'total_hours' => round(array_sum(array_column($people, 'hours')), 2)];
        if (!$this->scope->seesOthers()) {
            $out['note'] = 'Only your own absence: your Kimai role cannot see other people\'s timesheets.';
        }

        return $out;
    }
}

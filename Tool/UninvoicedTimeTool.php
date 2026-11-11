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
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;
use KimaiPlugin\McpServerBundle\Support\TimeSummary;

final class UninvoicedTimeTool extends AbstractReadTool
{
    public function __construct(
        private readonly TimesheetScope $scope,
        private readonly TimeSummary $summary,
    ) {
    }

    public function getName(): string
    {
        return 'uninvoiced_time';
    }

    public function getDescription(): string
    {
        return 'Billable time not yet exported (invoiced), per customer and project, largest first, with the oldest '
            . 'and newest day still open and the amount in the customer\'s currency where you may see rates. '
            . 'Answers "what is left to invoice?" in one call. Hours are as logged, not rounded for invoicing.';
    }

    public function getInputSchema(): array
    {
        $filters = TimesheetScope::filterSchema();
        unset($filters['billable'], $filters['exported']);

        return Schema::object($filters + [
            'by_project' => ['type' => 'boolean', 'description' => 'One row per project rather than per customer. Default: true'],
            'limit' => Schema::limit(100),
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_other_timesheet'];
    }

    public function call(array $arguments, User $user): array
    {
        $arguments['billable'] = true;
        $arguments['exported'] = false;
        $query = $this->scope->query($arguments, $user);

        $groupBy = Args::bool($arguments, 'by_project') === false ? ['customer'] : ['customer', 'project'];
        $result = $this->summary->summarise($query, $groupBy, $this->scope->mayViewRates(true));

        $rows = array_map(static function (array $row): array {
            unset($row['billable_hours'], $row['non_billable_hours'], $row['exported_hours'], $row['uninvoiced_billable_hours'], $row['billable_amount']);

            return $row;
        }, $result['rows']);

        $limit = Args::limit($arguments, 100);
        $out = [
            'rows' => \array_slice($rows, 0, $limit),
            'total_hours' => $result['totals']['hours'],
            'total_entries' => $result['totals']['entries'],
        ];
        if (\count($rows) > $limit) {
            $out['truncated'] = \sprintf('Only the %d largest of %d rows are shown; the totals cover all of them.', $limit, \count($rows));
        }

        return $out;
    }
}

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
use KimaiPlugin\McpServerBundle\Support\TimeSummary;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;

final class TimeSummaryTool extends AbstractReadTool
{
    public function __construct(
        private readonly TimesheetScope $scope,
        private readonly TimeSummary $summary,
    ) {
    }

    public function getName(): string
    {
        return 'time_summary';
    }

    public function getDescription(): string
    {
        return 'Adds up logged time, grouped by any of customer, project, activity, user, date, week, month or '
            . 'category, and filtered like search_timesheets. Each row splits the hours into billable and '
            . 'non-billable, and exported (invoiced) against billable time not yet exported, with the first and last '
            . 'day it covers. Rows grouped by customer or project also carry the billable amount in the customer\'s '
            . 'currency, where you may see rates. Use it for any "how much time" question rather than adding up '
            . 'entries. Hours are as logged, not rounded for invoicing.';
    }

    public function getInputSchema(): array
    {
        return Schema::object(TimesheetScope::filterSchema() + [
            'group_by' => TimeSummary::groupBySchema(),
            'limit' => Schema::limit(100),
        ], ['group_by']);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_own_timesheet|view_other_timesheet'];
    }

    public function call(array $arguments, User $user): array
    {
        $groupBy = array_values(array_filter($arguments['group_by'] ?? [], 'is_string'));
        $query = $this->scope->query($arguments, $user);
        $result = $this->summary->summarise($query, $groupBy, $this->scope->mayViewRates($this->scope->seesOthers()));

        $limit = Args::limit($arguments, 100);
        $rows = $result['rows'];
        $out = ['group_by' => $groupBy, 'rows' => \array_slice($rows, 0, $limit), 'totals' => $result['totals']];
        if (\count($rows) > $limit) {
            $out['truncated'] = \sprintf('Only %d of %d rows are shown; the totals cover all of them. Narrow the filters or raise "limit".', $limit, \count($rows));
        }
        if (!$this->scope->seesOthers()) {
            $out['note'] = 'Only your own time: your Kimai role cannot see other people\'s timesheets.';
        }

        return $out;
    }
}

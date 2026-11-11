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
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Format;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;
use KimaiPlugin\McpServerBundle\Support\TimeSummary;

final class SearchTimesheetsTool extends AbstractReadTool
{
    private const ORDER = [
        'newest' => ['begin', 'DESC'],
        'oldest' => ['begin', 'ASC'],
        'longest' => ['duration', 'DESC'],
    ];

    public function __construct(
        private readonly TimesheetScope $scope,
        private readonly TimeSummary $summary,
    ) {
    }

    public function getName(): string
    {
        return 'search_timesheets';
    }

    public function getDescription(): string
    {
        return 'Lists individual time entries: who logged what, when, on which customer, project and activity, '
            . 'with the description, billable and exported (invoiced) flags and custom fields such as a YouTrack '
            . 'issue key. Filter by period, customers, projects, activities, people, billable, exported or a '
            . 'search term. Paged, with totals for everything that matched. For totals alone use time_summary.';
    }

    public function getInputSchema(): array
    {
        return Schema::object(TimesheetScope::filterSchema() + [
            'order' => ['type' => 'string', 'enum' => array_keys(self::ORDER), 'description' => 'Default: newest'],
            'limit' => Schema::limit(),
            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page of results, starting at 1'],
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_own_timesheet|view_other_timesheet'];
    }

    public function call(array $arguments, User $user): array
    {
        $limit = Args::limit($arguments);
        $page = Args::page($arguments);
        [$orderBy, $order] = self::ORDER[Args::string($arguments, 'order') ?? 'newest'] ?? self::ORDER['newest'];

        $query = $this->scope->query($arguments, $user);
        $totals = $this->summary->summarise(clone $query, [], false)['totals'];

        $query->setOrderBy($orderBy);
        $query->setOrder($order);
        $query->setPageSize($limit);
        $query->setPage($page);
        $pager = $this->scope->repository()->getPagerfantaForQuery($query);

        $entries = [];
        if ($page <= max(1, $pager->getNbPages())) {
            /** @var Timesheet $timesheet */
            foreach ($pager->getCurrentPageResults() as $timesheet) {
                $entries[] = $this->describe($timesheet, $user);
            }
        }

        return [
            'entries' => $entries,
            'page' => $page,
            'pages' => $pager->getNbPages(),
            'total_entries' => $pager->getNbResults(),
            'totals' => $totals,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Timesheet $timesheet, User $user): array
    {
        $owner = $timesheet->getUser();
        $project = $timesheet->getProject();
        $activity = $timesheet->getActivity();
        $duration = $timesheet->getDuration() ?? 0;

        $entry = [
            'id' => $timesheet->getId(),
            'date' => Format::dateTime($timesheet->getBegin(), $user) === null ? null : substr((string) Format::dateTime($timesheet->getBegin(), $user), 0, 10),
            'begin' => Format::dateTime($timesheet->getBegin(), $user),
            'end' => Format::dateTime($timesheet->getEnd(), $user),
            'hours' => Format::hours($duration),
            'minutes' => Format::minutes($duration),
            'user' => ['id' => $owner?->getId(), 'name' => $owner?->getDisplayName()],
            'customer' => ['id' => $project?->getCustomer()?->getId(), 'name' => $project?->getCustomer()?->getName()],
            'project' => ['id' => $project?->getId(), 'name' => $project?->getName()],
            'activity' => ['id' => $activity?->getId(), 'name' => $activity?->getName()],
            'description' => $timesheet->getDescription(),
            'billable' => $timesheet->isBillable(),
            'exported' => $timesheet->isExported(),
            'running' => $timesheet->getEnd() === null,
        ];

        $fields = Format::metaFields($timesheet);
        if ($fields !== []) {
            $entry['custom_fields'] = $fields;
        }

        if ($this->scope->mayViewRates($owner?->getId() !== $user->getId())) {
            $entry['amount'] = Format::money($timesheet->getRate());
            $entry['currency'] = $project?->getCustomer()?->getCurrency();
        }

        return $entry;
    }
}

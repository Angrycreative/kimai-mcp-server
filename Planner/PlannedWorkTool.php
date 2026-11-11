<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Planner;

use App\Entity\User;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Tool\AbstractReadTool;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\PlannerBundle\Repository\PlannedActivityRepository;

final class PlannedWorkTool extends AbstractReadTool
{
    public function __construct(
        private readonly PlannerSupport $support,
        private readonly PlannedActivityRepository $plannings,
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
    ) {
    }

    public function getName(): string
    {
        return 'planned_work';
    }

    public function getDescription(): string
    {
        return 'What is planned in the Weekly Planner in a period: each planning with its person, dates, hours per '
            . 'day, and what it is for (customer and project, reserved time, or time off), marked when tentative. '
            . 'Filter by people, customers or projects. The IDs it returns are what set_planned_hours and '
            . 'remove_planned_work take.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'from' => Schema::date('First day'),
            'to' => Schema::date('Last day, inclusive'),
            'user_ids' => Schema::ids('Only these people. Default: everyone you may see in the planner'),
            'customer_ids' => Schema::ids('Only plannings for these customers'),
            'project_ids' => Schema::ids('Only plannings for these projects'),
            'tentative' => ['type' => 'boolean', 'description' => 'true for tentative plannings only, false for confirmed only. Default: both'],
            'limit' => Schema::limit(),
        ], ['from', 'to']);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_planner'];
    }

    public function call(array $arguments, User $user): array
    {
        $people = $this->support->visibleUsers($arguments, $user);
        $from = PlannerSupport::day($arguments, 'from', $user);
        $to = PlannerSupport::day($arguments, 'to', $user);
        if ($from === null || $to === null || $to < $from) {
            throw new ToolException('"to" cannot be before "from".');
        }

        $customerIds = Args::ids($arguments, 'customer_ids');
        $customers = $customerIds === [] ? [] : array_values($this->customers->findByIds($customerIds));
        $projectIds = Args::ids($arguments, 'project_ids');
        $projects = $projectIds === [] ? [] : array_values($this->projects->findByIds($projectIds));
        // an empty list means "no filter" to the repository, so IDs that match nothing must not get that far
        if (($customerIds !== [] && $customers === []) || ($projectIds !== [] && $projects === [])) {
            throw new ToolException('None of the given customer or project IDs exist.');
        }

        $plannings = $this->plannings->findByFilter($people, $from, $to, $customers, $projects, Args::bool($arguments, 'tentative'));

        $limit = Args::limit($arguments);
        $items = array_map(PlannerSupport::describe(...), \array_slice(array_values($plannings), 0, $limit));

        return $this->listResult('plannings', $items, \count($plannings), $limit);
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\Project;
use App\Entity\User;
use App\Project\ProjectStatisticService;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\ProjectQuery;
use App\Repository\Query\VisibilityInterface;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Budget;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class BudgetOverviewTool extends AbstractReadTool
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly CustomerRepository $customers,
        private readonly ProjectStatisticService $statistics,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'budget_overview';
    }

    public function getDescription(): string
    {
        return 'Every visible project with a time or money budget whose budget you may see, sorted by how little is '
            . 'left (closest to running out first), with budget, used, remaining and share used. Answers "which '
            . 'projects are close to their budget?" in one call. Monthly budgets are for the month of "date".';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'customer_ids' => Schema::ids('Only projects of these customers (IDs from find_customers)'),
            'date' => Schema::date('For monthly budgets, a day in the month to report on. Default: today'),
            'max_remaining_percent' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'description' => 'Only projects with at most this share of their budget left, for example 20'],
            'limit' => Schema::limit(),
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['budget_project|budget_team_project|budget_teamlead_project'];
    }

    public function call(array $arguments, User $user): array
    {
        $query = new ProjectQuery();
        $query->setCurrentUser($user);
        $query->setVisibility(VisibilityInterface::SHOW_VISIBLE);
        if (($ids = Args::ids($arguments, 'customer_ids')) !== []) {
            $customers = $this->customers->findByIds($ids);
            if ($customers === []) {
                throw new ToolException('None of the IDs in "customer_ids" exist.');
            }
            $query->setCustomers($customers);
        }

        $withBudget = array_values(array_filter(
            $this->projects->getProjectsForQuery($query),
            fn (Project $project): bool => ($project->hasBudget() || $project->hasTimeBudget()) && $this->auth->isGranted('budget', $project)
        ));
        if ($withBudget === []) {
            return ['projects' => [], 'count' => 0, 'total' => 0];
        }

        $date = Args::date($arguments, 'date', $user) ?? new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));
        $models = $this->statistics->getBudgetStatisticModelForProjects($withBudget, $date);

        $max = isset($arguments['max_remaining_percent']) && is_numeric($arguments['max_remaining_percent']) ? (float) $arguments['max_remaining_percent'] / 100 : null;

        $rows = [];
        foreach ($withBudget as $project) {
            $model = $models[$project->getId()] ?? null;
            if ($model === null) {
                continue;
            }
            $share = Budget::remainingShare($model);
            if ($max !== null && $share > $max) {
                continue;
            }
            $customer = $project->getCustomer();
            $rows[] = ['share' => $share, 'row' => [
                'project' => ['id' => $project->getId(), 'name' => $project->getName()],
                'customer' => ['id' => $customer?->getId(), 'name' => $customer?->getName()],
                'remaining_percent' => round($share * 100, 1),
            ] + Budget::describe($model, $customer?->getCurrency())];
        }

        usort($rows, static fn (array $a, array $b): int => $a['share'] <=> $b['share']);
        $limit = Args::limit($arguments);
        $items = array_map(static fn (array $entry): array => $entry['row'], \array_slice($rows, 0, $limit));

        return $this->listResult('projects', $items, \count($rows), $limit);
    }
}

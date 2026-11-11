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
use App\Repository\ProjectRepository;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Budget;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ProjectBudgetTool extends AbstractReadTool
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectStatisticService $statistics,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'project_budget';
    }

    public function getDescription(): string
    {
        return 'A project\'s time and money budget: how much it is, how much is used, what remains and the share '
            . 'used. For a monthly budget, the figures are for the month of "date" (default: this month), plus the '
            . 'all-time totals. Same numbers as the project\'s page in Kimai, which counts only billable time.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'project_id' => Schema::id('The project (ID from find_projects)'),
            'date' => Schema::date('For a monthly budget, a day in the month to report on. Default: today'),
        ], ['project_id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['budget_project|budget_team_project|budget_teamlead_project'];
    }

    public function call(array $arguments, User $user): array
    {
        $project = $this->projects->find((int) Args::int($arguments, 'project_id'));
        if (!$project instanceof Project) {
            throw new ToolException('No project with that ID.');
        }
        if (!$this->auth->isGranted('budget', $project)) {
            throw new ToolException('You may not see this project\'s budget.');
        }

        $date = Args::date($arguments, 'date', $user) ?? new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));
        $customer = $project->getCustomer();

        return [
            'project' => ['id' => $project->getId(), 'name' => $project->getName()],
            'customer' => ['id' => $customer?->getId(), 'name' => $customer?->getName()],
            'has_budget' => $project->hasBudget() || $project->hasTimeBudget(),
            'month' => $project->isMonthlyBudget() ? $date->format('Y-m') : null,
        ] + Budget::describe($this->statistics->getBudgetStatisticModel($project, $date), $customer?->getCurrency());
    }
}

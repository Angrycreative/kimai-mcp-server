<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Customer\CustomerStatisticService;
use App\Entity\Customer;
use App\Entity\User;
use App\Repository\CustomerRepository;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Budget;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class CustomerBudgetTool extends AbstractReadTool
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerStatisticService $statistics,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'customer_budget';
    }

    public function getDescription(): string
    {
        return 'A customer\'s own time and money budget (set on the customer, not the sum of its projects): how much '
            . 'it is, used, remaining and the share used, monthly or in total. Same numbers as the customer\'s page '
            . 'in Kimai, which counts only billable time. For project budgets use project_budget or budget_overview.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'customer_id' => Schema::id('The customer (ID from find_customers)'),
            'date' => Schema::date('For a monthly budget, a day in the month to report on. Default: today'),
        ], ['customer_id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['budget_customer|budget_team_customer|budget_teamlead_customer'];
    }

    public function call(array $arguments, User $user): array
    {
        $customer = $this->customers->find((int) Args::int($arguments, 'customer_id'));
        if (!$customer instanceof Customer) {
            throw new ToolException('No customer with that ID.');
        }
        if (!$this->auth->isGranted('budget', $customer)) {
            throw new ToolException('You may not see this customer\'s budget.');
        }

        $date = Args::date($arguments, 'date', $user) ?? new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone()));

        return [
            'customer' => ['id' => $customer->getId(), 'name' => $customer->getName()],
            'has_budget' => $customer->hasBudget() || $customer->hasTimeBudget(),
            'month' => $customer->isMonthlyBudget() ? $date->format('Y-m') : null,
        ] + Budget::describe($this->statistics->getBudgetStatisticModel($customer, $date), $customer->getCurrency());
    }
}

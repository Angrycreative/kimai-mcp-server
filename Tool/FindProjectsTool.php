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
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\ProjectQuery;
use App\Utils\SearchTerm;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Format;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class FindProjectsTool extends AbstractReadTool
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly CustomerRepository $customers,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'find_projects';
    }

    public function getDescription(): string
    {
        return 'Finds projects by name or number, optionally within customers, and returns their IDs for the other '
            . 'tools, with the customer, order number, start and end dates, whether they carry a time or money budget '
            . '(and whether it is monthly), visibility and, where you may see project details, custom fields. Only '
            . 'projects this user may see in Kimai.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'query' => ['type' => 'string', 'description' => 'Words to find in the name, number, order number or comment. Leave out to list all'],
            'customer_ids' => Schema::ids('Only projects of these customers (IDs from find_customers)'),
            'visibility' => Schema::visibility(),
            'limit' => Schema::limit(),
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return [];
    }

    public function call(array $arguments, User $user): array
    {
        $limit = Args::limit($arguments);

        $query = new ProjectQuery();
        $query->setCurrentUser($user);
        $query->loadTeams();
        $query->setVisibility($this->visibility($arguments));
        $query->setOrderBy('name');
        $query->setPageSize($limit);
        $query->setPage(1);
        if (($term = Args::string($arguments, 'query')) !== null) {
            $query->setSearchTerm(new SearchTerm($term));
        }
        if (($ids = Args::ids($arguments, 'customer_ids')) !== []) {
            $customers = $this->customers->findByIds($ids);
            if ($customers === []) {
                throw new ToolException('None of the IDs in "customer_ids" exist.');
            }
            $query->setCustomers($customers);
        }

        $pager = $this->projects->getPagerfantaForQuery($query);

        $items = [];
        /** @var Project $project */
        foreach ($pager->getCurrentPageResults() as $project) {
            $items[] = $this->describe($project);
        }

        return $this->listResult('projects', $items, $pager->getNbResults(), $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Project $project): array
    {
        $customer = $project->getCustomer();

        $item = [
            'id' => $project->getId(),
            'name' => $project->getName(),
            'customer' => ['id' => $customer?->getId(), 'name' => $customer?->getName()],
            'number' => $project->getNumber(),
            'order_number' => $project->getOrderNumber(),
            'start' => Format::date($project->getStart()),
            'end' => Format::date($project->getEnd()),
            'visible' => $project->isVisible() && ($customer?->isVisible() ?? true),
            'billable' => $project->isBillable(),
            'has_time_budget' => $project->hasTimeBudget(),
            'has_money_budget' => $project->hasBudget(),
            'monthly_budget' => $project->isMonthlyBudget(),
        ];

        if ($this->auth->isGranted('details', $project)) {
            $item['custom_fields'] = Format::metaFields($project);
        }

        return $item;
    }
}

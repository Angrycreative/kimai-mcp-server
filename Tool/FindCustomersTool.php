<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\Customer;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\CustomerRepository;
use App\Repository\Query\CustomerQuery;
use App\Utils\SearchTerm;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Format;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class FindCustomersTool extends AbstractReadTool
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'find_customers';
    }

    public function getDescription(): string
    {
        return 'Finds customers by name, number or company, and returns their IDs for the other tools, with number, '
            . 'company, currency, whether they are visible (active) and, where you may see customer details, their '
            . 'custom fields. Only customers this user may see in Kimai.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'query' => ['type' => 'string', 'description' => 'Words to find in the name, number, company or comment. Leave out to list all'],
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

        $query = new CustomerQuery();
        $query->setCurrentUser($user);
        $query->loadTeams();
        $query->setVisibility($this->visibility($arguments));
        $query->setOrderBy('name');
        $query->setPageSize($limit);
        $query->setPage(1);
        if (($term = Args::string($arguments, 'query')) !== null) {
            $query->setSearchTerm(new SearchTerm($term));
        }

        $pager = $this->customers->getPagerfantaForQuery($query);

        $items = [];
        /** @var Customer $customer */
        foreach ($pager->getCurrentPageResults() as $customer) {
            $items[] = $this->describe($customer);
        }

        return $this->listResult('customers', $items, $pager->getNbResults(), $limit);
    }

    /**
     * @return list<string>
     */
    private function teamNames(Customer $customer): array
    {
        $names = [];
        foreach ($customer->getTeams() as $team) {
            if ($team instanceof Team) {
                $names[] = (string) $team->getName();
            }
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Customer $customer): array
    {
        $item = [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'number' => $customer->getNumber(),
            'company' => $customer->getCompany(),
            'currency' => $customer->getCurrency(),
            'country' => $customer->getCountry(),
            'visible' => $customer->isVisible(),
            'billable' => $customer->isBillable(),
            'teams' => $this->teamNames($customer),
        ];

        if ($this->auth->isGranted('details', $customer)) {
            $item['custom_fields'] = Format::metaFields($customer);
        }

        return $item;
    }
}

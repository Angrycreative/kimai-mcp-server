<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Customer\CustomerService;
use App\Entity\User;
use App\Repository\CustomerRepository;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\EntityArguments;
use KimaiPlugin\McpServerBundle\Support\EntityForms;
use KimaiPlugin\McpServerBundle\Support\Schema;

/**
 * Creates a customer through Kimai's own customer form (EntityForms), as Kimai's "Create customer" page does.
 */
final class CreateCustomerTool extends AbstractWriteTool
{
    public function __construct(
        private readonly CustomerRepository $repository,
        private readonly CustomerService $customers,
        private readonly EntityForms $forms,
    ) {
    }

    public function getName(): string
    {
        return 'create_customer';
    }

    public function getDescription(): string
    {
        return 'Creates a customer, as Kimai\'s own "Create customer" page does: its validation applies, budgets need '
            . 'Kimai\'s budget permissions, and custom fields such as billing references can be set. Country, '
            . 'currency, timezone and language default to Kimai\'s settings for new customers. Check with '
            . 'find_customers first that it does not exist already. Its projects are made with create_project.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object(EntityArguments::customerSchema(false), ['name']);
    }

    public function getRequiredPermissions(): array
    {
        return ['create_customer'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $name = Args::string($arguments, 'name');
        if ($name === null) {
            throw new ToolException('A customer needs a name.');
        }

        $fields = EntityArguments::customerFields();
        $customer = $this->customers->createNewCustomer($name);
        $this->forms->submit($this->forms->customerForm($customer), $fields, $arguments, 'Customers');

        $summary = ['create_customer' => $this->forms->describeSet($customer, $fields, $user)];
        $namesake = $this->repository->findOneBy(['name' => $customer->getName()]);
        if ($namesake !== null) {
            $summary['warning'] = \sprintf('There is already a customer called "%s" (ID %d). Make sure the person wants a second one.', $customer->getName(), $namesake->getId());
        }

        return new WritePlan($summary, function () use ($customer, $user, $fields): array {
            $this->customers->saveCustomer($customer);

            return [
                'created' => ['id' => $customer->getId()] + $this->forms->describeSet($customer, $fields, $user),
                ToolHandlerAdapter::CHANGED => ['customer:' . $customer->getId()],
            ];
        });
    }
}

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
use App\Entity\Customer;
use App\Entity\User;
use App\Repository\CustomerRepository;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\EntityArguments;
use KimaiPlugin\McpServerBundle\Support\EntityForms;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Changes a customer through Kimai's own customer form (EntityForms), as its edit page does. Only what is given changes.
 */
final class UpdateCustomerTool extends AbstractWriteTool
{
    public function __construct(
        private readonly CustomerRepository $repository,
        private readonly CustomerService $customers,
        private readonly EntityForms $forms,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'update_customer';
    }

    public function getDescription(): string
    {
        return 'Changes a customer, as Kimai\'s own edit page does: name, number, company details, address, '
            . 'contact details, currency, invoicing details, billable, budgets or custom fields. Only what you give '
            . 'changes. Budgets need Kimai\'s budget permissions, and a custom field Kimai locks for the person stays '
            . 'locked. To hide a customer, use archive_customer.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object(['customer_id' => Schema::id('The customer (ID from find_customers)')] + EntityArguments::customerSchema(true), ['customer_id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['edit_customer'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $customer = $this->repository->find((int) Args::int($arguments, 'customer_id'));
        // one answer for "no such customer" and "not yours to edit", so the name of a customer the caller may not
        // edit never comes back
        if (!$customer instanceof Customer || !$this->auth->isGranted('edit', $customer)) {
            throw new ToolException('No customer with that ID that you may edit.');
        }

        try {
            $fields = EntityArguments::customerFields();
            $this->customers->loadMetaFields($customer);
            $before = $this->forms->describe($customer, $fields, $user);
            $this->forms->submit($this->forms->customerForm($customer), $fields, $arguments, 'Customers');
            $changes = $this->forms->changes($before, $this->forms->describe($customer, $fields, $user));
            if ($changes === []) {
                throw new ToolException(\sprintf('Nothing to change: "%s" already looks like that.', $customer->getName()));
            }

            $summary = ['update_customer' => ['id' => $customer->getId(), 'name' => $before['name']], 'changes' => $changes];
        } catch (\Throwable $e) {
            $this->forms->forget($customer);

            throw $e;
        }

        return new WritePlan($summary, function () use ($customer, $summary): array {
            $this->customers->saveCustomer($customer);

            return ['updated' => $summary['update_customer'], 'changes' => $summary['changes'], ToolHandlerAdapter::CHANGED => ['customer:' . $customer->getId()]];
        }, fn () => $this->forms->forget($customer));
    }
}

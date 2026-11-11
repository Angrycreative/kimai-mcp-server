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
use App\Entity\User;
use App\Repository\CustomerRepository;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Archiver;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ArchiveCustomerTool extends AbstractWriteTool
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly Archiver $archiver,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'archive_customer';
    }

    public function getDescription(): string
    {
        return 'Archives a customer at the end of the relationship: hides the customer and all its visible projects, '
            . 'so nobody can log new time there. Their time and invoices stay. Refuses while billable time on them '
            . 'is not yet invoiced, unless ignore_uninvoiced is true because the person said to archive anyway. '
            . 'Undo by making them visible again in Kimai.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'customer_id' => Schema::id('The customer (ID from find_customers)'),
            'ignore_uninvoiced' => ['type' => 'boolean', 'description' => 'Archive even though billable time is not yet invoiced. Only when the person said so'],
        ], ['customer_id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['edit_customer', 'edit_project'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $customer = $this->customers->find((int) Args::int($arguments, 'customer_id'));
        if (!$customer instanceof Customer || !$this->auth->isGranted('edit', $customer)) {
            throw new ToolException('No customer with that ID that you may edit.');
        }
        if (!$customer->isVisible()) {
            throw new ToolException(\sprintf('"%s" is already archived.', $customer->getName()));
        }

        $projects = $this->archiver->visibleProjects($customer);
        $this->archiver->assertEditable($projects);
        $uninvoiced = $this->archiver->uninvoiced($projects);
        $this->archiver->assertInvoiced($uninvoiced, Args::bool($arguments, 'ignore_uninvoiced') === true, \sprintf('"%s"', $customer->getName()));

        $summary = [
            'hide_customer' => ['id' => $customer->getId(), 'name' => $customer->getName()],
            'hide_projects' => Archiver::names($projects),
            'uninvoiced_billable' => $uninvoiced,
        ];

        return new WritePlan($summary, function () use ($projects, $customer, $summary): array {
            return ['archived' => $summary, ToolHandlerAdapter::CHANGED => $this->archiver->hide($projects, $customer)];
        });
    }
}

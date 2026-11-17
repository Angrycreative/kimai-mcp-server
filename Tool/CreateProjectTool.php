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
use App\Project\ProjectService;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Utils\Context;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\EntityArguments;
use KimaiPlugin\McpServerBundle\Support\EntityForms;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Creates a project through Kimai's own project form (EntityForms), as Kimai's "Create project" page does.
 */
final class CreateProjectTool extends AbstractWriteTool
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projectRepository,
        private readonly ProjectService $projects,
        private readonly EntityForms $forms,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'create_project';
    }

    public function getDescription(): string
    {
        return 'Creates a project for a customer, as Kimai\'s own "Create project" page does: its validation applies, '
            . 'budgets need Kimai\'s budget permissions, and custom fields such as the company entity can be set '
            . '(Kimai refuses a project without the ones it requires). Check with find_projects first that it does '
            . 'not exist already. Activities, rates and teams are set up in Kimai itself.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object(EntityArguments::projectSchema(false), ['customer_id', 'name']);
    }

    public function getRequiredPermissions(): array
    {
        return ['create_project'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $customer = $this->customers->find((int) Args::int($arguments, 'customer_id'));
        if (!$customer instanceof Customer || !$this->auth->isGranted('view', $customer)) {
            throw new ToolException('No customer with that ID that you can see.');
        }

        $fields = EntityArguments::projectFields();
        $project = $this->projects->createNewProject($customer);
        $this->forms->submit($this->forms->projectForm($project, $user), $fields, $arguments, 'Projects');

        $summary = ['create_project' => $this->forms->describeSet($project, $fields, $user)];
        $namesake = $this->projectRepository->findOneBy(['customer' => $customer, 'name' => $project->getName()]);
        if ($namesake !== null) {
            $summary['warning'] = \sprintf('%s already has a project called "%s" (ID %d). Make sure the person wants a second one.', $customer->getName(), $project->getName(), $namesake->getId());
        }

        return new WritePlan($summary, function () use ($project, $user, $fields): array {
            $this->projects->saveProject($project, new Context($user));

            return [
                'created' => ['id' => $project->getId()] + $this->forms->describeSet($project, $fields, $user),
                ToolHandlerAdapter::CHANGED => ['project:' . $project->getId()],
            ];
        });
    }
}

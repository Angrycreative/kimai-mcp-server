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
use App\Project\ProjectService;
use App\Repository\ProjectRepository;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\EntityArguments;
use KimaiPlugin\McpServerBundle\Support\EntityForms;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Changes a project through Kimai's own project form (EntityForms), as its edit page does. Only what is given changes.
 */
final class UpdateProjectTool extends AbstractWriteTool
{
    public function __construct(
        private readonly ProjectRepository $repository,
        private readonly ProjectService $projects,
        private readonly EntityForms $forms,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'update_project';
    }

    public function getDescription(): string
    {
        return 'Changes a project, as Kimai\'s own edit page does: name, number, description, order details, start '
            . 'and end dates, billable, budgets or custom fields, or moves it to another customer. Only what you give '
            . 'changes. Budgets need Kimai\'s budget permissions, and a custom field Kimai locks for the person (such '
            . 'as the company entity) stays locked. To hide a project, use archive_project.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object(['project_id' => Schema::id('The project (ID from find_projects)')] + EntityArguments::projectSchema(true), ['project_id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['edit_project'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $project = $this->repository->find((int) Args::int($arguments, 'project_id'));
        // one answer for "no such project" and "not yours to edit", so the name of a project the caller may not
        // edit never comes back
        if (!$project instanceof Project || !$this->auth->isGranted('edit', $project)) {
            throw new ToolException('No project with that ID that you may edit.');
        }

        $fields = EntityArguments::projectFields();
        $this->projects->loadMetaFields($project);
        $before = $this->forms->describe($project, $fields, $user);
        $this->forms->submit($this->forms->projectForm($project, $user), $fields, $arguments, 'Projects');
        $changes = $this->forms->changes($before, $this->forms->describe($project, $fields, $user));
        if ($changes === []) {
            throw new ToolException(\sprintf('Nothing to change: "%s" already looks like that.', $project->getName()));
        }

        $summary = ['update_project' => ['id' => $project->getId(), 'name' => $before['name']], 'changes' => $changes];
        if (isset($changes['customer'])) {
            $summary['warning'] = 'All time logged on the project moves to the new customer with it, invoiced time included, and so do its budgets, which are in the customer\'s currency.';
        }

        return new WritePlan($summary, function () use ($project, $summary): array {
            $this->projects->saveProject($project);

            return ['updated' => $summary['update_project'], 'changes' => $summary['changes'], ToolHandlerAdapter::CHANGED => ['project:' . $project->getId()]];
        });
    }
}

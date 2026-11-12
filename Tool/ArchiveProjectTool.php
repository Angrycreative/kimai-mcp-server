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
use App\Repository\ProjectRepository;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Archiver;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class ArchiveProjectTool extends AbstractWriteTool
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly Archiver $archiver,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'archive_project';
    }

    public function getDescription(): string
    {
        return 'Archives one project: hides it, so nobody can log new time there. Its time stays. Refuses while '
            . 'billable time on it is not yet invoiced, unless ignore_uninvoiced is true because the person said to '
            . 'archive anyway. For a whole customer, use archive_customer. Undo by making it visible again in Kimai.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'project_id' => Schema::id('The project (ID from find_projects)'),
            'ignore_uninvoiced' => ['type' => 'boolean', 'description' => 'Archive even though billable time is not yet invoiced. Only when the person said so'],
        ], ['project_id']);
    }

    public function getRequiredPermissions(): array
    {
        return ['edit_project'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $project = $this->projects->find((int) Args::int($arguments, 'project_id'));
        // one answer for "no such project" and "not yours to edit", so the name of a project the caller may not
        // edit never comes back
        if (!$project instanceof Project || !$this->auth->isGranted('edit', $project)) {
            throw new ToolException('No project with that ID that you may edit.');
        }
        if (!$project->isVisible()) {
            throw new ToolException(\sprintf('"%s" is already archived.', $project->getName()));
        }
        $uninvoiced = $this->archiver->uninvoiced([$project]);
        $this->archiver->assertInvoiced($uninvoiced, Args::bool($arguments, 'ignore_uninvoiced') === true, \sprintf('"%s"', $project->getName()));

        $summary = [
            'hide_project' => ['id' => $project->getId(), 'name' => $project->getName(), 'customer' => $project->getCustomer()?->getName()],
            'uninvoiced_billable' => $uninvoiced,
        ];

        return new WritePlan($summary, function () use ($project, $summary): array {
            return ['archived' => $summary, ToolHandlerAdapter::CHANGED => $this->archiver->hide([$project])];
        });
    }
}

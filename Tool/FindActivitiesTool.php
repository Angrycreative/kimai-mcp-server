<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\Activity;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\ActivityQuery;
use App\Utils\SearchTerm;
use KimaiPlugin\McpServerBundle\Category\TimeCategories;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;

final class FindActivitiesTool extends AbstractReadTool
{
    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ProjectRepository $projects,
        private readonly TimeCategories $categories,
    ) {
    }

    public function getName(): string
    {
        return 'find_activities';
    }

    public function getDescription(): string
    {
        return 'Finds activities (the kind of work, such as Development or Sick leave) and returns their IDs for the '
            . 'other tools. Give project_ids to get the activities that can be logged on those projects; global '
            . 'activities are included unless switched off. Set absence to true to list the absence types: the '
            . 'activities of the projects this Kimai counts as absence.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'query' => ['type' => 'string', 'description' => 'Words to find in the name, number or comment'],
            'project_ids' => Schema::ids('The activities of these projects (IDs from find_projects)'),
            'include_global' => ['type' => 'boolean', 'description' => 'Include global activities, which belong to no project. Default: true'],
            'absence' => ['type' => 'boolean', 'description' => 'Only the activities of the absence projects (the absence types)'],
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

        $query = new ActivityQuery();
        $query->setCurrentUser($user);
        $query->setVisibility($this->visibility($arguments));
        $query->setOrderBy('name');
        $query->setPageSize($limit);
        $query->setPage(1);
        if (($term = Args::string($arguments, 'query')) !== null) {
            $query->setSearchTerm(new SearchTerm($term));
        }

        $projectIds = Args::ids($arguments, 'project_ids');
        if (Args::bool($arguments, 'absence') === true) {
            $projectIds = $this->categories->provider()->getAbsenceProjectIds();
            if ($projectIds === []) {
                throw new ToolException('No absence projects are configured in this Kimai (System > Settings > MCP server).');
            }
            $query->setExcludeGlobals(true);
        } elseif (Args::bool($arguments, 'include_global') === false) {
            $query->setExcludeGlobals(true);
        }

        if ($projectIds !== []) {
            $projects = $this->projects->findByIds($projectIds);
            if ($projects === []) {
                throw new ToolException('None of the IDs in "project_ids" exist.');
            }
            $query->setProjects($projects);
        }

        $pager = $this->activities->getPagerfantaForQuery($query);

        $items = [];
        /** @var Activity $activity */
        foreach ($pager->getCurrentPageResults() as $activity) {
            $project = $activity->getProject();
            $items[] = [
                'id' => $activity->getId(),
                'name' => $activity->getName(),
                'project' => $project === null ? null : ['id' => $project->getId(), 'name' => $project->getName(), 'customer' => $project->getCustomer()?->getName()],
                'global' => $activity->isGlobal(),
                'visible' => $activity->isVisible(),
                'billable' => $activity->isBillable(),
            ];
        }

        return $this->listResult('activities', $items, $pager->getNbResults(), $limit);
    }
}

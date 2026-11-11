<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Customer\CustomerService;
use App\Entity\Customer;
use App\Entity\Project;
use App\Project\ProjectService;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Archiving in Kimai means switching "Visible" off: the customer or project and its time stay, but nobody can log
 * new time on it and it drops out of the lists. Goes through Kimai's own services, so their events and the audit
 * trail apply.
 */
final class Archiver
{
    public function __construct(
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ProjectRepository $projects,
        private readonly TimesheetRepository $timesheets,
        private readonly ProjectService $projectService,
        private readonly CustomerService $customerService,
    ) {
    }

    /**
     * The customer's projects that are still visible.
     *
     * @return list<Project>
     */
    public function visibleProjects(Customer $customer): array
    {
        return array_values($this->projects->findBy(['customer' => $customer, 'visible' => true], ['name' => 'ASC']));
    }

    /**
     * @param list<Project> $projects
     * @throws ToolException
     */
    public function assertEditable(array $projects): void
    {
        $denied = array_filter($projects, fn (Project $project): bool => !$this->auth->isGranted('edit', $project));
        if ($denied !== []) {
            throw new ToolException(\sprintf(
                'You may not edit %s, so it cannot be archived.',
                implode(', ', array_map(static fn (Project $project): string => \sprintf('"%s" (%d)', $project->getName(), $project->getId()), $denied))
            ));
        }
    }

    /**
     * Billable time on these projects not yet marked invoiced.
     *
     * @param list<Project> $projects
     * @return array{entries: int, hours: float}
     */
    public function uninvoiced(array $projects): array
    {
        if ($projects === []) {
            return ['entries' => 0, 'hours' => 0.0];
        }

        /** @var array{entries: int|string|null, seconds: int|string|null} $row */
        $row = $this->timesheets->createQueryBuilder('t')
            ->select('COUNT(t.id) AS entries, COALESCE(SUM(t.duration), 0) AS seconds')
            ->where('t.project IN (:projects)')
            ->andWhere('t.billable = true')
            ->andWhere('t.exported = false')
            ->andWhere('t.end IS NOT NULL')
            ->setParameter('projects', $projects)
            ->getQuery()
            ->getSingleResult();

        return ['entries' => (int) $row['entries'], 'hours' => Format::hours((int) $row['seconds'])];
    }

    /**
     * Refuses while uninvoiced billable time remains, unless the person accepted that.
     *
     * @param array{entries: int, hours: float} $uninvoiced
     * @throws ToolException
     */
    public function assertInvoiced(array $uninvoiced, bool $accepted, string $what): void
    {
        if ($uninvoiced['entries'] > 0 && !$accepted) {
            throw new ToolException(\sprintf(
                '%s still has %s billable hours in %d entries not yet invoiced (uninvoiced_time shows them). Invoice '
                . 'them first, or call again with ignore_uninvoiced: true if the person says to archive anyway.',
                $what,
                $uninvoiced['hours'],
                $uninvoiced['entries']
            ));
        }
    }

    /**
     * @param list<Project> $projects
     * @return list<string> references to what changed
     */
    public function hide(array $projects, ?Customer $customer = null): array
    {
        $changed = [];
        foreach ($projects as $project) {
            $project->setVisible(false);
            $this->projectService->updateProject($project);
            $changed[] = 'project:' . $project->getId();
        }
        if ($customer !== null) {
            $customer->setVisible(false);
            $this->customerService->updateCustomer($customer);
            $changed[] = 'customer:' . $customer->getId();
        }

        return $changed;
    }

    /**
     * @param list<Project> $projects
     * @return list<array{id: int|null, name: string|null}>
     */
    public static function names(array $projects): array
    {
        return array_map(static fn (Project $project): array => ['id' => $project->getId(), 'name' => $project->getName()], $projects);
    }
}

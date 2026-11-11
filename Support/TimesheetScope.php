<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\Utils\SearchTerm;
use Doctrine\ORM\QueryBuilder;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The time a user may see, filtered the way the tools ask. Everything goes through Kimai's own TimesheetQuery, so
 * the result is exactly what "All times" would show: team visibility, hidden customers and projects, all of it.
 * Without "view_other_timesheet" a user sees only their own time, as on "My times".
 */
final class TimesheetScope
{
    public function __construct(
        private readonly TimesheetRepository $timesheets,
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
        private readonly ActivityRepository $activities,
        private readonly UserRepository $users,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    /**
     * The filter arguments every time tool accepts.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function filterSchema(): array
    {
        return [
            'from' => Schema::date('First day, inclusive'),
            'to' => Schema::date('Last day, inclusive'),
            'customer_ids' => Schema::ids('Only these customers (IDs from find_customers)'),
            'project_ids' => Schema::ids('Only these projects (IDs from find_projects)'),
            'activity_ids' => Schema::ids('Only these activities (IDs from find_activities)'),
            'user_ids' => Schema::ids('Only these people (IDs from find_users). Without permission to see other people\'s time, only your own'),
            'billable' => ['type' => 'boolean', 'description' => 'true: only billable time, false: only non-billable. Leave out for both'],
            'exported' => ['type' => 'boolean', 'description' => 'true: only time already exported (invoiced), false: only time not yet exported. Leave out for both'],
            'search' => ['type' => 'string', 'description' => 'Words to find in the description. "field:value" searches a custom field, for example "youtrack_issue:ABC-123"'],
        ];
    }

    public function seesOthers(): bool
    {
        return $this->auth->isGranted('view_other_timesheet');
    }

    public function mayViewRates(bool $others): bool
    {
        return $others ? $this->auth->isGranted('view_rate_other_timesheet') : $this->auth->isGranted('view_rate_own_timesheet');
    }

    /**
     * @param array<string, mixed> $args
     * @throws ToolException
     */
    public function query(array $args, User $user): TimesheetQuery
    {
        $query = new TimesheetQuery();
        $query->setCurrentUser($user);

        [$from, $to] = Args::range($args, $user);
        if ($from !== null) {
            $query->setBegin($from);
        }
        if ($to !== null) {
            $query->setEnd($to);
        }

        $userIds = Args::ids($args, 'user_ids');
        if (!$this->seesOthers()) {
            if (array_diff($userIds, [$user->getId()]) !== []) {
                throw new ToolException('You can only see your own time: your Kimai role lacks the permission to view other people\'s timesheets.');
            }
            $query->setUser($user);
        } elseif ($userIds !== []) {
            $found = $this->users->findByIds($userIds);
            if ($found === []) {
                throw new ToolException('None of the given user_ids exist.');
            }
            foreach ($found as $foundUser) {
                $query->addUser($foundUser);
            }
        }

        if (($ids = Args::ids($args, 'customer_ids')) !== []) {
            $query->setCustomers($this->require($this->customers->findByIds($ids), 'customer_ids'));
        }
        if (($ids = Args::ids($args, 'project_ids')) !== []) {
            $query->setProjects($this->require($this->projects->findByIds($ids), 'project_ids'));
        }
        if (($ids = Args::ids($args, 'activity_ids')) !== []) {
            $query->setActivities($this->require($this->activities->findByIds($ids), 'activity_ids'));
        }

        $billable = Args::bool($args, 'billable');
        if ($billable !== null) {
            $query->setBillable($billable);
        }

        $exported = Args::bool($args, 'exported');
        if ($exported !== null) {
            $query->setExported($exported ? TimesheetQuery::STATE_EXPORTED : TimesheetQuery::STATE_NOT_EXPORTED);
        }

        if (($search = Args::string($args, 'search')) !== null) {
            $query->setSearchTerm(new SearchTerm($search));
        }

        return $query;
    }

    /**
     * Core's own query for these timesheets, with every filter and permission rule applied, ready for a GROUP BY.
     *
     * Kimai keeps it inside TimesheetResult (where it computes the totals of "All times") without a public
     * accessor. Reading it beats copying core's visibility rules, which would drift on the next Kimai update; if a
     * future Kimai moves it, this fails loudly instead of quietly showing more than it should.
     *
     * @throws ToolException
     */
    public function aggregate(TimesheetQuery $query): QueryBuilder
    {
        $result = $this->timesheets->getTimesheetResult($query);

        try {
            $qb = (new \ReflectionProperty($result, 'statisticQb'))->getValue($result);
        } catch (\ReflectionException) {
            $qb = null;
        }

        if (!$qb instanceof QueryBuilder) {
            throw new ToolException('This tool does not support this Kimai version yet: the timesheet query it builds on has changed.');
        }

        $qb = clone $qb;
        $qb->resetDQLPart('select')->resetDQLPart('orderBy')->setFirstResult(null)->setMaxResults(null);

        return $qb;
    }

    public function repository(): TimesheetRepository
    {
        return $this->timesheets;
    }

    /**
     * @template T of object
     * @param array<T> $found
     * @return array<T>
     * @throws ToolException
     */
    private function require(array $found, string $argument): array
    {
        if ($found === []) {
            throw new ToolException(\sprintf('None of the IDs in "%s" exist.', $argument));
        }

        return $found;
    }
}

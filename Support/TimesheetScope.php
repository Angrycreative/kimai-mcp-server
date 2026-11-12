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
    private const NOUNS = ['user_ids' => 'user', 'customer_ids' => 'customer', 'project_ids' => 'project', 'activity_ids' => 'activity'];

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

        if (!$this->seesOthers()) {
            $query->setUser($user);
        }
        foreach ($this->people(Args::ids($args, 'user_ids'), $user) as $person) {
            $query->addUser($person);
        }

        if (($ids = Args::ids($args, 'customer_ids')) !== []) {
            $query->setCustomers($this->require($ids, $this->customers->findByIds($ids), 'customer_ids'));
        }
        if (($ids = Args::ids($args, 'project_ids')) !== []) {
            $query->setProjects($this->require($ids, $this->projects->findByIds($ids), 'project_ids'));
        }
        if (($ids = Args::ids($args, 'activity_ids')) !== []) {
            $query->setActivities($this->require($ids, $this->activities->findByIds($ids), 'activity_ids'));
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
     * The people named by ID, each checked the way Kimai's own timesheet API checks them: someone outside the
     * caller's teams is refused, not quietly queried. Kimai limits a query to the caller's teams only when it names
     * nobody, so a name given here has to pass the same test first.
     *
     * @param list<int> $ids
     * @return list<User>
     * @throws ToolException
     */
    public function people(array $ids, User $user): array
    {
        if ($ids === []) {
            return [];
        }
        if (!$this->seesOthers()) {
            if (array_diff($ids, [$user->getId()]) !== []) {
                throw new ToolException('You can only see your own time: your Kimai role lacks the permission to view other people\'s timesheets.');
            }

            return [$user];
        }

        $found = $this->require($ids, $this->users->findByIds($ids), 'user_ids');
        $refused = array_map(static fn (User $person): int => (int) $person->getId(), array_filter($found, fn (User $person): bool => !$this->mayAccess($person)));
        if ($refused !== []) {
            throw new ToolException(\sprintf('Kimai does not let you see the time of user %s (only people in teams you lead).', implode(', ', $refused)));
        }

        return array_values($found);
    }

    /**
     * Whether the caller may see this person at all ("access_user", as Kimai's API asks).
     */
    public function mayAccess(User $person): bool
    {
        return $this->auth->isGranted('access_user', $person);
    }

    /**
     * Whether the caller may see this person's working hours and contract, as on Kimai's "Working hours" screen.
     */
    public function maySeeHours(User $person): bool
    {
        return $this->auth->isGranted('hours', $person);
    }

    /**
     * Every requested ID has to exist: a total over some of them would look complete while it is not.
     *
     * @template T of object
     * @param list<int> $ids
     * @param array<T> $found
     * @return array<T>
     * @throws ToolException
     */
    private function require(array $ids, array $found, string $argument): array
    {
        $missing = array_values(array_diff($ids, array_map(static fn (object $entity): int => (int) (method_exists($entity, 'getId') ? $entity->getId() : 0), $found)));
        if ($missing !== []) {
            throw new ToolException(\sprintf('No %s with ID %s.', self::NOUNS[$argument] ?? 'record', implode(', ', $missing)));
        }

        return $found;
    }
}

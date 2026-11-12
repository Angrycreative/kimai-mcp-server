<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Support;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManager;
use KimaiPlugin\McpServerBundle\Category\DefaultTimeCategoryProvider;
use KimaiPlugin\McpServerBundle\Category\TimeCategories;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;
use KimaiPlugin\McpServerBundle\Support\TimeSummary;
use KimaiPlugin\McpServerBundle\Tests\Kimai;
use KimaiPlugin\McpServerBundle\Tests\Settings\McpSettingsTest;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use PHPUnit\Framework\TestCase;

/**
 * A teamlead's view of time, through Kimai's real queries and voters: the case mocks cannot cover, because the rule
 * that keeps a teamlead to their own teams lives in core's query and voters, not in this bundle.
 */
class TeamScopeTest extends TestCase
{
    private EntityManager $em;
    private User $lead;
    private User $member;
    private User $outsider;
    private Customer $customer;

    protected function setUp(): void
    {
        $this->em = Kimai::entityManager();

        $this->lead = $this->user('lead', ['ROLE_TEAMLEAD']);
        $this->member = $this->user('member');
        $this->outsider = $this->user('outsider');
        $team = new Team('Developers');
        $team->addTeamlead($this->lead);
        $team->addUser($this->member);
        $this->em->persist($team);
        // in a team of their own: Kimai lets a teamlead see people who are in no team at all
        $other = new Team('Designers');
        $other->addUser($this->outsider);
        $this->em->persist($other);

        $this->customer = new Customer('ACME');
        $this->customer->setCountry('SE');
        $this->customer->setCurrency('SEK');
        $this->customer->setTimezone('Europe/Stockholm');
        $project = new Project();
        $project->setName('Website');
        $project->setCustomer($this->customer);
        $activity = new Activity();
        $activity->setName('Development');
        $this->em->persist($this->customer);
        $this->em->persist($project);
        $this->em->persist($activity);

        $this->entry($this->member, $project, $activity, 2);
        $this->entry($this->outsider, $project, $activity, 3);
        $this->em->flush();
    }

    /**
     * @param list<string> $roles
     */
    private function user(string $name, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setUserIdentifier($name);
        $user->setEmail($name . '@example.com');
        $user->setPassword('x');
        $user->setTimezone('Europe/Stockholm');
        $user->setRoles($roles);
        $user->setEnabled(true);
        $this->em->persist($user);

        return $user;
    }

    private function entry(User $user, Project $project, Activity $activity, int $hours): void
    {
        $entry = new Timesheet();
        $entry->setUser($user);
        $entry->setProject($project);
        $entry->setActivity($activity);
        $entry->setBegin(new \DateTime('2026-10-14 09:00', new \DateTimeZone('Europe/Stockholm')));
        $entry->setEnd(new \DateTime(\sprintf('2026-10-14 %02d:00', 9 + $hours), new \DateTimeZone('Europe/Stockholm')));
        $entry->setDuration($hours * 3600);
        $entry->setBillable(true);
        $this->em->persist($entry);
    }

    private function scope(): TimesheetScope
    {
        $auth = Kimai::auth($this->lead, ['ROLE_TEAMLEAD' => ['view_own_timesheet', 'view_other_timesheet']]);

        /** @var TimesheetRepository $timesheets */
        $timesheets = $this->em->getRepository(Timesheet::class);
        /** @var CustomerRepository $customers */
        $customers = $this->em->getRepository(Customer::class);
        /** @var ProjectRepository $projects */
        $projects = $this->em->getRepository(Project::class);
        /** @var ActivityRepository $activities */
        $activities = $this->em->getRepository(Activity::class);
        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        return new TimesheetScope($timesheets, $customers, $projects, $activities, $users, $auth);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function summary(array $arguments): array
    {
        $scope = $this->scope();
        $default = new DefaultTimeCategoryProvider(McpSettingsTest::settings([]), $this->em);
        $summary = new TimeSummary($scope, new TimeCategories([$default], $default));

        return $summary->summarise($scope->query($arguments + ['from' => '2026-10-01', 'to' => '2026-10-31'], $this->lead), ['user'], false);
    }

    public function testATeamleadSeesTheirTeamAndNotEveryone(): void
    {
        $result = $this->summary([]);

        $this->assertSame(2.0, $result['totals']['hours']);
        $this->assertSame([(int) $this->member->getId()], array_map(static fn (array $row): int => $row['user']['id'], $result['rows']));
    }

    public function testNamingSomeoneOutsideTheirTeamsIsRefused(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Kimai does not let you see the time of user ' . $this->outsider->getId());

        $this->summary(['user_ids' => [(int) $this->outsider->getId()]]);
    }

    public function testNamingATeamMemberWithACustomerFilterWorks(): void
    {
        $result = $this->summary(['user_ids' => [(int) $this->member->getId()], 'customer_ids' => [(int) $this->customer->getId()]]);

        $this->assertSame(2.0, $result['totals']['hours']);
    }

    public function testAnUnknownIdAmongKnownOnesIsNamed(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No user with ID 9999.');

        $this->summary(['user_ids' => [(int) $this->member->getId(), 9999]]);
    }
}

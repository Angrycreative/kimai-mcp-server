<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Tool;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\WorkingTime\Mode\WorkingTimeModeNone;
use App\WorkingTime\WorkingTimeService;
use Doctrine\ORM\EntityManager;
use KimaiPlugin\McpServerBundle\Capacity\ExpectedHours;
use KimaiPlugin\McpServerBundle\Capacity\PublicHolidaysInterface;
use KimaiPlugin\McpServerBundle\Capacity\WorkingCapacity;
use KimaiPlugin\McpServerBundle\Capacity\WorkingCapacityProviderInterface;
use KimaiPlugin\McpServerBundle\Category\DefaultTimeCategoryProvider;
use KimaiPlugin\McpServerBundle\Category\TimeCategories;
use KimaiPlugin\McpServerBundle\Category\TimeCategoryProviderInterface;
use KimaiPlugin\McpServerBundle\People\People;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;
use KimaiPlugin\McpServerBundle\Support\TimeSummary;
use KimaiPlugin\McpServerBundle\Tests\Kimai;
use KimaiPlugin\McpServerBundle\Tool\UtilisationTool;
use PHPUnit\Framework\TestCase;

/**
 * utilisation over Kimai's own schema in SQLite, in Ascension week 2026: a public holiday expects no hours, and
 * absence logged on it (as many log bank holidays) takes nothing off the hours left to work.
 */
class UtilisationToolTest extends TestCase
{
    private EntityManager $em;
    private User $user;
    private Project $absence;

    protected function setUp(): void
    {
        $this->em = Kimai::entityManager();

        $user = new User();
        $user->setUserIdentifier('anna');
        $user->setEmail('anna@example.com');
        $user->setPassword('x');
        $user->setTimezone('Europe/Stockholm');
        $user->setRoles(['ROLE_USER']);
        $user->setEnabled(true);
        $this->em->persist($user);
        $this->user = $user;

        $customer = new Customer('ACME');
        $customer->setCountry('SE');
        $customer->setCurrency('SEK');
        $customer->setTimezone('Europe/Stockholm');
        $this->em->persist($customer);
        $work = $this->project('Website', $customer);
        $this->absence = $this->project('Absence', $customer);
        $development = $this->activity('Development', $work);
        $bankHoliday = $this->activity('Bank holiday', $this->absence);

        // eight hours worked on Monday to Wednesday and Friday, a bank holiday logged as absence on Thursday
        foreach (['2026-05-11', '2026-05-12', '2026-05-13', '2026-05-15'] as $day) {
            $this->entry($work, $development, $day);
        }
        $this->entry($this->absence, $bankHoliday, '2026-05-14');
        $this->em->flush();
    }

    private function project(string $name, Customer $customer): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setCustomer($customer);
        $this->em->persist($project);

        return $project;
    }

    private function activity(string $name, Project $project): Activity
    {
        $activity = new Activity();
        $activity->setName($name);
        $activity->setProject($project);
        $this->em->persist($activity);

        return $activity;
    }

    private function entry(Project $project, Activity $activity, string $day): void
    {
        $entry = new Timesheet();
        $entry->setUser($this->user);
        $entry->setProject($project);
        $entry->setActivity($activity);
        $entry->setBegin(new \DateTime($day . ' 08:00', new \DateTimeZone('Europe/Stockholm')));
        $entry->setEnd(new \DateTime($day . ' 16:00', new \DateTimeZone('Europe/Stockholm')));
        $entry->setDuration(8 * 3600);
        $this->em->persist($entry);
    }

    /**
     * @param array<string, string>|null $holidays the holiday calendar, or null without the Weekly Planner
     * @return array<string, mixed> the person's row
     */
    private function week(?array $holidays): array
    {
        $auth = Kimai::auth($this->user, ['ROLE_USER' => ['view_own_timesheet', 'hours_own_profile']]);
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
        $scope = new TimesheetScope($timesheets, $customers, $projects, $activities, $users, $auth);

        $absenceId = (int) $this->absence->getId();
        $categories = $this->createMock(TimeCategoryProviderInterface::class);
        $categories->method('getCategories')->willReturn(['work' => 'Work', TimeCategoryProviderInterface::ABSENCE => 'Absence']);
        $categories->method('categoryFor')->willReturnCallback(static fn (?int $project): string => $project === $absenceId ? TimeCategoryProviderInterface::ABSENCE : 'work');
        $categories->method('getAbsenceProjectIds')->willReturn([$absenceId]);
        $time = new TimeCategories([$categories], (new \ReflectionClass(DefaultTimeCategoryProvider::class))->newInstanceWithoutConstructor());

        // no work contract: the organisation's default eight hour weekday
        $workingTime = $this->createMock(WorkingTimeService::class);
        $workingTime->method('getContractMode')->willReturn(new WorkingTimeModeNone());
        $weekdays = $this->createMock(WorkingCapacityProviderInterface::class);
        $weekdays->method('secondsWithoutContract')->willReturnCallback(static fn (User $u, \DateTimeInterface $day): int => (int) $day->format('N') <= 5 ? 8 * 3600 : 0);
        $capacity = new WorkingCapacity([$weekdays]);
        $calendar = null;
        if ($holidays !== null) {
            $calendar = $this->createMock(PublicHolidaysInterface::class);
            $calendar->method('between')->willReturn($holidays);
        }

        $tool = new UtilisationTool($scope, new TimeSummary($scope, $time), $users, new ExpectedHours($workingTime, $capacity, $calendar), $capacity, new People([]));
        $result = $tool->call(['from' => '2026-05-11', 'to' => '2026-05-15', 'user_ids' => [(int) $this->user->getId()]], $this->user);

        return $result['people'][0];
    }

    public function testABankHolidayLoggedAsAbsenceTakesNothingOffTheHoursLeftToWork(): void
    {
        $row = $this->week(['2026-05-14' => 'Ascension Day']);

        $this->assertSame(32.0, $row['contract_hours']);
        $this->assertSame(8.0, $row['absence_hours']);
        $this->assertSame(32.0, $row['available_hours']);
        $this->assertSame(8.0, $row['absence_hours_beyond_expected']);
        $this->assertSame(32.0, $row['worked_hours']);
        $this->assertSame(100.0, $row['worked_percent_of_available']);
    }

    public function testWithoutAHolidayCalendarTheSameWeekComesOutTheSame(): void
    {
        $row = $this->week(null);

        $this->assertSame(40.0, $row['contract_hours']);
        $this->assertSame(32.0, $row['available_hours']);
        $this->assertArrayNotHasKey('absence_hours_beyond_expected', $row);
        $this->assertSame(100.0, $row['worked_percent_of_available']);
    }
}

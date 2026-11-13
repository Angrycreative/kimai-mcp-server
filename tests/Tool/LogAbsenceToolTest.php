<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Tool;

use App\Configuration\ConfigLoaderInterface;
use App\Configuration\SystemConfiguration;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Timesheet;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\Timesheet\TimesheetService;
use App\Timesheet\TrackingMode\TrackingModeInterface;
use App\Timesheet\TrackingModeService;
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
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;
use KimaiPlugin\McpServerBundle\Tests\Kimai;
use KimaiPlugin\McpServerBundle\Tool\LogAbsenceTool;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * log_absence over Kimai's own schema in SQLite: one entry per working day, skipping weekends, days that already hold
 * absence and, when the Weekly Planner's holiday calendar is there, the public holidays the person has off.
 */
class LogAbsenceToolTest extends TestCase
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

        $customer = new Customer('Internal');
        $customer->setCountry('SE');
        $customer->setCurrency('SEK');
        $customer->setTimezone('Europe/Stockholm');
        $this->em->persist($customer);
        $this->absence = new Project();
        $this->absence->setName('Absence');
        $this->absence->setCustomer($customer);
        $this->em->persist($this->absence);
        foreach (['Sick leave', 'Holiday'] as $name) {
            $activity = new Activity();
            $activity->setName($name);
            $activity->setProject($this->absence);
            $this->em->persist($activity);
        }
        $this->em->flush();
    }

    /**
     * @param array<string, string>|null $holidays the holiday calendar, or null without the Weekly Planner
     */
    private function tool(?array $holidays): LogAbsenceTool
    {
        $auth = Kimai::auth($this->user, ['ROLE_USER' => ['create_own_timesheet']]);

        /** @var TimesheetRepository $timesheets */
        $timesheets = $this->em->getRepository(Timesheet::class);
        /** @var ProjectRepository $projects */
        $projects = $this->em->getRepository(Project::class);
        /** @var ActivityRepository $activities */
        $activities = $this->em->getRepository(Activity::class);

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('getConfigurations')->willReturn([]);
        $configuration = new SystemConfiguration($loader, ['timesheet' => ['mode' => 'default', 'default_begin' => '08:00']]);
        $mode = $this->createMock(TrackingModeInterface::class);
        $mode->method('getId')->willReturn('default');
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());
        $service = new TimesheetService(
            $configuration,
            $timesheets,
            new TrackingModeService($configuration, [$mode]),
            $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $auth,
            $validator,
        );
        $writer = new TimesheetWriter(
            $service,
            $auth,
            $this->createMock(UserRepository::class),
            $projects,
            $activities,
            $configuration,
            $timesheets,
            $this->createMock(EventDispatcherInterface::class),
        );

        // no work contract: the organisation's default eight hour weekday
        $workingTime = $this->createMock(WorkingTimeService::class);
        $workingTime->method('getContractMode')->willReturn(new WorkingTimeModeNone());
        $week = $this->createMock(WorkingCapacityProviderInterface::class);
        $week->method('secondsWithoutContract')->willReturnCallback(static fn (User $u, \DateTimeInterface $day): int => (int) $day->format('N') <= 5 ? 8 * 3600 : 0);

        $categories = $this->createMock(TimeCategoryProviderInterface::class);
        $categories->method('getAbsenceProjectFor')->willReturn($this->absence);
        $categories->method('getAbsenceProjectIds')->willReturn([(int) $this->absence->getId()]);

        $calendar = null;
        if ($holidays !== null) {
            $calendar = $this->createMock(PublicHolidaysInterface::class);
            $calendar->method('between')->willReturn($holidays);
        }

        return new LogAbsenceTool(
            $writer,
            new TimeCategories([$categories], (new \ReflectionClass(DefaultTimeCategoryProvider::class))->newInstanceWithoutConstructor()),
            new ExpectedHours($workingTime, new WorkingCapacity([$week]), $calendar),
            $timesheets,
            $activities,
        );
    }

    /**
     * @return array<string, string> the reason by date
     */
    private static function skipped(array $preview): array
    {
        return array_column($preview['would']['skipped'] ?? [], 'reason', 'date');
    }

    public function testPublicHolidaysAreSkipped(): void
    {
        $preview = $this->tool(['2026-12-24' => 'Christmas Eve', '2026-12-25' => 'Christmas Day'])
            ->call(['type' => 'sick leave', 'from' => '2026-12-21', 'to' => '2026-12-27'], $this->user);

        $this->assertSame(['2026-12-21', '2026-12-22', '2026-12-23'], array_column($preview['would']['entries'], 'date'));
        $this->assertSame(24.0, $preview['would']['total_hours']);
        $this->assertSame([
            '2026-12-24' => 'public holiday: Christmas Eve',
            '2026-12-25' => 'public holiday: Christmas Day',
            '2026-12-26' => 'not a working day',
            '2026-12-27' => 'not a working day',
        ], self::skipped($preview));
    }

    public function testAHolidayIsSkippedEvenWithHoursGiven(): void
    {
        $preview = $this->tool(['2026-12-25' => 'Christmas Day'])
            ->call(['type' => 'Sick leave', 'from' => '2026-12-25', 'hours' => 4], $this->user);

        $this->assertSame([], $preview['would']['entries']);
        $this->assertSame('Nothing to log.', $preview['would']['note']);
    }

    public function testDaysWithAbsenceAlreadyLoggedAreSkippedBeforeHolidays(): void
    {
        $entry = new Timesheet();
        $entry->setUser($this->user);
        $entry->setProject($this->absence);
        $entry->setActivity($this->em->getRepository(Activity::class)->findOneBy(['name' => 'Holiday']));
        $entry->setBegin(new \DateTime('2026-12-22 08:00', new \DateTimeZone('Europe/Stockholm')));
        $entry->setEnd(new \DateTime('2026-12-22 16:00', new \DateTimeZone('Europe/Stockholm')));
        $entry->setDuration(8 * 3600);
        $this->em->persist($entry);
        $this->em->flush();

        $preview = $this->tool(['2026-12-24' => 'Christmas Eve'])
            ->call(['type' => 'Sick leave', 'from' => '2026-12-21', 'to' => '2026-12-24'], $this->user);

        $this->assertSame(['2026-12-21', '2026-12-23'], array_column($preview['would']['entries'], 'date'));
        $this->assertSame('absence already logged', self::skipped($preview)['2026-12-22']);
    }

    public function testWithoutTheWeeklyPlannerHolidaysAreLogged(): void
    {
        $preview = $this->tool(null)->call(['type' => 'Sick leave', 'from' => '2026-12-24', 'to' => '2026-12-25'], $this->user);

        $this->assertSame(['2026-12-24', '2026-12-25'], array_column($preview['would']['entries'], 'date'));
    }

    public function testPublicHolidaysLogsTheHolidaysOnlyAtTheHoursTheDayWouldExpect(): void
    {
        $preview = $this->tool(['2026-12-24' => 'Christmas Eve', '2026-12-25' => 'Christmas Day', '2026-12-26' => 'Boxing Day'])
            ->call(['type' => 'Holiday', 'from' => '2026-12-21', 'to' => '2026-12-27', 'public_holidays' => true], $this->user);

        $this->assertSame([
            ['date' => '2026-12-24', 'from' => '08:00', 'hours' => 8.0, 'public_holiday' => 'Christmas Eve'],
            ['date' => '2026-12-25', 'from' => '08:00', 'hours' => 8.0, 'public_holiday' => 'Christmas Day'],
        ], $preview['would']['entries']);
        $skipped = self::skipped($preview);
        $this->assertSame('not a public holiday', $skipped['2026-12-21']);
        // Boxing Day is a Saturday: a holiday, but not a day they were expected to work
        $this->assertSame('not a working day', $skipped['2026-12-26']);
    }
}

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
use Doctrine\ORM\EntityManager;
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;
use KimaiPlugin\McpServerBundle\Tests\Kimai;
use KimaiPlugin\McpServerBundle\Tool\RunningTimersTool;
use KimaiPlugin\McpServerBundle\Tool\StartTimerTool;
use KimaiPlugin\McpServerBundle\Tool\StopTimerTool;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The timer tools over Kimai's own schema in SQLite. Kimai's validator is stubbed, so these cover what the tools
 * decide themselves: which timer, when it starts and stops, and the overlaps Kimai's own check would only find once
 * a timer is stopped.
 */
class TimerToolsTest extends TestCase
{
    private const ALL = ['ROLE_USER' => ['view_own_timesheet', 'create_own_timesheet', 'start_own_timesheet', 'stop_own_timesheet', 'edit_own_timesheet']];

    private EntityManager $em;
    private User $user;
    private Project $project;
    private Activity $activity;

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
        $this->project = new Project();
        $this->project->setName('Website');
        $this->project->setCustomer($customer);
        $this->em->persist($this->project);
        $this->activity = new Activity();
        $this->activity->setName('Development');
        $this->activity->setProject($this->project);
        $this->em->persist($this->activity);
        $this->em->flush();
    }

    /**
     * @param array<string, list<string>> $permissions
     */
    private function writer(array $permissions = self::ALL): TimesheetWriter
    {
        $auth = Kimai::auth($this->user, $permissions);
        /** @var TimesheetRepository $timesheets */
        $timesheets = $this->em->getRepository(Timesheet::class);
        /** @var ProjectRepository $projects */
        $projects = $this->em->getRepository(Project::class);
        /** @var ActivityRepository $activities */
        $activities = $this->em->getRepository(Activity::class);

        $loader = $this->createMock(ConfigLoaderInterface::class);
        $loader->method('getConfigurations')->willReturn([]);
        $configuration = new SystemConfiguration($loader, ['timesheet' => [
            'mode' => 'default',
            'active_entries' => ['hard_limit' => 1],
            'rules' => ['allow_overlapping_records' => false],
        ]]);
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

        return new TimesheetWriter(
            $service,
            $auth,
            $this->createMock(UserRepository::class),
            $projects,
            $activities,
            $configuration,
            $timesheets,
            $this->createMock(EventDispatcherInterface::class),
        );
    }

    private function entry(\DateTime $begin, ?\DateTime $end = null, string $description = 'Earlier work'): Timesheet
    {
        $entry = new Timesheet();
        $entry->setUser($this->user);
        $entry->setProject($this->project);
        $entry->setActivity($this->activity);
        $entry->setBegin($begin);
        if ($end !== null) {
            $entry->setEnd($end);
            $entry->setDuration($end->getTimestamp() - $begin->getTimestamp());
        }
        $entry->setDescription($description);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function today(string $time): \DateTime
    {
        return new \DateTime('today ' . $time, new \DateTimeZone('Europe/Stockholm'));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function confirm(StartTimerTool|StopTimerTool $tool, array $arguments): array
    {
        $preview = $tool->call($arguments, $this->user);
        $this->assertTrue($preview['preview']);

        return $tool->call($arguments + ['user_confirmed' => true, 'preview_token' => $preview['preview_token']], $this->user);
    }

    public function testAPreviewOfATimerStartingNowNamesNoTimeSoItsTokenHolds(): void
    {
        $preview = (new StartTimerTool($this->writer()))->call(['project_id' => $this->project->getId(), 'activity_id' => $this->activity->getId()], $this->user);

        $this->assertSame('now, when you confirm', $preview['would']['start_timer']['from']);
        $this->assertArrayNotHasKey('date', $preview['would']['start_timer']);
        $this->assertArrayNotHasKey('hours', $preview['would']['start_timer']);
    }

    public function testStartingStopsTheRunningTimerExactlyWhereTheNewOneStarts(): void
    {
        $writer = $this->writer();
        $old = $this->entry($writer->minute($this->user)->modify('-1 hour'));

        $result = $this->confirm(new StartTimerTool($writer), ['project_id' => $this->project->getId(), 'activity_id' => $this->activity->getId(), 'description' => 'New work']);

        $this->assertSame([(int) $old->getId()], $result['stopped']);
        $running = $writer->running($this->user);
        $this->assertCount(1, $running);
        $this->assertSame('New work', $running[0]->getDescription());
        $this->assertEquals($old->getEnd(), $running[0]->getBegin(), 'the old timer ends where the new one starts, on the whole minute');
        $this->assertSame('00', $running[0]->getBegin()?->format('s'));
    }

    public function testStartingFromAnEarlierTimeStopsTheRunningTimerThen(): void
    {
        $writer = $this->writer();
        $old = $this->entry($this->today('00:00'));
        $start = $writer->minute($this->user)->format('H:i');

        $this->confirm(new StartTimerTool($writer), ['project_id' => $this->project->getId(), 'activity_id' => $this->activity->getId(), 'start' => $start]);

        $this->assertSame($start, $old->getEnd()?->format('H:i'));
    }

    public function testAStartBeforeTheRunningTimerIsRefusedAsTheyWouldOverlap(): void
    {
        $writer = $this->writer();
        $this->entry($writer->minute($this->user));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('would overlap');
        (new StartTimerTool($writer))->call(['project_id' => $this->project->getId(), 'activity_id' => $this->activity->getId(), 'start' => '00:00'], $this->user);
    }

    public function testAStartBeforeAFinishedEntryLaterTodayIsRefused(): void
    {
        $this->entry($this->today('00:00:30'), $this->today('00:00:40'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('would overlap it');
        (new StartTimerTool($this->writer()))->call(['project_id' => $this->project->getId(), 'activity_id' => $this->activity->getId(), 'start' => '00:00'], $this->user);
    }

    public function testATimerCannotStartInTheFuture(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('later than now');
        (new StartTimerTool($this->writer()))->call(['project_id' => $this->project->getId(), 'activity_id' => $this->activity->getId(), 'start' => '23:59'], $this->user);
    }

    public function testStoppingWithoutAnIdStopsTheOnlyRunningTimerOnTheMinute(): void
    {
        $writer = $this->writer();
        $entry = $this->entry($writer->minute($this->user)->modify('-30 minutes'));

        $result = $this->confirm(new StopTimerTool($writer), ['description' => 'Done']);

        $this->assertSame($entry->getId(), $result['stopped']['id']);
        $this->assertSame('Done', $entry->getDescription());
        $this->assertEquals($writer->minute($this->user), $entry->getEnd());
        $this->assertSame([], $writer->running($this->user));
    }

    public function testStoppingAtAnEarlierEndUsesThatTime(): void
    {
        $writer = $this->writer();
        $entry = $this->entry($this->today('00:00'));
        $end = $writer->minute($this->user)->format('H:i');

        $this->confirm(new StopTimerTool($writer), ['end' => $end]);

        $this->assertSame($end, $entry->getEnd()?->format('H:i'));
    }

    public function testStoppingAsksWhichWhenSeveralAreRunning(): void
    {
        $writer = $this->writer();
        $this->entry($this->today('00:00'));
        $this->entry($this->today('00:00'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('2 timers are running');
        (new StopTimerTool($writer))->call([], $this->user);
    }

    public function testStoppingWhenNothingRunsSaysSo(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No timer is running');
        (new StopTimerTool($this->writer()))->call([], $this->user);
    }

    public function testAnEndOrDescriptionIsAnEditAndNeedsTheEditPermission(): void
    {
        $this->entry($this->today('00:00'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('can only be stopped now');
        (new StopTimerTool($this->writer(['ROLE_USER' => ['view_own_timesheet', 'stop_own_timesheet']])))->call(['description' => 'Done'], $this->user);
    }

    public function testRunningTimersShowsTheHoursSoFar(): void
    {
        $writer = $this->writer();
        $this->entry($writer->minute($this->user)->modify('-90 minutes'));

        $result = (new RunningTimersTool($writer))->call([], $this->user);

        $this->assertSame(1, $result['count']);
        $this->assertGreaterThanOrEqual(1.5, $result['timers'][0]['hours_so_far']);
        $this->assertArrayNotHasKey('running', $result['timers'][0]);
    }
}

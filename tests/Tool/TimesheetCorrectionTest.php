<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Tool;

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
use App\Timesheet\TrackingModeService;
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;
use KimaiPlugin\McpServerBundle\Tests\Kimai;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use KimaiPlugin\McpServerBundle\Tool\DeleteTimesheetTool;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\McpServerBundle\Tool\UpdateTimesheetTool;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * update_timesheet and delete_timesheet, decided by Kimai's real voters: only the caller's own entries, never
 * invoiced ones, and a preview that leaves the entry exactly as it was.
 */
class TimesheetCorrectionTest extends TestCase
{
    private const OWN = ['ROLE_USER' => ['view_own_timesheet', 'edit_own_timesheet', 'delete_own_timesheet']];

    private User $user;
    private Project $project;
    private Activity $activity;
    /** @var list<string> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->user = TestUser::make(5);
        $this->project = $this->project('Website', 1);
        $this->activity = $this->activity('Development', 1);
        $this->saved = [];
    }

    private function project(string $name, int $id): Project
    {
        $project = new Project();
        $project->setName($name);
        $project->setCustomer(new Customer('ACME'));
        (new \ReflectionProperty(Project::class, 'id'))->setValue($project, $id);

        return $project;
    }

    private function activity(string $name, int $id, ?Project $project = null): Activity
    {
        $activity = new Activity();
        $activity->setName($name);
        $activity->setProject($project);
        (new \ReflectionProperty(Activity::class, 'id'))->setValue($activity, $id);

        return $activity;
    }

    /**
     * 2 hours from 09:00 on 2026-11-10, Stockholm time.
     */
    private function entry(?User $owner = null): Timesheet
    {
        $entry = new Timesheet();
        (new \ReflectionProperty(Timesheet::class, 'id'))->setValue($entry, 42);
        $entry->setUser($owner ?? $this->user);
        $entry->setProject($this->project);
        $entry->setActivity($this->activity);
        $begin = new \DateTime('2026-11-10 09:00', new \DateTimeZone('Europe/Stockholm'));
        $entry->setBegin($begin);
        $entry->setEnd((clone $begin)->modify('+2 hours'));
        $entry->setDuration(7200);
        $entry->setDescription('Fixing the header');

        return $entry;
    }

    /**
     * @param array<string, list<string>> $permissions
     * @param list<Project> $projects
     */
    private function writer(Timesheet $entry, array $permissions, AuthorizationCheckerInterface &$auth = null, array $projects = []): TimesheetWriter
    {
        $auth = Kimai::auth($this->user, $permissions);

        $timesheets = $this->createMock(TimesheetRepository::class);
        $timesheets->method('find')->willReturn($entry);
        $timesheets->method('save')->willReturnCallback(function (Timesheet $t): void { $this->saved[] = 'save:' . $t->getId(); });
        $timesheets->method('delete')->willReturnCallback(function (Timesheet $t): void { $this->saved[] = 'delete:' . $t->getId(); });

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $none = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $service = new TimesheetService(
            $none(SystemConfiguration::class),
            $timesheets,
            $none(TrackingModeService::class),
            $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class),
            $auth,
            $validator,
        );

        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->method('find')->willReturnCallback(static function (int $id) use ($projects): ?Project {
            foreach ($projects as $project) {
                if ($project->getId() === $id) {
                    return $project;
                }
            }

            return null;
        });
        $activities = $this->createMock(ActivityRepository::class);
        $activities->method('find')->willReturnCallback(fn (int $id): ?Activity => $id === 1 ? $this->activity : null);

        return new TimesheetWriter(
            $service,
            $auth,
            $this->createMock(UserRepository::class),
            $projectRepository,
            $activities,
            $none(SystemConfiguration::class),
            $timesheets,
            $this->createMock(EventDispatcherInterface::class),
        );
    }

    /**
     * @param array<string, list<string>> $permissions
     * @param list<Project> $projects
     */
    private function update(Timesheet $entry, array $permissions = self::OWN, array $projects = []): UpdateTimesheetTool
    {
        $writer = $this->writer($entry, $permissions, $auth, $projects);
        \assert($auth instanceof AuthorizationCheckerInterface);

        return new UpdateTimesheetTool($writer, $auth);
    }

    public function testMovingToAnotherDayKeepsTheStartAndHours(): void
    {
        $entry = $this->entry();
        $preview = $this->update($entry)->call(['id' => 42, 'date' => '2026-11-11'], $this->user);

        $change = $preview['would']['change_entry'];
        $this->assertSame('2026-11-10', $change['before']['date']);
        $this->assertSame(['date' => '2026-11-11', 'from' => '09:00', 'to' => '11:00', 'hours' => 2.0], array_intersect_key($change['after'], array_flip(['date', 'from', 'to', 'hours'])));
        $this->assertSame(['date'], $change['changes']);
    }

    public function testAPreviewLeavesTheEntryAsItWas(): void
    {
        $entry = $this->entry();
        $this->update($entry)->call(['id' => 42, 'date' => '2026-11-11', 'start' => '13:30', 'hours' => 1.5, 'description' => 'Header and footer'], $this->user);

        $this->assertSame('2026-11-10 09:00', $entry->getBegin()?->format('Y-m-d H:i'));
        $this->assertSame('2026-11-10 11:00', $entry->getEnd()?->format('Y-m-d H:i'));
        $this->assertSame(7200, $entry->getDuration());
        $this->assertSame('Fixing the header', $entry->getDescription());
        $this->assertSame([], $this->saved);
    }

    public function testAConfirmedChangeIsSaved(): void
    {
        $entry = $this->entry();
        $tool = $this->update($entry);
        $arguments = ['id' => 42, 'end' => '10:30', 'description' => ''];
        $preview = $tool->call($arguments, $this->user);

        $done = $tool->call($arguments + ['user_confirmed' => true, 'preview_token' => $preview['preview_token']], $this->user);

        $this->assertTrue($done['done']);
        $this->assertSame(['save:42'], $this->saved);
        $this->assertSame(5400, $entry->getDuration());
        $this->assertNull($entry->getDescription());
    }

    public function testAProjectSpecificActivityHasToBeReplacedWhenMovingProject(): void
    {
        $this->activity = $this->activity('Design', 1, $this->project);
        $entry = $this->entry();

        try {
            $this->update($entry, self::OWN, [$this->project('Intranet', 2)])->call(['id' => 42, 'project_id' => 2], $this->user);
            $this->fail('The move went through without an activity of the new project.');
        } catch (ToolException $e) {
            $this->assertStringContainsString('Give activity_id', $e->getMessage());
        }
        $this->assertSame('Website', $entry->getProject()?->getName());
    }

    public function testAGlobalActivityMovesWithTheEntry(): void
    {
        $entry = $this->entry();
        $preview = $this->update($entry, self::OWN, [$this->project('Intranet', 2)])->call(['id' => 42, 'project_id' => 2], $this->user);

        $this->assertSame('Intranet', $preview['would']['change_entry']['after']['project']['name']);
        $this->assertSame('Development', $preview['would']['change_entry']['after']['activity']['name']);
        $this->assertSame('Website', $entry->getProject()?->getName());
    }

    public function testNothingToChangeIsRefused(): void
    {
        $this->expectExceptionMessage('That changes nothing');

        $this->update($this->entry())->call(['id' => 42, 'start' => '09:00', 'description' => 'Fixing the header'], $this->user);
    }

    public function testSomeoneElsesEntryReadsAsMissingEvenToThoseWhoMayEditIt(): void
    {
        $this->expectExceptionMessage('You have no entry with that ID');

        $this->update($this->entry(TestUser::make(6)), ['ROLE_USER' => ['edit_own_timesheet', 'edit_other_timesheet']])
            ->call(['id' => 42, 'hours' => 1], $this->user);
    }

    public function testInvoicedTimeIsRefusedEvenToThoseWhoMayEditIt(): void
    {
        $entry = $this->entry();
        $entry->setExported(true);
        $this->expectExceptionMessage('has been invoiced');

        $this->update($entry, ['ROLE_USER' => ['edit_own_timesheet', 'edit_exported_timesheet']])->call(['id' => 42, 'hours' => 1], $this->user);
    }

    public function testARunningEntryIsRefused(): void
    {
        $entry = $this->entry();
        $entry->setEnd(null);
        $this->expectExceptionMessage('still running');

        $this->update($entry)->call(['id' => 42, 'description' => 'x'], $this->user);
    }

    public function testKimaisOwnPermissionDecides(): void
    {
        $this->expectExceptionMessage('Kimai does not allow changing this entry');

        $this->update($this->entry(), ['ROLE_USER' => ['view_own_timesheet']])->call(['id' => 42, 'hours' => 1], $this->user);
    }

    public function testDeleteOnlyPreviewsUntilConfirmed(): void
    {
        $tool = new DeleteTimesheetTool($this->writer($this->entry(), self::OWN));
        $preview = $tool->call(['id' => 42], $this->user);

        $this->assertSame('2026-11-10', $preview['would']['delete_entry']['date']);
        $this->assertSame([], $this->saved);

        $done = $tool->call(['id' => 42, 'user_confirmed' => true, 'preview_token' => $preview['preview_token']], $this->user);
        $this->assertSame(42, $done['deleted']['id']);
        $this->assertSame(['delete:42'], $this->saved);
    }

    public function testDeletingNeedsKimaisDeletePermission(): void
    {
        $this->expectExceptionMessage('Kimai does not allow deleting this entry');

        (new DeleteTimesheetTool($this->writer($this->entry(), ['ROLE_USER' => ['edit_own_timesheet']])))->call(['id' => 42], $this->user);
    }
}

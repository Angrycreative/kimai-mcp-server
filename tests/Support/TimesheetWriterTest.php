<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Support;

use App\Configuration\SystemConfiguration;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\ActivityRepository;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Timesheet\TimesheetService;
use KimaiPlugin\McpServerBundle\Support\TimesheetWriter;
use KimaiPlugin\McpServerBundle\Tests\Kimai;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use PHPUnit\Framework\TestCase;

/**
 * Which projects an ordinary employee may log to, decided by Kimai's real voters with the permissions a plain
 * ROLE_USER has out of the box: none of the project administration ones.
 */
class TimesheetWriterTest extends TestCase
{
    private function writer(User $user, Project $project): TimesheetWriter
    {
        $projects = $this->createMock(ProjectRepository::class);
        $projects->method('find')->willReturn($project);

        return new TimesheetWriter(
            (new \ReflectionClass(TimesheetService::class))->newInstanceWithoutConstructor(),
            Kimai::auth($user, ['ROLE_USER' => ['view_own_timesheet', 'create_own_timesheet']]),
            $this->createMock(UserRepository::class),
            $projects,
            $this->createMock(ActivityRepository::class),
            (new \ReflectionClass(SystemConfiguration::class))->newInstanceWithoutConstructor(),
        );
    }

    private function project(?Team $team = null): Project
    {
        $project = new Project();
        $project->setName('Website');
        $project->setCustomer(new Customer('ACME'));
        if ($team !== null) {
            $project->addTeam($team);
        }

        return $project;
    }

    public function testAnEmployeeMayLogToAProjectOpenToEveryone(): void
    {
        $user = TestUser::make(5);
        $project = $this->project();

        $this->assertSame($project, $this->writer($user, $project)->project(1));
    }

    public function testAnEmployeeMayLogToTheirTeamsProject(): void
    {
        $user = TestUser::make(5);
        $team = new Team('Developers');
        $team->addUser($user);
        $project = $this->project($team);

        $this->assertSame($project, $this->writer($user, $project)->project(1));
    }

    public function testAnotherTeamsProjectIsRefused(): void
    {
        $other = new Team('Designers');
        $other->addUser(TestUser::make(6));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No project with that ID that you may see.');

        $this->writer(TestUser::make(5), $this->project($other))->project(1);
    }
}

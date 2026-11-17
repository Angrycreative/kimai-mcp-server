<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Tool;

use App\Entity\Customer;
use App\Entity\Project;
use App\Project\ProjectService;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\McpServerBundle\Support\EntityForms;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use KimaiPlugin\McpServerBundle\Tool\CreateProjectTool;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\McpServerBundle\Tool\UpdateProjectTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Forms;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The checks the tools make before Kimai's form is involved.
 */
final class CustomerProjectToolsTest extends TestCase
{
    public function testANewProjectIsRefusedForAnArchivedCustomer(): void
    {
        $customer = new Customer('Old client');
        $customer->setVisible(false);
        $customers = $this->createStub(CustomerRepository::class);
        $customers->method('find')->willReturn($customer);

        $tool = new CreateProjectTool($customers, $this->createStub(ProjectRepository::class), $this->projectService(), $this->forms(), $this->auth(true));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Old client is archived');
        $tool->call(['customer_id' => 5, 'name' => 'New work'], TestUser::make(1));
    }

    public function testANewProjectNeedsACustomerThePersonMaySee(): void
    {
        $customers = $this->createStub(CustomerRepository::class);
        $customers->method('find')->willReturn(new Customer('Someone else\'s'));

        $tool = new CreateProjectTool($customers, $this->createStub(ProjectRepository::class), $this->projectService(), $this->forms(), $this->auth(false));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No customer with that ID that you can see.');
        $tool->call(['customer_id' => 5, 'name' => 'New work'], TestUser::make(1));
    }

    public function testAProjectThePersonMayNotEditIsNotNamed(): void
    {
        $project = new Project();
        $project->setName('Secret');
        $projects = $this->createStub(ProjectRepository::class);
        $projects->method('find')->willReturn($project);

        $tool = new UpdateProjectTool($projects, $this->projectService(), $this->forms(), $this->auth(false));

        try {
            $tool->call(['project_id' => 7, 'name' => 'Renamed'], TestUser::make(1));
            $this->fail('A project the person may not edit was changed.');
        } catch (ToolException $e) {
            $this->assertSame('No project with that ID that you may edit.', $e->getMessage());
        }
    }

    private function forms(): EntityForms
    {
        return new EntityForms(Forms::createFormFactory(), $this->createStub(AuthorizationCheckerInterface::class), $this->createStub(EntityManagerInterface::class));
    }

    private function auth(bool $granted): AuthorizationCheckerInterface
    {
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturn($granted);

        return $auth;
    }

    /**
     * Kimai's service is final and is not reached by these checks.
     */
    private function projectService(): ProjectService
    {
        return (new \ReflectionClass(ProjectService::class))->newInstanceWithoutConstructor();
    }
}

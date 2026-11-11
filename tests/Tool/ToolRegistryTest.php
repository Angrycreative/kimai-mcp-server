<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Tool;

use KimaiPlugin\McpServerBundle\Tool\ToolInterface;
use KimaiPlugin\McpServerBundle\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class ToolRegistryTest extends TestCase
{
    /**
     * @param list<string> $permissions
     */
    private function tool(string $name, array $permissions, bool $readOnly = true): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('getRequiredPermissions')->willReturn($permissions);
        $tool->method('isReadOnly')->willReturn($readOnly);

        return $tool;
    }

    /**
     * @param list<string> $granted
     * @param list<ToolInterface> $tools
     * @return list<string>
     */
    private function available(array $granted, array $tools): array
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $attribute): bool => \in_array($attribute, $granted, true));

        return array_map(static fn (ToolInterface $tool): string => $tool->getName(), (new ToolRegistry($tools, $auth))->available());
    }

    public function testToolsNeedEveryPermissionTheyName(): void
    {
        $tools = [$this->tool('open', []), $this->tool('users', ['view_user']), $this->tool('both', ['view_user', 'view_other_timesheet'])];

        $this->assertSame(['open', 'users'], $this->available(['view_user'], $tools));
    }

    public function testAlternativesNeedOnlyOne(): void
    {
        $tools = [$this->tool('budget', ['budget_project|budget_team_project'])];

        $this->assertSame(['budget'], $this->available(['budget_team_project'], $tools));
        $this->assertSame([], $this->available(['view_user'], $tools));
    }

    public function testWritesAlsoNeedMcpWrite(): void
    {
        $tools = [$this->tool('log_time', ['create_own_timesheet'], false)];

        $this->assertSame([], $this->available(['create_own_timesheet'], $tools));
        $this->assertSame(['log_time'], $this->available(['create_own_timesheet', 'mcp_write'], $tools));
    }
}

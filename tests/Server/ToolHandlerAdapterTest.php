<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Server;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use KimaiPlugin\McpServerBundle\CallLog\CallLog;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;
use KimaiPlugin\McpServerBundle\Tests\Settings\McpSettingsTest;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\McpServerBundle\Tool\ToolInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\CallToolResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\ClientGateway;
use PHPUnit\Framework\TestCase;

class ToolHandlerAdapterTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $logged = [];

    private function callLog(bool $logReads = true): CallLog
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('insert')->willReturnCallback(function (string $table, array $row): int {
            $this->logged[] = $row;

            return 1;
        });

        return new CallLog($connection, McpSettingsTest::settings([McpSettings::LOG_READS => $logReads ? '1' : '0']));
    }

    /**
     * @param \Closure(array<string, mixed>, User): array<string, mixed> $call
     */
    private function tool(bool $readOnly, \Closure $call): ToolInterface
    {
        $tool = $this->createMock(ToolInterface::class);
        $tool->method('getName')->willReturn('demo');
        $tool->method('isReadOnly')->willReturn($readOnly);
        $tool->method('call')->willReturnCallback($call);

        return $tool;
    }

    private function gateway(): ClientGateway
    {
        return (new \ReflectionClass(ClientGateway::class))->newInstanceWithoutConstructor();
    }

    public function testResultIsReturnedAsTextAndStructuredContentAndLogged(): void
    {
        $adapter = new ToolHandlerAdapter($this->tool(true, static fn (): array => ['hours' => 1.5]), TestUser::make(3), $this->callLog(), 'Claude Code 2.1');

        $result = $adapter->execute(['from' => '2026-10-01'], $this->gateway());

        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertFalse($result->isError);
        $this->assertSame(['hours' => 1.5], $result->structuredContent);
        $this->assertCount(1, $this->logged);
        $this->assertSame('demo', $this->logged[0]['tool']);
        $this->assertSame('Claude Code 2.1', $this->logged[0]['client']);
        $this->assertSame(3, $this->logged[0]['user_id']);
        $this->assertSame('{"from":"2026-10-01"}', $this->logged[0]['arguments']);
        $this->assertSame(1, $this->logged[0]['success']);
        $this->assertSame(0, $this->logged[0]['is_write']);
    }

    public function testToolErrorsReachTheModelAndTheLog(): void
    {
        $adapter = new ToolHandlerAdapter($this->tool(true, static fn (): array => throw new ToolException('No project with that ID.')), TestUser::make(3), $this->callLog(), null);

        $result = $adapter->execute([], $this->gateway());

        $this->assertTrue($result->isError);
        $this->assertSame(0, $this->logged[0]['success']);
        $this->assertSame('No project with that ID.', $this->logged[0]['error']);
    }

    public function testReadsAreNotLoggedWhenSwitchedOffButWritesAlwaysAre(): void
    {
        $log = $this->callLog(false);

        (new ToolHandlerAdapter($this->tool(true, static fn (): array => []), TestUser::make(3), $log, null))->execute([], $this->gateway());
        $this->assertSame([], $this->logged);

        (new ToolHandlerAdapter($this->tool(false, static fn (): array => ['done' => true, ToolHandlerAdapter::CHANGED => ['timesheet:7']]), TestUser::make(3), $log, null))->execute([], $this->gateway());
        $this->assertCount(1, $this->logged);
        $this->assertSame(1, $this->logged[0]['is_write']);
        $this->assertSame('timesheet:7', $this->logged[0]['changed']);
    }

    public function testTheChangeListGoesToTheLogNotToTheModel(): void
    {
        $adapter = new ToolHandlerAdapter($this->tool(false, static fn (): array => ['done' => true, ToolHandlerAdapter::CHANGED => ['timesheet:7']]), TestUser::make(3), $this->callLog(), null);

        $result = $adapter->execute([], $this->gateway());

        $this->assertSame(['done' => true], $result->structuredContent);
    }

    public function testUnexpectedFailuresAreLoggedAndPassedOn(): void
    {
        $adapter = new ToolHandlerAdapter($this->tool(true, static fn (): array => throw new \RuntimeException('database gone')), TestUser::make(3), $this->callLog(), null);

        try {
            $adapter->execute([], $this->gateway());
            $this->fail('The failure must reach the SDK.');
        } catch (\RuntimeException) {
        }

        $this->assertSame('RuntimeException: database gone', $this->logged[0]['error']);
    }
}

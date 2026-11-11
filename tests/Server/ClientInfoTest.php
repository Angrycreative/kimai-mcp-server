<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Server;

use Doctrine\DBAL\Connection;
use KimaiPlugin\McpServerBundle\Server\ClientInfo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ClientInfoTest extends TestCase
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function request(array $body, array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/api/mcp', 'POST', [], [], [], $server, (string) json_encode($body));
    }

    public function testFromTheInitializeRequest(): void
    {
        $info = new ClientInfo($this->createMock(Connection::class));

        $this->assertSame('claude-code 2.1.0', $info->describe($this->request(['method' => 'initialize', 'params' => ['clientInfo' => ['name' => 'claude-code', 'version' => '2.1.0']]]), 1));
    }

    public function testFromStatelessRequestMetadata(): void
    {
        $info = new ClientInfo($this->createMock(Connection::class));
        $body = ['method' => 'tools/call', 'params' => ['_meta' => ['io.modelcontextprotocol/clientInfo' => ['name' => 'bridge', 'version' => '1']]]];

        $this->assertSame('bridge 1', $info->describe($this->request($body), 1));
    }

    public function testFromTheSessionOnlyForItsOwnUser(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('fetchOne')
            ->with($this->stringContains('WHERE id = ? AND user_id = ?'), ['abc', 4])
            ->willReturn('{"client_info":{"name":"Claude Desktop","version":"1.0"}}');

        $this->assertSame('Claude Desktop 1.0', (new ClientInfo($connection))->describe($this->request(['method' => 'tools/call'], ['Mcp-Session-Id' => 'abc']), 4));
    }

    public function testFallsBackToTheUserAgent(): void
    {
        $info = new ClientInfo($this->createMock(Connection::class));

        $this->assertSame('curl/8', $info->describe($this->request(['method' => 'tools/list'], ['User-Agent' => 'curl/8']), 1));
    }
}

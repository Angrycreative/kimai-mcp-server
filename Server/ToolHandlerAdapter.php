<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Server;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\CallLog\CallLog;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\McpServerBundle\Tool\ToolInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Content\TextContent;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\CallToolResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\ClientGateway;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Runs one of our tools for the SDK and records the call. Built per request, so holding the user here is safe: a
 * server instance never outlives the request it was built for.
 */
final class ToolHandlerAdapter implements ToolHandlerInterface
{
    /** A write tool lists what it changed under this key; it goes to the call log, not to the model. */
    public const CHANGED = '_changed';

    public function __construct(
        private readonly ToolInterface $tool,
        private readonly User $user,
        private readonly CallLog $callLog,
        private readonly ?string $client,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        $started = hrtime(true);

        try {
            $result = $this->tool->call($arguments, $this->user);
        } catch (ToolException $e) {
            $this->log($arguments, $started, $e->getMessage());

            return new CallToolResult([new TextContent($e->getMessage())], true);
        } catch (\Throwable $e) {
            // logged, then left to the SDK, which answers with a generic error rather than our internals
            $this->log($arguments, $started, $e::class . ': ' . $e->getMessage());

            throw $e;
        }

        $changed = [];
        if (\is_array($result[self::CHANGED] ?? null)) {
            $changed = array_values(array_map('strval', $result[self::CHANGED]));
            unset($result[self::CHANGED]);
        }
        $this->log($arguments, $started, null, $changed);

        // Text for clients that only read content, structured content for those that read the object.
        return new CallToolResult(
            [new TextContent(json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))],
            false,
            $result,
        );
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $changed
     */
    private function log(array $arguments, int|float $started, ?string $error, array $changed = []): void
    {
        $this->callLog->record(
            $this->user,
            $this->client,
            $this->tool->getName(),
            $arguments,
            !$this->tool->isReadOnly(),
            $error === null,
            $error,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $changed,
        );
    }
}

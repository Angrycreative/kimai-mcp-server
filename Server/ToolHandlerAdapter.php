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
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\McpServerBundle\Tool\ToolInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Content\TextContent;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\CallToolResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\ClientGateway;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Runs one of our tools for the SDK. Built per request, so holding the user here is safe: a server instance never
 * outlives the request it was built for.
 */
final class ToolHandlerAdapter implements ToolHandlerInterface
{
    public function __construct(
        private readonly ToolInterface $tool,
        private readonly User $user,
    ) {
    }

    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        try {
            $result = $this->tool->call($arguments, $this->user);
        } catch (ToolException $e) {
            return new CallToolResult([new TextContent($e->getMessage())], true);
        }

        // Text for clients that only read content, structured content for those that read the object.
        return new CallToolResult(
            [new TextContent(json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES))],
            false,
            $result,
        );
    }
}

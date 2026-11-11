<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Transport\Http\Middleware;

use KimaiPlugin\McpServerBundle\Vendor\Psr\Http\Message\ResponseInterface;
use KimaiPlugin\McpServerBundle\Vendor\Psr\Http\Message\ServerRequestInterface;
use KimaiPlugin\McpServerBundle\Vendor\Psr\Http\Server\MiddlewareInterface;
use KimaiPlugin\McpServerBundle\Vendor\Psr\Http\Server\RequestHandlerInterface;

/**
 * Hands every request to the next handler unchanged.
 *
 * Pass `[new PassthroughMiddleware()]` as the transport's middleware when the
 * host application already handles CORS and host validation. It replaces
 * {@see \KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Transport\StreamableHttpTransport::defaultMiddleware()}
 * without the warning an empty list logs.
 */
final class PassthroughMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}

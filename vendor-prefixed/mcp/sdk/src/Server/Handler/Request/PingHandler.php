<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Handler\Request;

use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\JsonRpc\Request;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\JsonRpc\Response;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Request\PingRequest;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result\EmptyResult;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Session\SessionInterface;

/**
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class PingHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof PingRequest;
    }

    /**
     * @return Response<EmptyResult>
     */
    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof PingRequest);

        return new Response($request->getId(), new EmptyResult());
    }
}

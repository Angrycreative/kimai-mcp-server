<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Handler;

use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\ClientGateway;

/**
 * Contract for explicit tool handlers paired with an `KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Tool` definition
 * via `KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Builder::add()`.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface ToolHandlerInterface extends ElementHandlerInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed;
}

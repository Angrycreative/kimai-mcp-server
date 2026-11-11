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
 * Contract for explicit resource-template handlers paired with an
 * `KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\ResourceTemplate` definition via `KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Builder::add()`.
 *
 * @author Mateu Aguiló Bosch <mateu.aguilo.bosch@gmail.com>
 */
interface ResourceTemplateHandlerInterface extends ElementHandlerInterface
{
    /**
     * @param array<string, string> $variables
     */
    public function read(string $uri, array $variables, ClientGateway $gateway): mixed;
}

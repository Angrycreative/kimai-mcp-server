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
use Doctrine\DBAL\Connection;
use KimaiPlugin\McpServerBundle\CallLog\CallLog;
use KimaiPlugin\McpServerBundle\Tool\ToolRegistry;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\ServerCapabilities;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Tool;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\ToolAnnotations;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server;
use Psr\Log\LoggerInterface;

/**
 * Builds the MCP server for one request and one user, offering only the tools that user may call. Under PHP-FPM
 * nothing outlives the request anyway, and building per user keeps tools/list honest: a tool the user lacks a
 * permission for is never listed, rather than listed and then refused.
 */
final class ServerFactory
{
    public const NAME = 'kimai';
    public const VERSION = '0.1.0-dev';

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly CallLog $callLog,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string|null $client the client's name and version, for the call log
     */
    public function create(User $user, ?string $client = null): Server
    {
        $builder = Server::builder()
            ->setServerInfo(self::NAME, self::VERSION, 'Time tracking, budgets and planning in Kimai')
            ->setInstructions(
                'Kimai is the time tracking system. Dates are Y-m-d and times are local to the user\'s timezone '
                . '(see whoami). Tools only show what this user may see in Kimai itself.'
            )
            ->setLogger(new LoggerBridge($this->logger))
            // Tools only: no resources, prompts, logging or completions, so clients are not offered what we do not serve.
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                toolsListChanged: false,
                resources: false,
                prompts: false,
                logging: false,
                completions: false,
            ))
            ->setSession(new DoctrineSessionStore($this->connection, (int) $user->getId()), gcProbability: 0)
            // Never hold a PHP-FPM worker open waiting for the client to answer a follow-up question.
            ->withoutInputRequiredShim();

        foreach ($this->tools->available() as $tool) {
            $definition = new Tool(
                name: $tool->getName(),
                title: null,
                inputSchema: $tool->getInputSchema(),
                description: $tool->getDescription(),
                annotations: new ToolAnnotations(
                    readOnlyHint: $tool->isReadOnly(),
                    destructiveHint: !$tool->isReadOnly(),
                    openWorldHint: false,
                ),
            );

            $builder->add($definition, new ToolHandlerAdapter($tool, $user, $this->callLog, $client));
        }

        return $builder->build();
    }
}

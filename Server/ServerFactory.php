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
use KimaiPlugin\McpServerBundle\Tool\ToolInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Tool;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\ToolAnnotations;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Builds the MCP server for one request and one user, offering only the tools that user may call. Under PHP-FPM
 * nothing outlives the request anyway, and building per user keeps tools/list honest: a tool the user lacks a
 * permission for is never listed, rather than listed and then refused.
 */
final class ServerFactory
{
    public const NAME = 'kimai';
    public const VERSION = '0.1.0-dev';

    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        private readonly iterable $tools,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(User $user): Server
    {
        $builder = Server::builder()
            ->setServerInfo(self::NAME, self::VERSION, 'Time tracking, budgets and planning in Kimai')
            ->setInstructions(
                'Kimai is the time tracking system. Dates are Y-m-d and times are local to the user\'s timezone '
                . '(see whoami). Tools only show what this user may see in Kimai itself.'
            )
            ->setLogger(new LoggerBridge($this->logger))
            ->setSession(new DoctrineSessionStore($this->connection, (int) $user->getId()), gcProbability: 0)
            // Never hold a PHP-FPM worker open waiting for the client to answer a follow-up question.
            ->withoutInputRequiredShim();

        foreach ($this->tools as $tool) {
            if (!$this->isAvailable($tool)) {
                continue;
            }

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

            $builder->add($definition, new ToolHandlerAdapter($tool, $user));
        }

        return $builder->build();
    }

    private function isAvailable(ToolInterface $tool): bool
    {
        $permissions = $tool->getRequiredPermissions();
        if (!$tool->isReadOnly()) {
            $permissions[] = 'mcp_write';
        }

        foreach ($permissions as $permission) {
            if (!$this->auth->isGranted($permission)) {
                return false;
            }
        }

        return true;
    }
}

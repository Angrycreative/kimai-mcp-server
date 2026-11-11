<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Command;

use Doctrine\DBAL\Connection;
use KimaiPlugin\McpServerBundle\CallLog\CallLog;
use KimaiPlugin\McpServerBundle\Server\DoctrineSessionStore;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly housekeeping: call log rows past the retention set under System > Settings > MCP, and sessions nobody has
 * used for longer than they live. Safe to run as often as you like.
 */
#[AsCommand(name: 'kimai:mcp:prune', description: 'Remove MCP call log rows past their retention, and expired MCP sessions')]
final class PruneCommand extends Command
{
    public function __construct(
        private readonly CallLog $callLog,
        private readonly McpSettings $settings,
        private readonly Connection $connection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $this->settings->retentionDays();
        $calls = $this->callLog->prune($days);
        $sessions = (int) $this->connection->executeStatement(
            'DELETE FROM ' . DoctrineSessionStore::TABLE . ' WHERE updated_at < ?',
            [time() - DoctrineSessionStore::DEFAULT_TTL]
        );

        (new SymfonyStyle($input, $output))->success(\sprintf(
            'Removed %d call log rows older than %d days and %d expired sessions.',
            $calls,
            $days,
            $sessions
        ));

        return Command::SUCCESS;
    }
}

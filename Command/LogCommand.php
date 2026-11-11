<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Command;

use KimaiPlugin\McpServerBundle\CallLog\CallLog;
use KimaiPlugin\McpServerBundle\Support\Value;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the call log, newest first, until there is a screen for it.
 */
#[AsCommand(name: 'kimai:mcp:log', description: 'Show the latest MCP tool calls: who called what, with which arguments, and how it went')]
final class LogCommand extends Command
{
    public function __construct(private readonly CallLog $callLog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'How many calls to show', '25')
            ->addOption('tool', null, InputOption::VALUE_REQUIRED, 'Only this tool')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Only this user ID')
            ->addOption('writes', null, InputOption::VALUE_NONE, 'Only tools that change data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tool = $input->getOption('tool');
        $user = $input->getOption('user');

        $rows = $this->callLog->latest(
            Value::int($input->getOption('limit'), 25),
            \is_string($tool) ? $tool : null,
            \is_string($user) && ctype_digit($user) ? (int) $user : null,
            (bool) $input->getOption('writes'),
        );

        $table = [];
        foreach ($rows as $row) {
            $table[] = [
                Value::string($row['created_at'] ?? null),
                Value::stringOrNull($row['username'] ?? null) ?? ('#' . Value::string($row['user_id'] ?? null)),
                Value::string($row['client'] ?? null),
                Value::string($row['tool'] ?? null) . (Value::int($row['is_write'] ?? 0) === 1 ? ' (write)' : ''),
                mb_strimwidth(Value::string($row['arguments'] ?? null), 0, 60, '…'),
                Value::int($row['success'] ?? 0) === 1 ? 'ok' : 'error: ' . mb_strimwidth(Value::string($row['error'] ?? null), 0, 40, '…'),
                Value::int($row['duration_ms'] ?? 0) . ' ms',
            ];
        }

        (new SymfonyStyle($input, $output))->table(['When', 'User', 'Client', 'Tool', 'Arguments', 'Result', 'Took'], $table);

        return Command::SUCCESS;
    }
}

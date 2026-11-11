<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\CallLog;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;

/**
 * Who called which tool, with what, and how it went. Writes are always recorded; reads unless switched off in the
 * MCP settings. Kept as plain rows (no entity): nothing in Kimai reads them but kimai:mcp:log and the prune command.
 */
final class CallLog
{
    public const TABLE = 'kimai2_mcp_call_log';

    /** Arguments are stored as JSON; anything longer than this is cut, so one odd call cannot bloat the table. */
    private const MAX_ARGUMENTS = 8000;

    public function __construct(
        private readonly Connection $connection,
        private readonly McpSettings $settings,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $changed entity references a write changed, e.g. "timesheet:123"
     */
    public function record(
        User $user,
        ?string $client,
        string $tool,
        array $arguments,
        bool $isWrite,
        bool $success,
        ?string $error,
        int $durationMs,
        array $changed = [],
    ): void {
        if (!$isWrite && !$this->settings->logReads()) {
            return;
        }

        $json = json_encode($arguments, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            $json = '{}';
        }
        if (mb_strlen($json) > self::MAX_ARGUMENTS) {
            $json = mb_substr($json, 0, self::MAX_ARGUMENTS) . '…';
        }

        $this->connection->insert(self::TABLE, [
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'user_id' => $user->getId(),
            'client' => $client === null ? null : mb_substr($client, 0, 150),
            'tool' => mb_substr($tool, 0, 100),
            'arguments' => $json,
            'is_write' => $isWrite ? 1 : 0,
            'success' => $success ? 1 : 0,
            'error' => $error === null ? null : mb_substr($error, 0, 2000),
            'duration_ms' => max(0, $durationMs),
            'changed' => $changed === [] ? null : implode(',', $changed),
        ]);
    }

    /**
     * Removes rows older than the given number of days and returns how many went.
     */
    public function prune(int $days): int
    {
        $before = (new \DateTimeImmutable('-' . max(1, $days) . ' days'))->format('Y-m-d H:i:s');

        return (int) $this->connection->executeStatement('DELETE FROM ' . self::TABLE . ' WHERE created_at < ?', [$before]);
    }

    /**
     * The newest rows first, for kimai:mcp:log.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit, ?string $tool = null, ?int $userId = null, bool $writesOnly = false): array
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('l.*', 'u.username')
            ->from(self::TABLE, 'l')
            ->leftJoin('l', 'kimai2_users', 'u', 'u.id = l.user_id')
            ->orderBy('l.id', 'DESC')
            ->setMaxResults(max(1, $limit));

        if ($tool !== null) {
            $qb->andWhere('l.tool = :tool')->setParameter('tool', $tool);
        }
        if ($userId !== null) {
            $qb->andWhere('l.user_id = :user')->setParameter('user', $userId);
        }
        if ($writesOnly) {
            $qb->andWhere('l.is_write = 1');
        }

        /* @var list<array<string, mixed>> */
        return $qb->executeQuery()->fetchAllAssociative();
    }
}

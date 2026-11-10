<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Server;

use Doctrine\DBAL\Connection;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Session\SessionStoreInterface;
use KimaiPlugin\McpServerBundle\Vendor\Symfony\Component\Uid\Uuid;

/**
 * MCP sessions in the database, so a session started on one webserver is found by the other: Kimai runs behind a load
 * balancer without sticky sessions, and the SDK's own stores are per process or per host.
 *
 * Every session belongs to the user whose token started it. Another user presenting the same session id is treated as
 * if the session did not exist, so a leaked id is worthless without the token that created it.
 */
final class DoctrineSessionStore implements SessionStoreInterface
{
    public const TABLE = 'kimai2_mcp_sessions';

    public function __construct(
        private readonly Connection $connection,
        private readonly int $userId,
        private readonly int $ttl = 3600,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        return $this->read($id) !== false;
    }

    public function read(Uuid $id): string|false
    {
        $row = $this->connection->fetchAssociative(
            'SELECT data, user_id, updated_at FROM ' . self::TABLE . ' WHERE id = ?',
            [$id->toRfc4122()]
        );

        if ($row === false || (int) $row['user_id'] !== $this->userId) {
            return false;
        }

        if (time() - (int) $row['updated_at'] > $this->ttl) {
            $this->destroy($id);

            return false;
        }

        return (string) $row['data'];
    }

    public function write(Uuid $id, string $data): bool
    {
        $key = $id->toRfc4122();
        $owner = $this->connection->fetchOne('SELECT user_id FROM ' . self::TABLE . ' WHERE id = ?', [$key]);

        if ($owner === false) {
            $this->connection->insert(self::TABLE, [
                'id' => $key,
                'user_id' => $this->userId,
                'data' => $data,
                'updated_at' => time(),
            ]);

            return true;
        }

        // Never let one user overwrite another user's session.
        if ((int) $owner !== $this->userId) {
            return false;
        }

        $this->connection->update(self::TABLE, ['data' => $data, 'updated_at' => time()], ['id' => $key]);

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        $this->connection->delete(self::TABLE, ['id' => $id->toRfc4122(), 'user_id' => $this->userId]);

        return true;
    }

    /**
     * Garbage collection runs from the nightly prune command, never inside a request, so this only reports.
     */
    public function gc(): array
    {
        return [];
    }
}

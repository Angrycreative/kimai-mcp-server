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
use Symfony\Component\HttpFoundation\Request;

/**
 * Which client made a request ("Claude Code 2.1.0"), for the call log. The SDK keeps that to itself, so it is read
 * where the protocol puts it: the initialize request, the per-request metadata of the stateless protocol, or the
 * session the handshake stored it in. Falls back to the User-Agent header.
 */
final class ClientInfo
{
    private const META_KEY = 'io.modelcontextprotocol/clientInfo';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function describe(Request $request, int $userId): ?string
    {
        $body = json_decode($request->getContent(), true);
        $params = \is_array($body) && \is_array($body['params'] ?? null) ? $body['params'] : [];

        $info = $params['clientInfo'] ?? (\is_array($params['_meta'] ?? null) ? ($params['_meta'][self::META_KEY] ?? null) : null);
        if (!\is_array($info)) {
            $info = $this->fromSession((string) $request->headers->get('Mcp-Session-Id'), $userId);
        }

        if (\is_array($info) && \is_string($info['name'] ?? null)) {
            return trim($info['name'] . ' ' . (\is_string($info['version'] ?? null) ? $info['version'] : ''));
        }

        $agent = $request->headers->get('User-Agent');

        return $agent === null || $agent === '' ? null : $agent;
    }

    /**
     * @return array<mixed>|null
     */
    private function fromSession(string $sessionId, int $userId): ?array
    {
        if ($sessionId === '') {
            return null;
        }

        $data = $this->connection->fetchOne(
            'SELECT data FROM ' . DoctrineSessionStore::TABLE . ' WHERE id = ? AND user_id = ?',
            [$sessionId, $userId]
        );
        $decoded = \is_string($data) ? json_decode($data, true) : null;

        return \is_array($decoded) && \is_array($decoded['client_info'] ?? null) ? $decoded['client_info'] : null;
    }
}

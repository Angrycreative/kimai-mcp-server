<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Result;

use KimaiPlugin\McpServerBundle\Vendor\Mcp\Exception\InvalidArgumentException;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Enum\ProtocolVersion;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\Enum\ResultType;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\JsonRpc\ResultInterface;
use KimaiPlugin\McpServerBundle\Vendor\Mcp\Schema\ServerCapabilities;

/**
 * The server's response to a `server/discover` request.
 *
 * The modern era's replacement for `InitializeResult`: it reports every
 * version this server speaks rather than negotiating one, and each request
 * picks from that list.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
class DiscoverResult implements ResultInterface
{
    /**
     * @param list<ProtocolVersion> $supportedVersions every revision this server can answer
     */
    public function __construct(
        public readonly array $supportedVersions,
        public readonly ServerCapabilities $capabilities,
        public readonly ?string $instructions = null,
    ) {
        if ([] === $this->supportedVersions) {
            throw new InvalidArgumentException('A DiscoverResult must advertise at least one supported version.');
        }
    }

    /**
     * `resultType`, caching hints and serverInfo are wire vocabulary, stamped
     * by {@see \KimaiPlugin\McpServerBundle\Vendor\Mcp\Server\Wire\Rev2026Codec} rather than modelled here.
     *
     * @return array{
     *     supportedVersions: list<string>,
     *     capabilities: ServerCapabilities,
     *     instructions?: string,
     * }
     */
    public function jsonSerialize(): array
    {
        $data = [
            'supportedVersions' => array_values(array_map(
                static fn (ProtocolVersion $version): string => $version->value,
                $this->supportedVersions,
            )),
            'capabilities' => $this->capabilities,
        ];

        if (null !== $this->instructions) {
            $data['instructions'] = $this->instructions;
        }

        return $data;
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

/**
 * JSON Schema pieces the tools share, so every tool describes dates, ID lists and limits the same way.
 */
final class Schema
{
    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public static function date(string $description): array
    {
        return ['type' => 'string', 'format' => 'date', 'description' => $description . ' (YYYY-MM-DD, in the user\'s timezone)'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ids(string $description): array
    {
        return ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function id(string $description): array
    {
        return ['type' => 'integer', 'minimum' => 1, 'description' => $description];
    }

    /**
     * @return array<string, mixed>
     */
    public static function limit(int $default = Args::DEFAULT_LIMIT): array
    {
        return [
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => Args::MAX_LIMIT,
            'description' => \sprintf('At most this many results (default %d, at most %d)', $default, Args::MAX_LIMIT),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function visibility(): array
    {
        return [
            'type' => 'string',
            'enum' => ['visible', 'hidden', 'both'],
            'description' => 'Visible (active) ones, hidden (archived) ones, or both. Default: visible',
        ];
    }
}

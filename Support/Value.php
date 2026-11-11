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
 * Narrows values read from the database or a request, which arrive untyped.
 */
final class Value
{
    public static function int(mixed $value, int $default = 0): int
    {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : $default);
    }

    public static function intOrNull(mixed $value): ?int
    {
        return \is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }

    public static function float(mixed $value): float
    {
        return \is_float($value) || \is_int($value) ? (float) $value : (is_numeric($value) ? (float) $value : 0.0);
    }

    public static function string(mixed $value, string $default = ''): string
    {
        return \is_string($value) ? $value : (\is_scalar($value) ? (string) $value : $default);
    }

    public static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : (\is_int($value) || \is_float($value) ? (string) $value : null);
    }
}

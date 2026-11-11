<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\Tool\ToolException;

/**
 * Reads tool arguments. The SDK has already validated them against the tool's schema, so these only convert: dates
 * into the user's timezone, ID lists into integers, limits into their bounds.
 */
final class Args
{
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 500;

    /**
     * @param array<string, mixed> $args
     */
    public static function string(array $args, string $key): ?string
    {
        $value = $args[$key] ?? null;

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function int(array $args, string $key): ?int
    {
        $value = $args[$key] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function bool(array $args, string $key): ?bool
    {
        $value = $args[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $args
     * @return list<int>
     */
    public static function ids(array $args, string $key): array
    {
        $value = $args[$key] ?? [];
        if (!\is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $id) {
            if (\is_int($id) && $id > 0) {
                $ids[$id] = true;
            } elseif (\is_string($id) && ctype_digit($id) && (int) $id > 0) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function limit(array $args, int $default = self::DEFAULT_LIMIT): int
    {
        $limit = self::int($args, 'limit') ?? $default;

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    /**
     * @param array<string, mixed> $args
     */
    public static function page(array $args): int
    {
        return max(1, self::int($args, 'page') ?? 1);
    }

    /**
     * A Y-m-d date as the start (or, with $endOfDay, the last second) of that day in the user's timezone.
     *
     * @param array<string, mixed> $args
     * @throws ToolException
     */
    public static function date(array $args, string $key, User $user, bool $endOfDay = false): ?\DateTimeImmutable
    {
        $value = self::string($args, $key);
        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone($user->getTimezone()));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ToolException(\sprintf('"%s" must be a date written as YYYY-MM-DD, got "%s".', $key, $value));
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }

    /**
     * "from" and "to", both inclusive. With $required, both must be given; otherwise either may be left out.
     *
     * @param array<string, mixed> $args
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     * @throws ToolException
     */
    public static function range(array $args, User $user, bool $required = false): array
    {
        $from = self::date($args, 'from', $user);
        $to = self::date($args, 'to', $user, true);

        if ($required && ($from === null || $to === null)) {
            throw new ToolException('Give both "from" and "to" (YYYY-MM-DD, both inclusive).');
        }
        if ($from !== null && $to !== null && $from > $to) {
            throw new ToolException('"from" is after "to".');
        }

        return [$from, $to];
    }
}

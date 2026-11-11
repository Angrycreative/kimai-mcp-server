<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Entity\EntityWithMetaFields;
use App\Entity\User;

/**
 * How results look to the model: hours as decimals with minutes alongside, dates as Y-m-d, times as local
 * Y-m-dTH:i:s in the user's timezone, names next to IDs.
 */
final class Format
{
    public static function hours(int|float $seconds): float
    {
        return round($seconds / 3600, 2);
    }

    public static function minutes(int|float $seconds): int
    {
        return (int) round($seconds / 60);
    }

    public static function money(float $amount): float
    {
        return round($amount, 2);
    }

    public static function date(?\DateTimeInterface $date): ?string
    {
        return $date?->format('Y-m-d');
    }

    public static function dateTime(?\DateTimeInterface $date, User $user): ?string
    {
        if ($date === null) {
            return null;
        }

        return \DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new \DateTimeZone($user->getTimezone()))
            ->format('Y-m-d\TH:i:s');
    }

    /**
     * The custom fields Kimai shows for an entity, by name, leaving out empty ones.
     *
     * @return array<string, mixed>
     */
    public static function metaFields(EntityWithMetaFields $entity): array
    {
        $fields = [];
        foreach ($entity->getMetaFields() as $meta) {
            if (!$meta->isVisible()) {
                continue;
            }
            $value = $meta->getValue();
            if ($value === null || $value === '') {
                continue;
            }
            $fields[(string) $meta->getName()] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }

        return $fields;
    }

    /**
     * Ratio as a percentage with one decimal, or null when there is nothing to divide by.
     */
    public static function percent(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }
}

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
 * One group of a time summary while it is being added up.
 */
final class SummaryRow
{
    public int $seconds = 0;
    public int $billable = 0;
    public int $exported = 0;
    public int $uninvoiced = 0;
    public float $billableAmount = 0.0;
    public float $uninvoicedAmount = 0.0;
    public int $entries = 0;
    public int $days = 0;
    public ?string $first = null;
    public ?string $last = null;

    /**
     * @param array<string, array<string, int|string|null>|string|null> $group the dimensions as the model sees them
     */
    public function __construct(
        public readonly array $group,
        public readonly ?int $customerId,
        public readonly ?int $projectId,
        public readonly ?int $activityId,
        public readonly ?int $userId,
        public readonly ?string $userName,
        public readonly ?string $activityName,
        public readonly ?string $category,
        public readonly ?string $currency,
    ) {
    }

    public function hours(): float
    {
        return Format::hours($this->seconds);
    }

    public function billableHours(): float
    {
        return Format::hours($this->billable);
    }
}

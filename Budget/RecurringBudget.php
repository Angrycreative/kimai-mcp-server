<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Budget;

/**
 * A budget credited again every interval (by a plugin such as RecurringBudgetBundle), and where its current interval
 * stands. Kimai keeps such a budget as one running total, so the interval is what answers "how much of this month's
 * hours are left?".
 */
final class RecurringBudget
{
    public const TIME = 'time';
    public const MONEY = 'money';

    /**
     * @param self::TIME|self::MONEY $type
     * @param int|float $credit seconds for a time budget, an amount in the customer's currency for a money budget
     * @param string $every the interval, e.g. "1 month"
     * @param bool $credited false when the credits have not started, so nothing has been added to the budget yet
     * @param int|null $expiresAfter unused credit expires after this many intervals; null keeps it for good
     */
    public function __construct(
        public readonly string $type,
        public readonly int|float $credit,
        public readonly string $every,
        public readonly \DateTimeInterface $intervalStart,
        public readonly \DateTimeInterface $intervalEnd,
        public readonly int $usedSeconds,
        public readonly float $usedMoney,
        public readonly bool $credited,
        public readonly ?int $expiresAfter,
    ) {
    }

    /**
     * Share of one credit left in the current interval: 1 untouched, 0 used up, below 0 overrun.
     */
    public function remainingShare(): float
    {
        $credit = (float) $this->credit;
        if ($credit <= 0) {
            return 1.0;
        }
        $used = $this->type === self::TIME ? (float) $this->usedSeconds : $this->usedMoney;

        return ($credit - $used) / $credit;
    }
}

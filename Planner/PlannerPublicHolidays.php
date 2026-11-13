<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Planner;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\Capacity\PublicHolidaysInterface;
use KimaiPlugin\PlannerBundle\Holiday\HolidayProviderInterface;

/**
 * The Weekly Planner's holiday calendar: the organisation's, or the person's own where they chose another, with the
 * observance days the organisation ticked. The same days the planner shows as off.
 */
final class PlannerPublicHolidays implements PublicHolidaysInterface
{
    public function __construct(private readonly HolidayProviderInterface $holidays)
    {
    }

    public function between(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $names = [];
        foreach ($this->holidays->getHolidays($user, $from, $to) as $date => $holiday) {
            $names[$date] = $holiday->getName();
        }

        return $names;
    }
}

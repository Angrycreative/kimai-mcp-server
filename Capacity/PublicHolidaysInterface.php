<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Capacity;

use App\Entity\User;

/**
 * The public holidays a person has off. Kimai's work contract does not know them, so this comes from another plugin:
 * the Weekly Planner's holiday calendar when that is installed (Planner\PlannerPublicHolidays).
 */
interface PublicHolidaysInterface
{
    /**
     * @return array<string, string> the holiday's name by date (Y-m-d), for the days between both (inclusive) that
     *                               the person has off
     */
    public function between(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array;
}

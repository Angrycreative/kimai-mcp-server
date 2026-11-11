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
 * What a person without a work contract is expected to work. Kimai itself expects nothing of them, so without a
 * provider such a person shows zero contract hours and is flagged. Another plugin can implement this to supply an
 * organisation's default working week; the first one registered is used.
 */
interface WorkingCapacityProviderInterface
{
    /**
     * Expected working seconds on one day for a person who has no work contract, or null to leave them without one.
     */
    public function secondsWithoutContract(User $user, \DateTimeInterface $day): ?int;

    /**
     * How the answer is described to the client, e.g. "the organisation's default 40 hour week".
     */
    public function describe(): string;
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\People;

use App\Entity\User;

/**
 * Leaves out the accounts any registered filter says are not people. Without a filter everyone stays.
 */
final class People
{
    /**
     * @param iterable<PeopleFilterInterface> $filters
     */
    public function __construct(private readonly iterable $filters)
    {
    }

    /**
     * @param iterable<User> $users
     * @return list<User>
     */
    public function only(iterable $users): array
    {
        $out = [];
        foreach ($users as $user) {
            if ($this->isPerson($user)) {
                $out[] = $user;
            }
        }

        return $out;
    }

    public function isPerson(User $user): bool
    {
        foreach ($this->filters as $filter) {
            if (!$filter->isPerson($user)) {
                return false;
            }
        }

        return true;
    }
}

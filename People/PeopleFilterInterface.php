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
 * Some accounts hold time with nobody behind them, for example one that collects billable time to invoice. They
 * belong in every customer and project figure, but not in a list of people: they would show up as someone with no
 * work contract, nothing planned and endless free hours. A plugin implementing this says which accounts to leave out
 * of the people tools (find_users, and utilisation when no people are named). Every registered filter applies.
 * Naming an account by its ID still reaches it.
 */
interface PeopleFilterInterface
{
    public function isPerson(User $user): bool;
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Budget;

use App\Entity\Project;

/**
 * Tells the budget tools which projects have a recurring budget and where its current interval stands. Kimai has no
 * recurring budgets of its own; a plugin that adds them (or reads another plugin's) implements this, and the first one
 * registered is used.
 */
interface RecurringBudgetProviderInterface
{
    /**
     * @param list<Project> $projects
     * @return array<int, RecurringBudget> keyed by project ID, only projects that have one
     */
    public function forProjects(array $projects, \DateTimeInterface $today): array;
}

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
 * The recurring budgets of projects, from the provider another plugin registers; none without one.
 */
final class RecurringBudgets
{
    private bool $resolved = false;
    private ?RecurringBudgetProviderInterface $provider = null;

    /**
     * @param iterable<RecurringBudgetProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers)
    {
    }

    /**
     * @param list<Project> $projects
     * @return array<int, RecurringBudget>
     */
    public function forProjects(array $projects, \DateTimeInterface $today): array
    {
        if (!$this->resolved) {
            $this->resolved = true;
            foreach ($this->providers as $provider) {
                $this->provider = $provider;
                break;
            }
        }

        return $this->provider === null || $projects === [] ? [] : $this->provider->forProjects($projects, $today);
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Capacity;

/**
 * Picks the working capacity provider another plugin registers, if any.
 */
final class WorkingCapacity
{
    private bool $resolved = false;
    private ?WorkingCapacityProviderInterface $provider = null;

    /**
     * @param iterable<WorkingCapacityProviderInterface> $providers
     */
    public function __construct(private readonly iterable $providers)
    {
    }

    public function provider(): ?WorkingCapacityProviderInterface
    {
        if (!$this->resolved) {
            $this->resolved = true;
            foreach ($this->providers as $provider) {
                $this->provider = $provider;
                break;
            }
        }

        return $this->provider;
    }
}

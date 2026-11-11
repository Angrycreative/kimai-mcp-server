<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Category;

/**
 * Picks the provider tools use: the first one another plugin registers, else the bundle's default.
 */
final class TimeCategories
{
    private ?TimeCategoryProviderInterface $provider = null;

    /**
     * @param iterable<TimeCategoryProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly DefaultTimeCategoryProvider $default,
    ) {
    }

    public function provider(): TimeCategoryProviderInterface
    {
        if ($this->provider === null) {
            $this->provider = $this->default;
            foreach ($this->providers as $provider) {
                if (!$provider instanceof DefaultTimeCategoryProvider) {
                    $this->provider = $provider;
                    break;
                }
            }
        }

        return $this->provider;
    }
}

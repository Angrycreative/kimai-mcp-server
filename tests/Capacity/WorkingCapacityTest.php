<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Capacity;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\Capacity\WorkingCapacity;
use KimaiPlugin\McpServerBundle\Capacity\WorkingCapacityProviderInterface;
use PHPUnit\Framework\TestCase;

final class WorkingCapacityTest extends TestCase
{
    public function testNoProviderMeansNone(): void
    {
        self::assertNull((new WorkingCapacity([]))->provider());
    }

    public function testFirstProviderWins(): void
    {
        $first = $this->provider('first');
        $capacity = new WorkingCapacity([$first, $this->provider('second')]);

        self::assertSame($first, $capacity->provider());
        self::assertSame($first, $capacity->provider());
    }

    private function provider(string $name): WorkingCapacityProviderInterface
    {
        return new class($name) implements WorkingCapacityProviderInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function secondsWithoutContract(User $user, \DateTimeInterface $day): ?int
            {
                return null;
            }

            public function describe(): string
            {
                return $this->name;
            }
        };
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\People;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\People\People;
use KimaiPlugin\McpServerBundle\People\PeopleFilterInterface;
use PHPUnit\Framework\TestCase;

final class PeopleTest extends TestCase
{
    public function testWithoutFiltersEveryoneStays(): void
    {
        $users = [$this->user('anna'), $this->user('billable')];

        self::assertSame($users, (new People([]))->only($users));
    }

    public function testEveryFilterApplies(): void
    {
        $anna = $this->user('anna');
        $people = new People([$this->without('billable'), $this->without('bot')]);

        self::assertSame([$anna], $people->only([$this->user('billable'), $anna, $this->user('bot')]));
        self::assertFalse($people->isPerson($this->user('bot')));
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->setUserIdentifier($name);

        return $user;
    }

    private function without(string $name): PeopleFilterInterface
    {
        return new class($name) implements PeopleFilterInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function isPerson(User $user): bool
            {
                return $user->getUserIdentifier() !== $this->name;
            }
        };
    }
}

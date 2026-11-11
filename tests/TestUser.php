<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests;

use App\Entity\User;

final class TestUser
{
    public static function make(int $id, string $timezone = 'Europe/Stockholm'): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setUserIdentifier('user' . $id);
        $user->setTimezone($timezone);

        return $user;
    }
}

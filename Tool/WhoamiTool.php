<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Constants;
use App\Entity\User;

/**
 * Who the connection acts as. The smoke test for a new connection, and how the model learns the user's timezone
 * before it reasons about dates.
 */
final class WhoamiTool implements ToolInterface
{
    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    public function getName(): string
    {
        return 'whoami';
    }

    public function getDescription(): string
    {
        return 'Returns the Kimai user this connection acts as: name, username, email, timezone, language, roles and the '
            . 'tools this user may call. '
            . 'Call it first to check the connection works, and to learn the timezone that every date and time in '
            . 'other tools is interpreted in.';
    }

    public function getInputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getRequiredPermissions(): array
    {
        return [];
    }

    public function call(array $arguments, User $user): array
    {
        return [
            'id' => $user->getId(),
            'name' => $user->getDisplayName(),
            'username' => $user->getUserIdentifier(),
            'email' => $user->getEmail(),
            'timezone' => $user->getTimezone(),
            'language' => $user->getLanguage(),
            'roles' => array_values($user->getRoles()),
            'kimai_version' => Constants::VERSION,
            'tools' => array_map(static fn (ToolInterface $tool): string => $tool->getName(), $this->tools->available()),
        ];
    }
}

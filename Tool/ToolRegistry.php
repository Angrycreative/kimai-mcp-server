<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Every tool any plugin registered, and the ones the current user may call: a tool needing a permission the user
 * lacks is never offered, and writes also need mcp_write.
 */
final class ToolRegistry
{
    /**
     * @param iterable<ToolInterface> $tools
     */
    public function __construct(
        private readonly iterable $tools,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    /**
     * @return list<ToolInterface>
     */
    public function available(): array
    {
        $available = [];
        foreach ($this->tools as $tool) {
            if ($this->isAvailable($tool)) {
                $available[] = $tool;
            }
        }

        return $available;
    }

    public function isAvailable(ToolInterface $tool): bool
    {
        $permissions = $tool->getRequiredPermissions();
        if (!$tool->isReadOnly()) {
            $permissions[] = 'mcp_write';
        }

        foreach ($permissions as $permission) {
            if (!$this->auth->isGranted($permission)) {
                return false;
            }
        }

        return true;
    }
}

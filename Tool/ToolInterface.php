<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\User;

/**
 * One MCP tool. Any service implementing this, in this bundle or in another plugin, is offered to MCP clients.
 *
 * Deliberately free of MCP SDK types: other plugins depend on this interface only, so an SDK upgrade before its 1.0
 * never reaches them.
 */
interface ToolInterface
{
    /**
     * Unique, snake_case, e.g. "time_summary".
     */
    public function getName(): string;

    /**
     * Written for the model: what question the tool answers, and when to use it rather than another tool.
     */
    public function getDescription(): string;

    /**
     * JSON Schema for the arguments, always of type "object". The SDK validates every call against it before call()
     * runs.
     *
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * True when the tool only reads. Anything else is treated as a write: it needs mcp_write and the user's explicit
     * confirmation.
     */
    public function isReadOnly(): bool;

    /**
     * Kimai permissions the user must hold, on top of mcp_access (and mcp_write for writes). A tool the user lacks a
     * permission for is not listed to them at all. "a|b" means either will do, for permissions Kimai splits into
     * global and team variants (budget_project|budget_team_project); check the entity itself in call().
     *
     * @return list<string>
     */
    public function getRequiredPermissions(): array;

    /**
     * Runs the tool as the given user and returns a JSON-serialisable result. Throw ToolException for an error the
     * model should read and react to (an unknown project, a missing permission on one entity).
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws ToolException
     */
    public function call(array $arguments, User $user): array;
}

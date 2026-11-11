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
 * A tool that changes data. The server, not the client, enforces the confirmation rule: called without
 * user_confirmed: true the tool changes nothing and returns a preview of exactly what would happen. Everything is
 * checked before the preview (permissions, Kimai's own validation), so a preview that reads fine is a change that
 * will go through.
 */
abstract class AbstractWriteTool implements ToolInterface
{
    public const CONFIRM = 'user_confirmed';

    final public function isReadOnly(): bool
    {
        return false;
    }

    final public function getInputSchema(): array
    {
        $schema = $this->getArgumentSchema();
        /** @var array<string, mixed> $properties */
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $properties[self::CONFIRM] = [
            'type' => 'boolean',
            'description' => 'Set to true only when the person asked for this exact change, or said yes when it was '
                . 'described to them. Otherwise leave it false: nothing changes and you get a preview to describe '
                . 'to them first.',
        ];
        $schema['properties'] = $properties;
        /** @var list<string> $required */
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $schema['required'] = array_values(array_unique([...$required, self::CONFIRM]));

        return $schema;
    }

    final public function call(array $arguments, User $user): array
    {
        $plan = $this->plan($arguments, $user);

        if (($arguments[self::CONFIRM] ?? false) !== true) {
            return [
                'preview' => true,
                'would' => $plan->summary,
                'next' => 'Nothing has changed. Describe this to the person and ask them to confirm. Call again with '
                    . 'the same arguments and user_confirmed: true only if they say yes.',
            ];
        }

        return ['done' => true] + $plan->apply();
    }

    /**
     * The arguments this tool takes, without user_confirmed, which is added for every write tool.
     *
     * @return array<string, mixed>
     */
    abstract protected function getArgumentSchema(): array;

    /**
     * Checks everything and works out the change, without making it.
     *
     * @param array<string, mixed> $arguments
     * @throws ToolException
     */
    abstract protected function plan(array $arguments, User $user): WritePlan;
}

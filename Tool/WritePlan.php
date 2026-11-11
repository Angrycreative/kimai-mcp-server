<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

/**
 * What a write tool is about to do: a summary the person can read, and the change itself, run only once they confirm.
 */
final class WritePlan
{
    /**
     * @param array<string, mixed> $summary exactly what would happen, for the preview
     * @param \Closure(): array<string, mixed> $apply makes the change; its result lists what changed under
     *                                                ToolHandlerAdapter::CHANGED
     */
    public function __construct(
        public readonly array $summary,
        private readonly \Closure $apply,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(): array
    {
        return ($this->apply)();
    }
}

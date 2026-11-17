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
     * @param (\Closure(): void)|null $discard undoes what working out the change did to records in memory, when it
     *                                         is only previewed, so nothing saved later in the same request takes it
     *                                         along
     */
    public function __construct(
        public readonly array $summary,
        private readonly \Closure $apply,
        private readonly ?\Closure $discard = null,
    ) {
    }

    public function discard(): void
    {
        if ($this->discard !== null) {
            ($this->discard)();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(): array
    {
        return ($this->apply)();
    }
}

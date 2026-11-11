<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Repository\Query\VisibilityInterface;
use KimaiPlugin\McpServerBundle\Support\Args;

/**
 * Shared bits of the read-only tools.
 */
abstract class AbstractReadTool implements ToolInterface
{
    public function isReadOnly(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $args
     */
    protected function visibility(array $args): int
    {
        return match (Args::string($args, 'visibility')) {
            'hidden' => VisibilityInterface::SHOW_HIDDEN,
            'both' => VisibilityInterface::SHOW_BOTH,
            default => VisibilityInterface::SHOW_VISIBLE,
        };
    }

    /**
     * The list part of a result: the items, how many matched in all, and whether the list was cut off.
     *
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    protected function listResult(string $key, array $items, int $total, int $limit): array
    {
        $result = [$key => $items, 'count' => \count($items), 'total' => $total];
        if ($total > \count($items)) {
            $result['truncated'] = \sprintf('Only the first %d of %d are shown. Narrow the search, or raise "limit" (up to %d).', \count($items), $total, Args::MAX_LIMIT);
        }

        return $result;
    }
}

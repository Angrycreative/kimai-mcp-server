<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Settings;

use App\Configuration\SystemConfiguration;

/**
 * The MCP section under System > Settings. Values live in Kimai's configuration table, so every webserver sees the
 * same ones without a deploy.
 */
final class McpSettings
{
    public const LOG_READS = 'mcp.log_reads';
    public const RETENTION_DAYS = 'mcp.log_retention_days';
    public const ABSENCE_PROJECTS = 'mcp.absence_projects';

    public const DEFAULT_LOG_READS = true;
    public const DEFAULT_RETENTION_DAYS = 90;

    public function __construct(private readonly SystemConfiguration $configuration)
    {
    }

    /**
     * Whether read tools are recorded in the call log. Writes always are.
     */
    public function logReads(): bool
    {
        $value = $this->configuration->find(self::LOG_READS);

        return $value === null ? self::DEFAULT_LOG_READS : (bool) $value;
    }

    /**
     * How many days kimai:mcp:prune keeps call log rows. Never less than one.
     */
    public function retentionDays(): int
    {
        $value = $this->configuration->find(self::RETENTION_DAYS);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return max(1, (int) $value);
    }

    /**
     * The projects the default category provider counts as absence. Junk and duplicates are dropped.
     *
     * @return list<int>
     */
    public function absenceProjectIds(): array
    {
        return self::parseIds($this->configuration->find(self::ABSENCE_PROJECTS));
    }

    /**
     * @return list<int>
     */
    public static function parseIds(mixed $raw): array
    {
        $ids = [];
        if (\is_int($raw) && $raw > 0) {
            $ids[$raw] = true;
        } elseif (\is_string($raw)) {
            foreach (explode(',', $raw) as $part) {
                $part = trim($part);
                if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                    $ids[(int) $part] = true;
                }
            }
        }

        return array_keys($ids);
    }
}

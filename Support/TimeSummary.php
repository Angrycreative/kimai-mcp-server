<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Repository\Query\TimesheetQuery;
use KimaiPlugin\McpServerBundle\Category\TimeCategories;

/**
 * Adds up time in the database, grouped the way a tool asks, so a year of a whole customer's time comes back as a
 * handful of rows. Week, month and category are worked out from the daily (or per project and activity) rows,
 * because the database has no week function Kimai registers, and categories come from a provider, not a column.
 */
final class TimeSummary
{
    public const DIMENSIONS = ['customer', 'project', 'activity', 'user', 'date', 'week', 'month', 'category'];

    public function __construct(
        private readonly TimesheetScope $scope,
        private readonly TimeCategories $categories,
    ) {
    }

    /**
     * The group_by argument, shared by the tools that take one.
     *
     * @return array<string, mixed>
     */
    public static function groupBySchema(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string', 'enum' => self::DIMENSIONS],
            'minItems' => 1,
            'uniqueItems' => true,
            'description' => 'What to group by, in order: customer, project, activity, user, date, week (ISO, Monday '
                . 'first), month, or category (for example absence against other time; see whoami for the categories)',
        ];
    }

    /**
     * The summary as tools return it: one row per group with its figures, largest first (or in time order when
     * grouped by date, week or month), and totals over every row.
     *
     * @param list<string> $dims
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public function summarise(TimesheetQuery $query, array $dims, bool $withAmounts): array
    {
        $dims = $this->dimensions($dims);
        $withAmounts = $withAmounts && (\in_array('customer', $dims, true) || \in_array('project', $dims, true));
        // folding categories merges rows of different projects on the same day, so distinct days would be counted twice
        $withDays = !\in_array('category', $dims, true);

        $rows = $this->rows($query, $dims, $withAmounts);

        $out = [];
        $totals = ['seconds' => 0, 'billable' => 0, 'exported' => 0, 'uninvoiced' => 0, 'entries' => 0];
        foreach ($rows as $row) {
            $totals['seconds'] += $row->seconds;
            $totals['billable'] += $row->billable;
            $totals['exported'] += $row->exported;
            $totals['uninvoiced'] += $row->uninvoiced;
            $totals['entries'] += $row->entries;
            $out[] = ['row' => $row, 'data' => $row->group + self::figures($row, $withAmounts, $withDays)];
        }

        $timeDims = array_values(array_intersect(['date', 'week', 'month'], $dims));
        usort($out, static function (array $a, array $b) use ($timeDims): int {
            foreach ($timeDims as $dim) {
                $cmp = self::sortKey($a['row']->group[$dim] ?? null) <=> self::sortKey($b['row']->group[$dim] ?? null);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return $b['row']->seconds <=> $a['row']->seconds;
        });

        return [
            'rows' => array_map(static fn (array $entry): array => $entry['data'], $out),
            'totals' => [
                'hours' => Format::hours($totals['seconds']),
                'billable_hours' => Format::hours($totals['billable']),
                'non_billable_hours' => Format::hours($totals['seconds'] - $totals['billable']),
                'exported_hours' => Format::hours($totals['exported']),
                'uninvoiced_billable_hours' => Format::hours($totals['uninvoiced']),
                'entries' => $totals['entries'],
            ],
        ];
    }

    /**
     * The groups themselves, for tools that work on the figures rather than return them.
     *
     * @param list<string> $dims
     * @return list<SummaryRow>
     */
    public function rows(TimesheetQuery $query, array $dims, bool $withAmounts = false): array
    {
        $dims = $this->dimensions($dims);
        $withCategory = \in_array('category', $dims, true);

        $qb = $this->scope->aggregate($query);
        $qb->leftJoin('t.project', 'mp')
            ->leftJoin('mp.customer', 'mc')
            ->leftJoin('t.activity', 'ma')
            ->leftJoin('t.user', 'mu');

        $select = [];
        if (\in_array('customer', $dims, true) || $withCategory || $withAmounts) {
            array_push($select, 'mc.id AS customer_id', 'mc.name AS customer_name', 'mc.currency AS currency');
            $qb->addGroupBy('mc.id');
        }
        if (\in_array('project', $dims, true) || $withCategory) {
            array_push($select, 'mp.id AS project_id', 'mp.name AS project_name');
            $qb->addGroupBy('mp.id');
        }
        if (\in_array('activity', $dims, true) || $withCategory) {
            array_push($select, 'ma.id AS activity_id', 'ma.name AS activity_name');
            $qb->addGroupBy('ma.id');
        }
        if (\in_array('user', $dims, true)) {
            array_push($select, 'mu.id AS user_id', 'mu.username AS user_username', 'mu.alias AS user_alias');
            $qb->addGroupBy('mu.id');
        }
        if (array_intersect(['date', 'week', 'month'], $dims) !== []) {
            $select[] = 't.date AS day';
            $qb->addGroupBy('t.date');
        }

        array_push(
            $select,
            'COALESCE(SUM(t.duration), 0) AS seconds',
            'COALESCE(SUM(CASE WHEN t.billable = true THEN t.duration ELSE 0 END), 0) AS billable',
            'COALESCE(SUM(CASE WHEN t.exported = true THEN t.duration ELSE 0 END), 0) AS exported',
            'COALESCE(SUM(CASE WHEN t.billable = true AND t.exported = false THEN t.duration ELSE 0 END), 0) AS uninvoiced',
            'COALESCE(SUM(CASE WHEN t.billable = true THEN t.rate ELSE 0 END), 0) AS billable_amount',
            'COALESCE(SUM(CASE WHEN t.billable = true AND t.exported = false THEN t.rate ELSE 0 END), 0) AS uninvoiced_amount',
            'COUNT(t.id) AS entries',
            'COUNT(DISTINCT t.date) AS days',
            'MIN(t.date) AS first_day',
            'MAX(t.date) AS last_day',
        );
        $qb->select($select);

        $provider = $this->categories->provider();
        $labels = $provider->getCategories();

        /** @var array<string, SummaryRow> $rows */
        $rows = [];
        /** @var list<array<string, mixed>> $result */
        $result = $qb->getQuery()->getArrayResult();
        foreach ($result as $db) {
            $customerId = Value::intOrNull($db['customer_id'] ?? null);
            $projectId = Value::intOrNull($db['project_id'] ?? null);
            $activityId = Value::intOrNull($db['activity_id'] ?? null);
            $userId = Value::intOrNull($db['user_id'] ?? null);
            $userName = Value::stringOrNull($db['user_alias'] ?? null);
            if ($userName === null || $userName === '') {
                $userName = Value::stringOrNull($db['user_username'] ?? null);
            }
            $activityName = Value::stringOrNull($db['activity_name'] ?? null);
            $currency = $withAmounts ? Value::stringOrNull($db['currency'] ?? null) : null;
            $category = $withCategory ? $provider->categoryFor($projectId, $activityId, $customerId) : null;
            $day = self::day($db['day'] ?? null);

            $group = [];
            foreach ($dims as $dim) {
                $group[$dim] = match ($dim) {
                    'customer' => ['id' => $customerId, 'name' => Value::stringOrNull($db['customer_name'] ?? null)],
                    'project' => ['id' => $projectId, 'name' => Value::stringOrNull($db['project_name'] ?? null)],
                    'activity' => ['id' => $activityId, 'name' => $activityName],
                    'user' => ['id' => $userId, 'name' => $userName],
                    'date' => $day,
                    'week' => $day === null ? null : self::week($day),
                    'month' => $day === null ? null : substr($day, 0, 7),
                    'category' => ['key' => (string) $category, 'name' => $labels[(string) $category] ?? $category],
                    default => null,
                };
            }

            $key = (string) json_encode([array_map(static fn (mixed $value): mixed => \is_array($value) ? ($value['id'] ?? $value['key'] ?? $value['start'] ?? null) : $value, $group), $currency]);
            $row = $rows[$key] ?? new SummaryRow(
                $group,
                \in_array('customer', $dims, true) ? $customerId : null,
                \in_array('project', $dims, true) ? $projectId : null,
                \in_array('activity', $dims, true) ? $activityId : null,
                $userId,
                $userName,
                \in_array('activity', $dims, true) ? $activityName : null,
                $category,
                $currency,
            );

            $row->seconds += Value::int($db['seconds'] ?? 0);
            $row->billable += Value::int($db['billable'] ?? 0);
            $row->exported += Value::int($db['exported'] ?? 0);
            $row->uninvoiced += Value::int($db['uninvoiced'] ?? 0);
            $row->billableAmount += Value::float($db['billable_amount'] ?? 0);
            $row->uninvoicedAmount += Value::float($db['uninvoiced_amount'] ?? 0);
            $row->entries += Value::int($db['entries'] ?? 0);
            $row->days += Value::int($db['days'] ?? 0);
            $row->first = self::earliest($row->first, self::day($db['first_day'] ?? null));
            $row->last = self::latest($row->last, self::day($db['last_day'] ?? null));
            $rows[$key] = $row;
        }

        return array_values($rows);
    }

    /**
     * @param list<string> $dims
     * @return list<string>
     */
    private function dimensions(array $dims): array
    {
        return array_values(array_intersect(self::DIMENSIONS, $dims));
    }

    /**
     * @return array<string, mixed>
     */
    private static function figures(SummaryRow $row, bool $withAmounts, bool $withDays): array
    {
        $figures = [
            'hours' => $row->hours(),
            'minutes' => Format::minutes($row->seconds),
            'billable_hours' => $row->billableHours(),
            'non_billable_hours' => Format::hours($row->seconds - $row->billable),
            'exported_hours' => Format::hours($row->exported),
            'uninvoiced_billable_hours' => Format::hours($row->uninvoiced),
            'entries' => $row->entries,
        ];
        if ($withDays) {
            $figures['days'] = $row->days;
        }
        $figures['first_date'] = $row->first;
        $figures['last_date'] = $row->last;
        if ($withAmounts) {
            $figures['currency'] = $row->currency;
            $figures['billable_amount'] = Format::money($row->billableAmount);
            $figures['uninvoiced_amount'] = Format::money($row->uninvoicedAmount);
        }

        return $figures;
    }

    private static function sortKey(mixed $value): string
    {
        if (\is_array($value)) {
            return Value::string($value['start'] ?? '');
        }

        return Value::string($value);
    }

    /**
     * @return array{label: string, start: string}
     */
    private static function week(string $day): array
    {
        $date = new \DateTimeImmutable($day);

        return ['label' => $date->format('o-\WW'), 'start' => $date->modify('monday this week')->format('Y-m-d')];
    }

    private static function earliest(?string $current, ?string $candidate): ?string
    {
        return $current === null || ($candidate !== null && $candidate < $current) ? $candidate : $current;
    }

    private static function latest(?string $current, ?string $candidate): ?string
    {
        return $current === null || ($candidate !== null && $candidate > $current) ? $candidate : $current;
    }

    private static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return \is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}

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
     * @param list<string> $dims
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function summarise(TimesheetQuery $query, array $dims, bool $withAmounts): array
    {
        $dims = array_values(array_intersect(self::DIMENSIONS, $dims));
        // money only makes sense per currency, which customers carry
        $withAmounts = $withAmounts && (\in_array('customer', $dims, true) || \in_array('project', $dims, true));

        $qb = $this->scope->aggregate($query);
        $qb->leftJoin('t.project', 'mp')
            ->leftJoin('mp.customer', 'mc')
            ->leftJoin('t.activity', 'ma')
            ->leftJoin('t.user', 'mu');

        $groups = [];
        $select = [];
        if (\in_array('customer', $dims, true) || \in_array('category', $dims, true) || $withAmounts) {
            $select[] = 'mc.id AS customer_id';
            $select[] = 'mc.name AS customer_name';
            $select[] = 'mc.currency AS currency';
            $groups[] = 'mc.id';
        }
        if (\in_array('project', $dims, true) || \in_array('category', $dims, true)) {
            $select[] = 'mp.id AS project_id';
            $select[] = 'mp.name AS project_name';
            $groups[] = 'mp.id';
        }
        if (\in_array('activity', $dims, true) || \in_array('category', $dims, true)) {
            $select[] = 'ma.id AS activity_id';
            $select[] = 'ma.name AS activity_name';
            $groups[] = 'ma.id';
        }
        if (\in_array('user', $dims, true)) {
            $select[] = 'mu.id AS user_id';
            $select[] = 'mu.username AS user_username';
            $select[] = 'mu.alias AS user_alias';
            $groups[] = 'mu.id';
        }
        if (array_intersect(['date', 'week', 'month'], $dims) !== []) {
            $select[] = 't.date AS day';
            $groups[] = 't.date';
        }

        $select[] = 'COALESCE(SUM(t.duration), 0) AS seconds';
        $select[] = 'COALESCE(SUM(CASE WHEN t.billable = true THEN t.duration ELSE 0 END), 0) AS billable';
        $select[] = 'COALESCE(SUM(CASE WHEN t.exported = true THEN t.duration ELSE 0 END), 0) AS exported';
        $select[] = 'COALESCE(SUM(CASE WHEN t.billable = true AND t.exported = false THEN t.duration ELSE 0 END), 0) AS uninvoiced';
        $select[] = 'COALESCE(SUM(CASE WHEN t.billable = true THEN t.rate ELSE 0 END), 0) AS billable_amount';
        $select[] = 'COALESCE(SUM(CASE WHEN t.billable = true AND t.exported = false THEN t.rate ELSE 0 END), 0) AS uninvoiced_amount';
        $select[] = 'COUNT(t.id) AS entries';
        $select[] = 'COUNT(DISTINCT t.date) AS days';
        $select[] = 'MIN(t.date) AS first_day';
        $select[] = 'MAX(t.date) AS last_day';

        $qb->select($select);
        foreach ($groups as $group) {
            $qb->addGroupBy($group);
        }

        // folding categories merges rows from different days of the same group, so distinct days would be summed twice
        $withDays = !\in_array('category', $dims, true);
        $provider = $this->categories->provider();
        $labels = $provider->getCategories();

        $rows = [];
        foreach ($qb->getQuery()->getArrayResult() as $db) {
            $key = [];
            $group = [];
            foreach ($dims as $dim) {
                $value = $this->dimension($dim, $db, $provider->categoryFor(
                    isset($db['project_id']) ? (int) $db['project_id'] : null,
                    isset($db['activity_id']) ? (int) $db['activity_id'] : null,
                    isset($db['customer_id']) ? (int) $db['customer_id'] : null,
                ), $labels);
                $group[$dim] = $value;
                $key[] = \is_array($value) ? ($value['id'] ?? $value['key'] ?? $value['start'] ?? '') : $value;
            }
            if ($withAmounts) {
                $key[] = $db['currency'] ?? '';
            }
            $k = json_encode($key);

            $rows[$k] ??= ['group' => $group, 'seconds' => 0, 'billable' => 0, 'exported' => 0, 'uninvoiced' => 0, 'billable_amount' => 0.0, 'uninvoiced_amount' => 0.0, 'entries' => 0, 'days' => 0, 'first' => null, 'last' => null, 'currency' => $db['currency'] ?? null];
            $row = &$rows[$k];
            foreach (['seconds', 'billable', 'exported', 'uninvoiced', 'entries', 'days'] as $field) {
                $row[$field] += (int) $db[$field];
            }
            $row['billable_amount'] += (float) $db['billable_amount'];
            $row['uninvoiced_amount'] += (float) $db['uninvoiced_amount'];
            $first = self::day($db['first_day']);
            $last = self::day($db['last_day']);
            $row['first'] = $row['first'] === null || ($first !== null && $first < $row['first']) ? $first : $row['first'];
            $row['last'] = $row['last'] === null || ($last !== null && $last > $row['last']) ? $last : $row['last'];
            unset($row);
        }

        $out = [];
        $totals = ['seconds' => 0, 'billable' => 0, 'exported' => 0, 'uninvoiced' => 0, 'entries' => 0];
        foreach ($rows as $row) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] += $row[$field];
            }
            $out[] = $row['group'] + self::figures($row, $withAmounts, $withDays);
        }

        $timeDims = array_values(array_intersect(['date', 'week', 'month'], $dims));
        usort($out, static function (array $a, array $b) use ($timeDims): int {
            foreach ($timeDims as $dim) {
                $cmp = (\is_array($a[$dim]) ? $a[$dim]['start'] : $a[$dim]) <=> (\is_array($b[$dim]) ? $b[$dim]['start'] : $b[$dim]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return $b['hours'] <=> $a['hours'];
        });

        return [
            'rows' => $out,
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
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function figures(array $row, bool $withAmounts, bool $withDays): array
    {
        $figures = [
            'hours' => Format::hours($row['seconds']),
            'minutes' => Format::minutes($row['seconds']),
            'billable_hours' => Format::hours($row['billable']),
            'non_billable_hours' => Format::hours($row['seconds'] - $row['billable']),
            'exported_hours' => Format::hours($row['exported']),
            'uninvoiced_billable_hours' => Format::hours($row['uninvoiced']),
            'entries' => $row['entries'],
        ];
        if ($withDays) {
            $figures['days'] = $row['days'];
        }
        $figures['first_date'] = $row['first'];
        $figures['last_date'] = $row['last'];
        if ($withAmounts) {
            $figures['currency'] = $row['currency'];
            $figures['billable_amount'] = Format::money($row['billable_amount']);
            $figures['uninvoiced_amount'] = Format::money($row['uninvoiced_amount']);
        }

        return $figures;
    }

    /**
     * @param array<string, mixed> $db
     * @param array<string, string> $labels
     * @return array<string, mixed>|string|null
     */
    private function dimension(string $dim, array $db, string $category, array $labels): array|string|null
    {
        $day = self::day($db['day'] ?? null);

        return match ($dim) {
            'customer' => ['id' => $db['customer_id'] ?? null, 'name' => $db['customer_name'] ?? null],
            'project' => ['id' => $db['project_id'] ?? null, 'name' => $db['project_name'] ?? null],
            'activity' => ['id' => $db['activity_id'] ?? null, 'name' => $db['activity_name'] ?? null],
            'user' => ['id' => $db['user_id'] ?? null, 'name' => ($db['user_alias'] ?? '') !== '' ? $db['user_alias'] : ($db['user_username'] ?? null)],
            'date' => $day,
            'week' => $day === null ? null : self::week($day),
            'month' => $day === null ? null : substr($day, 0, 7),
            'category' => ['key' => $category, 'name' => $labels[$category] ?? $category],
            default => null,
        };
    }

    /**
     * @return array{label: string, start: string}
     */
    private static function week(string $day): array
    {
        $date = new \DateTimeImmutable($day);
        $monday = $date->modify('monday this week');

        return ['label' => $date->format('o-\WW'), 'start' => $monday->format('Y-m-d')];
    }

    private static function day(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return \is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}

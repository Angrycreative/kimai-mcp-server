<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Support;

use App\Model\BudgetStatisticModel;
use KimaiPlugin\McpServerBundle\Budget\RecurringBudget;

/**
 * A budget as the model reads it, from Kimai's own budget statistic: the same figures as a project's or customer's
 * details page. Kimai counts only billable time and money against a budget, and keeps the two apart: the time
 * budget needs the "time" permission on the entity, the money budget the "budget" permission.
 */
final class Budget
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(BudgetStatisticModel $model, ?string $currency, bool $showTime, bool $showMoney, ?RecurringBudget $recurring = null): array
    {
        $out = [
            'type' => $model->isMonthlyBudget() ? 'monthly' : 'total',
        ];

        if ($showTime && $model->hasTimeBudget()) {
            $used = $model->getTimeBudgetSpent();
            $remaining = $model->getTimeBudget() - $used;
            $out['time'] = [
                'budget_hours' => Format::hours($model->getTimeBudget()),
                'used_hours' => Format::hours($used),
                'remaining_hours' => Format::hours(max(0, $remaining)),
                'over_budget_hours' => Format::hours(max(0, -$remaining)),
                'used_percent' => Format::percent($used, $model->getTimeBudget()),
            ];
        }

        if ($showMoney && $model->hasBudget()) {
            $used = $model->getBudgetSpent();
            $remaining = $model->getBudget() - $used;
            $out['money'] = [
                'currency' => $currency,
                'budget' => Format::money($model->getBudget()),
                'billable_revenue' => Format::money($used),
                'remaining' => Format::money(max(0.0, $remaining)),
                'over_budget' => Format::money(max(0.0, -$remaining)),
                'used_percent' => Format::percent($used, $model->getBudget()),
            ];
        }

        if ($showTime) {
            $out['logged_hours'] = Format::hours($model->getDuration());
        }
        if ($model->isMonthlyBudget()) {
            $allTime = [];
            if ($showTime) {
                $allTime['billable_hours'] = Format::hours($model->getDurationBillableTotal());
            }
            if ($showMoney) {
                $allTime['billable_revenue'] = Format::money($model->getRateBillableTotal());
            }
            if ($allTime !== []) {
                $out['all_time'] = $allTime;
            }
        }

        if ($recurring !== null && self::showsRecurring($recurring, $showTime, $showMoney)) {
            $out['recurring'] = self::recurring($recurring, $currency);
            $out['note'] = 'A recurring budget: the budget above is every credit so far, set against all billable '
                . 'time logged on the project. "recurring" shows the current interval, which is what answers how '
                . 'much of this period\'s budget is left.';
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function recurring(RecurringBudget $recurring, ?string $currency): array
    {
        $out = [
            'every' => $recurring->every,
            'current_interval' => ['from' => Format::date($recurring->intervalStart), 'to' => Format::date($recurring->intervalEnd)],
        ];

        if ($recurring->type === RecurringBudget::TIME) {
            $credit = (int) $recurring->credit;
            $remaining = $credit - $recurring->usedSeconds;
            $out += [
                'credit_hours' => Format::hours($credit),
                'used_hours' => Format::hours($recurring->usedSeconds),
                'remaining_hours' => Format::hours(max(0, $remaining)),
                'over_credit_hours' => Format::hours(max(0, -$remaining)),
                'used_percent' => Format::percent($recurring->usedSeconds, $credit),
            ];
        } else {
            $credit = (float) $recurring->credit;
            $remaining = $credit - $recurring->usedMoney;
            $out += [
                'currency' => $currency,
                'credit' => Format::money($credit),
                'billable_revenue' => Format::money($recurring->usedMoney),
                'remaining' => Format::money(max(0.0, $remaining)),
                'over_credit' => Format::money(max(0.0, -$remaining)),
                'used_percent' => Format::percent($recurring->usedMoney, $credit),
            ];
        }

        $out['unused_credit'] = $recurring->expiresAfter === null
            ? 'kept until used'
            : \sprintf('expires after %d interval%s', $recurring->expiresAfter, $recurring->expiresAfter === 1 ? '' : 's');

        if (!$recurring->credited) {
            $out['not_started'] = 'Credits have not started: no first credit date is set, so nothing has been added '
                . 'to the budget yet. The interval shown is the calendar one.';
        }

        return $out;
    }

    private static function showsRecurring(RecurringBudget $recurring, bool $showTime, bool $showMoney): bool
    {
        return $recurring->type === RecurringBudget::TIME ? $showTime : $showMoney;
    }

    /**
     * The smallest share left of the budgets shown, for sorting: 0 is used up, 1 untouched, below 0 overrun.
     */
    public static function remainingShare(BudgetStatisticModel $model, bool $showTime = true, bool $showMoney = true, ?RecurringBudget $recurring = null): float
    {
        // a recurring budget's running total says little; its current interval is what runs out
        if ($recurring !== null && self::showsRecurring($recurring, $showTime, $showMoney)) {
            return $recurring->remainingShare();
        }

        $shares = [];
        if ($showTime && $model->hasTimeBudget() && $model->getTimeBudget() > 0) {
            $shares[] = ($model->getTimeBudget() - $model->getTimeBudgetSpent()) / $model->getTimeBudget();
        }
        if ($showMoney && $model->hasBudget() && $model->getBudget() > 0) {
            $shares[] = ($model->getBudget() - $model->getBudgetSpent()) / $model->getBudget();
        }

        return $shares === [] ? 1.0 : min($shares);
    }
}

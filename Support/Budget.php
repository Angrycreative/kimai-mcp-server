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
    public static function describe(BudgetStatisticModel $model, ?string $currency, bool $showTime, bool $showMoney): array
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

        return $out;
    }

    /**
     * The smallest share left of the budgets shown, for sorting: 0 is used up, 1 untouched, below 0 overrun.
     */
    public static function remainingShare(BudgetStatisticModel $model, bool $showTime = true, bool $showMoney = true): float
    {
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

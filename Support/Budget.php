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
 * details page. Kimai counts only billable time and money against a budget.
 */
final class Budget
{
    /**
     * @return array<string, mixed>
     */
    public static function describe(BudgetStatisticModel $model, ?string $currency): array
    {
        $out = [
            'type' => $model->isMonthlyBudget() ? 'monthly' : 'total',
        ];

        if ($model->hasTimeBudget()) {
            $used = $model->getTimeBudgetSpent();
            $out['time'] = [
                'budget_hours' => Format::hours($model->getTimeBudget()),
                'used_hours' => Format::hours($used),
                'remaining_hours' => Format::hours($model->getTimeBudget() - $used),
                'used_percent' => Format::percent($used, $model->getTimeBudget()),
            ];
        }

        if ($model->hasBudget()) {
            $used = $model->getBudgetSpent();
            $out['money'] = [
                'currency' => $currency,
                'budget' => Format::money($model->getBudget()),
                'used' => Format::money($used),
                'remaining' => Format::money($model->getBudget() - $used),
                'used_percent' => Format::percent($used, $model->getBudget()),
            ];
        }

        $out['logged_hours'] = Format::hours($model->getDuration());
        if ($model->isMonthlyBudget()) {
            $out['all_time'] = [
                'billable_hours' => Format::hours($model->getDurationBillableTotal()),
                'billable_amount' => Format::money($model->getRateBillableTotal()),
            ];
        }

        return $out;
    }

    /**
     * The smallest share left of the budgets the model has, for sorting: 0 is used up, 1 untouched.
     */
    public static function remainingShare(BudgetStatisticModel $model): float
    {
        $shares = [];
        if ($model->hasTimeBudget() && $model->getTimeBudget() > 0) {
            $shares[] = ($model->getTimeBudget() - $model->getTimeBudgetSpent()) / $model->getTimeBudget();
        }
        if ($model->hasBudget() && $model->getBudget() > 0) {
            $shares[] = ($model->getBudget() - $model->getBudgetSpent()) / $model->getBudget();
        }

        return $shares === [] ? 1.0 : min($shares);
    }
}

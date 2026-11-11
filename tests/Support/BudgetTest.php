<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Support;

use App\Entity\Project;
use App\Model\ProjectBudgetStatisticModel;
use App\Model\Statistic\BudgetStatistic;
use KimaiPlugin\McpServerBundle\Budget\RecurringBudget;
use KimaiPlugin\McpServerBundle\Support\Budget;
use PHPUnit\Framework\TestCase;

class BudgetTest extends TestCase
{
    private function model(int $timeBudget, float $budget, int $billableSeconds, float $billableRate): ProjectBudgetStatisticModel
    {
        $project = new Project();
        $project->setTimeBudget($timeBudget);
        $project->setBudget($budget);

        $statistic = new BudgetStatistic();
        $statistic->setDuration($billableSeconds + 3600);
        $statistic->setDurationBillable($billableSeconds);
        $statistic->setRateBillable($billableRate);

        $model = new ProjectBudgetStatisticModel($project);
        $model->setStatisticTotal($statistic);

        return $model;
    }

    public function testDescribesTimeAndMoneyIncludingAnOverrun(): void
    {
        $out = Budget::describe($this->model(36000, 1000.0, 45000, 250.0), 'SEK', true, true);

        $this->assertSame('total', $out['type']);
        $this->assertSame(['budget_hours' => 10.0, 'used_hours' => 12.5, 'remaining_hours' => 0.0, 'over_budget_hours' => 2.5, 'used_percent' => 125.0], $out['time']);
        $this->assertSame(['currency' => 'SEK', 'budget' => 1000.0, 'billable_revenue' => 250.0, 'remaining' => 750.0, 'over_budget' => 0.0, 'used_percent' => 25.0], $out['money']);
        $this->assertSame(13.5, $out['logged_hours']);
    }

    public function testTimeAndMoneyEachNeedTheirOwnPermission(): void
    {
        $model = $this->model(36000, 1000.0, 45000, 250.0);

        $timeOnly = Budget::describe($model, 'SEK', true, false);
        $this->assertArrayHasKey('time', $timeOnly);
        $this->assertArrayNotHasKey('money', $timeOnly);

        $moneyOnly = Budget::describe($model, 'SEK', false, true);
        $this->assertArrayHasKey('money', $moneyOnly);
        $this->assertArrayNotHasKey('time', $moneyOnly);
        $this->assertArrayNotHasKey('logged_hours', $moneyOnly);

        $this->assertSame(0.75, Budget::remainingShare($model, false, true));
    }

    public function testRemainingShareIsTheTightestBudget(): void
    {
        $this->assertSame(-0.25, Budget::remainingShare($this->model(36000, 1000.0, 45000, 250.0)));
        $this->assertSame(1.0, Budget::remainingShare($this->model(0, 0.0, 0, 0.0)));
    }

    private function recurring(int $usedSeconds, bool $credited = true, ?int $expiresAfter = 1): RecurringBudget
    {
        return new RecurringBudget(
            RecurringBudget::TIME,
            14400,
            '1 month',
            new \DateTimeImmutable('2026-11-01'),
            new \DateTimeImmutable('2026-11-30 23:59:59'),
            $usedSeconds,
            0.0,
            $credited,
            $expiresAfter,
        );
    }

    public function testRecurringBudgetShowsItsCurrentInterval(): void
    {
        // the running total is far overrun by history; this month is not
        $out = Budget::describe($this->model(28800, 0.0, 6264000, 0.0), 'SEK', true, true, $this->recurring(9000));

        $this->assertSame([
            'every' => '1 month',
            'current_interval' => ['from' => '2026-11-01', 'to' => '2026-11-30'],
            'credit_hours' => 4.0,
            'used_hours' => 2.5,
            'remaining_hours' => 1.5,
            'over_credit_hours' => 0.0,
            'used_percent' => 62.5,
            'unused_credit' => 'expires after 1 interval',
        ], $out['recurring']);
        $this->assertArrayHasKey('note', $out);
    }

    public function testRecurringBudgetNotStartedAndKeptForGood(): void
    {
        $out = Budget::describe($this->model(0, 0.0, 0, 0.0), 'SEK', true, false, $this->recurring(0, false, null));

        $this->assertSame('kept until used', $out['recurring']['unused_credit']);
        $this->assertArrayHasKey('not_started', $out['recurring']);
    }

    public function testRecurringTimeBudgetNeedsTheTimePermission(): void
    {
        $out = Budget::describe($this->model(28800, 1000.0, 0, 0.0), 'SEK', false, true, $this->recurring(0));

        $this->assertArrayNotHasKey('recurring', $out);
    }

    public function testRecurringBudgetIsRankedByItsInterval(): void
    {
        $model = $this->model(28800, 0.0, 6264000, 0.0);

        $this->assertLessThan(-100, Budget::remainingShare($model));
        $this->assertEqualsWithDelta(0.375, Budget::remainingShare($model, true, true, $this->recurring(9000)), 0.0001);
        $this->assertEqualsWithDelta(-0.5, Budget::remainingShare($model, true, true, $this->recurring(21600)), 0.0001);
    }
}

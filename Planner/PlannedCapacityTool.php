<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Planner;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Tool\AbstractReadTool;
use KimaiPlugin\PlannerBundle\Planner\WeeklyPlannerService;

final class PlannedCapacityTool extends AbstractReadTool
{
    public function __construct(
        private readonly PlannerSupport $support,
        private readonly WeeklyPlannerService $planner,
    ) {
    }

    public function getName(): string
    {
        return 'planned_capacity';
    }

    public function getDescription(): string
    {
        return 'Who has room in the Weekly Planner: per person and day, the contract hours, time off, the capacity '
            . 'left (contract minus time off), confirmed and tentative planned hours, and what is free. Whole weeks, '
            . 'Monday to Sunday, starting with the week holding "date". People are sorted by free hours from today, '
            . 'most first. The same figures as the planner screen.';
    }

    public function getInputSchema(): array
    {
        return Schema::object([
            'date' => Schema::date('A day in the first week. Default: today'),
            'weeks' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'description' => 'How many weeks, 1 to 8. Default: 1'],
            'user_ids' => Schema::ids('Only these people (IDs from find_users). Default: everyone you may see in the planner'),
            'days' => ['type' => 'boolean', 'description' => 'Include the figures per day, not just per person. Default: true'],
            'limit' => Schema::limit(100),
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_planner'];
    }

    public function call(array $arguments, User $user): array
    {
        $people = $this->support->visibleUsers($arguments, $user);
        $today = new \DateTimeImmutable('today', new \DateTimeZone($user->getTimezone()));
        $date = Args::date($arguments, 'date', $user) ?? $today;
        $weeks = Args::int($arguments, 'weeks') ?? 1;
        $withDays = Args::bool($arguments, 'days') ?? true;

        $data = $this->planner->getPlannerData($people, $date, $weeks);
        $data->sortByFreeHours($today);

        $rows = [];
        foreach ($data->getRows() as $row) {
            $person = [
                'user' => ['id' => $row->getUser()->getId(), 'name' => $row->getUser()->getDisplayName()],
                'contract_hours' => PlannerSupport::hours($row->getTotalExpectedHours()),
                'capacity_hours' => PlannerSupport::hours($row->getTotalCapacityHours()),
                'planned_hours' => PlannerSupport::hours($row->getTotalPlannedHours()),
                'tentative_hours' => PlannerSupport::hours($row->getTotalTentativeHours()),
                'free_hours_from_today' => $row->getFreeHours($today),
                'planned_percent' => $row->getPlannedPercentage(),
            ];
            if ($withDays) {
                $days = [];
                foreach ($row->getDays() as $day) {
                    if (!$day->isWorkingDay() && $day->getPlannedHours() <= 0.0 && $day->getTentativeHours() <= 0.0) {
                        continue;
                    }
                    $entry = [
                        'date' => $day->getDate()->format('Y-m-d'),
                        'contract' => PlannerSupport::hours($day->getExpectedHours()),
                        'time_off' => PlannerSupport::hours($day->getTimeOffHours()),
                        'planned' => PlannerSupport::hours($day->getPlannedHours()),
                        'tentative' => PlannerSupport::hours($day->getTentativeHours()),
                        'free' => PlannerSupport::hours($day->getCapacityHours() - $day->getPlannedHours()),
                    ];
                    if ($day->isHoliday()) {
                        $entry['holiday'] = $day->getHoliday()?->getName();
                    }
                    $days[] = $entry;
                }
                $person['days'] = $days;
            }
            $rows[] = $person;
        }

        $limit = Args::limit($arguments, 100);
        $out = [
            'period' => ['from' => $data->getStart()->format('Y-m-d'), 'to' => $data->getEnd()->format('Y-m-d')],
        ] + $this->listResult('people', \array_slice($rows, 0, $limit), \count($rows), $limit);
        $out['note'] = 'free = capacity minus confirmed planned hours; tentative hours are shown apart and not deducted.';

        return $out;
    }
}

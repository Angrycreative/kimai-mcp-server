<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\User;
use App\Repository\Query\UserQuery;
use App\Repository\Query\VisibilityInterface;
use App\Repository\UserRepository;
use KimaiPlugin\McpServerBundle\Capacity\ExpectedHours;
use KimaiPlugin\McpServerBundle\Capacity\WorkingCapacity;
use KimaiPlugin\McpServerBundle\Category\TimeCategoryProviderInterface;
use KimaiPlugin\McpServerBundle\People\People;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Format;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;
use KimaiPlugin\McpServerBundle\Support\TimeSummary;

final class UtilisationTool extends AbstractReadTool
{
    private const BASIS_CONTRACT = 'contract';
    private const BASIS_DEFAULT = 'default';
    private const BASIS_NONE = 'none';

    public function __construct(
        private readonly TimesheetScope $scope,
        private readonly TimeSummary $summary,
        private readonly UserRepository $users,
        private readonly ExpectedHours $expected,
        private readonly WorkingCapacity $capacity,
        private readonly People $people,
    ) {
    }

    public function getName(): string
    {
        return 'utilisation';
    }

    public function getDescription(): string
    {
        return 'Logged time against each person\'s work contract in a period: contract hours, absence, the hours '
            . 'left to work (contract minus absence on days they were expected to work), hours worked, billable '
            . 'hours, and the shares between them. Absence on days without contract hours, such as a bank holiday '
            . 'logged as absence, is shown as absence_hours_beyond_expected and takes nothing off. '
            . 'A period reaching past today is counted up to today. Contract hours come from Kimai\'s work '
            . 'contract, less the public holidays the person has off where the Weekly Planner is installed (their '
            . 'holiday calendar there). People without a contract are counted on the organisation\'s default week '
            . 'where one is configured (flagged default_week), otherwise flagged no_work_contract.';
    }

    public function getInputSchema(): array
    {
        $filters = TimesheetScope::filterSchema();

        return Schema::object([
            'from' => $filters['from'],
            'to' => $filters['to'],
            'user_ids' => Schema::ids('Only these people (IDs from find_users). Default: everyone whose time you may see'),
            'limit' => Schema::limit(100),
        ], ['from', 'to']);
    }

    public function getRequiredPermissions(): array
    {
        return ['view_own_timesheet|view_other_timesheet'];
    }

    public function call(array $arguments, User $user): array
    {
        [$from, $to] = Args::requiredRange($arguments, $user);
        $today = (new \DateTimeImmutable('today', new \DateTimeZone($user->getTimezone())))->setTime(23, 59, 59);
        $capped = $to > $today;
        if ($capped) {
            $to = $today;
            if ($from > $to) {
                throw new ToolException('The period starts after today, so nothing has been logged in it yet.');
            }
        }

        $people = $this->people($arguments, $user);
        if ($people === []) {
            throw new ToolException('Nobody found to report on.');
        }

        $arguments['from'] = $from->format('Y-m-d');
        $arguments['to'] = $to->format('Y-m-d');
        $arguments['user_ids'] = array_map(static fn (User $person): int => (int) $person->getId(), $people);
        unset($arguments['customer_ids'], $arguments['project_ids'], $arguments['activity_ids'], $arguments['billable'], $arguments['exported'], $arguments['search']);

        /** @var array<int, array{absence: float, worked: float, billable: float}> $logged */
        $logged = [];
        /** @var array<int, array<string, float>> $absenceByDay hours of absence per person and day */
        $absenceByDay = [];
        foreach ($this->summary->rows($this->scope->query($arguments, $user), ['user', 'category', 'date']) as $row) {
            $id = (int) $row->userId;
            $logged[$id] ??= ['absence' => 0.0, 'worked' => 0.0, 'billable' => 0.0];
            if ($row->category === TimeCategoryProviderInterface::ABSENCE) {
                $logged[$id]['absence'] += $row->hours();
                $day = \is_string($row->group['date'] ?? null) ? $row->group['date'] : '';
                $absenceByDay[$id][$day] = ($absenceByDay[$id][$day] ?? 0.0) + $row->hours();
            } else {
                $logged[$id]['worked'] += $row->hours();
                $logged[$id]['billable'] += $row->billableHours();
            }
        }

        $rows = [];
        $defaultUsed = false;
        $hidden = false;
        foreach ($people as $person) {
            $time = $logged[(int) $person->getId()] ?? ['absence' => 0.0, 'worked' => 0.0, 'billable' => 0.0];
            $row = [
                'user' => ['id' => $person->getId(), 'name' => $person->getDisplayName()],
                'absence_hours' => round($time['absence'], 2),
                'worked_hours' => round($time['worked'], 2),
                'billable_hours' => round($time['billable'], 2),
                'billable_percent_of_worked' => Format::percent($time['billable'], $time['worked']),
            ];

            // someone else's working hours are as private as Kimai's own "Working hours" screen keeps them
            if (!$this->scope->maySeeHours($person)) {
                $row['contract_hidden'] = true;
                $hidden = true;
                $rows[] = $row;
                continue;
            }

            [$contractSeconds, $basis, $expectedByDay] = $this->contract($person, $from, $to);
            $contract = Format::hours($contractSeconds);
            // absence only takes off the hours expected that day: absence logged on a public holiday or a weekend
            // (as many log bank holidays) is not time they were meant to work
            $absenceOnWorkingDays = 0.0;
            foreach ($absenceByDay[(int) $person->getId()] ?? [] as $day => $hours) {
                $absenceOnWorkingDays += min($hours, Format::hours($expectedByDay[$day] ?? 0));
            }
            $available = max(0.0, round($contract - $absenceOnWorkingDays, 2));
            $daysOff = round($time['absence'] - $absenceOnWorkingDays, 2);
            $row = \array_slice($row, 0, 1, true) + [
                'contract_hours' => $contract,
                'available_hours' => $available,
            ] + \array_slice($row, 1, null, true) + [
                'worked_percent_of_available' => Format::percent($time['worked'], $available),
                'billable_percent_of_available' => Format::percent($time['billable'], $available),
            ];
            if ($daysOff > 0 && $basis !== self::BASIS_NONE) {
                $row['absence_hours_beyond_expected'] = $daysOff;
            }
            if ($basis === self::BASIS_DEFAULT) {
                $row['default_week'] = true;
                $defaultUsed = true;
            } elseif ($basis === self::BASIS_NONE) {
                $row['no_work_contract'] = true;
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => strnatcasecmp((string) $a['user']['name'], (string) $b['user']['name']));

        $limit = Args::limit($arguments, 100);
        $out = [
            'period' => ['from' => $arguments['from'], 'to' => $arguments['to']],
            'people' => \array_slice($rows, 0, $limit),
        ];
        $provider = $this->capacity->provider();
        if ($defaultUsed && $provider !== null) {
            $out['default_week'] = 'People flagged default_week have no work contract and are counted on ' . $provider->describe() . '.';
        }
        if ($hidden) {
            $out['contract_hidden'] = 'Your Kimai role may not see the working hours of people flagged contract_hidden, so only their logged time is shown.';
        }
        if ($capped) {
            $out['note'] = 'The period reaches past today, so it is counted up to today.';
        }
        if (\count($rows) > $limit) {
            $out['truncated'] = \sprintf('Only %d of %d people are shown.', $limit, \count($rows));
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<User>
     * @throws ToolException
     */
    private function people(array $arguments, User $user): array
    {
        $ids = Args::ids($arguments, 'user_ids');

        if (!$this->scope->seesOthers()) {
            return $ids === [] ? [$user] : $this->scope->people($ids, $user);
        }

        if ($ids !== []) {
            return $this->scope->people($ids, $user);
        }

        $query = new UserQuery();
        $query->setCurrentUser($user);
        $query->setVisibility(VisibilityInterface::SHOW_VISIBLE);
        $query->setSystemAccount(false);

        return array_values(array_filter(
            $this->people->only($this->users->getUsersForQuery($query)),
            fn (User $person): bool => $this->scope->mayAccess($person)
        ));
    }

    /**
     * Expected seconds from the first to the last day, in total and per day: from the person's contract, else from
     * the capacity provider, else none.
     *
     * @return array{0: int, 1: string, 2: array<string, int>}
     */
    private function contract(User $person, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $last = $to->format('Y-m-d');
        $seconds = 0;
        $byDay = [];
        $basis = ExpectedHours::NONE;
        // midday, so a step of one day never lands on the same date twice across a daylight saving change
        for ($day = $from->setTime(12, 0); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
            [$forDay, $dayBasis] = $this->expected->forDay($person, $day);
            if ($forDay === null) {
                return [0, self::BASIS_NONE, []];
            }
            $seconds += $forDay;
            $byDay[$day->format('Y-m-d')] = $forDay;
            $basis = $dayBasis;
        }

        return match ($basis) {
            ExpectedHours::CONTRACT => [$seconds, self::BASIS_CONTRACT, $byDay],
            ExpectedHours::DEFAULT => [$seconds, self::BASIS_DEFAULT, $byDay],
            default => [0, self::BASIS_NONE, []],
        };
    }
}

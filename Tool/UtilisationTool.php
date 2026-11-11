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
            . 'left to work (contract minus absence), hours worked, billable hours, and the shares between them. '
            . 'A period reaching past today is counted up to today. Contract hours come from Kimai\'s work '
            . 'contract, which does not know public holidays. People without one are counted on the organisation\'s default '
            . 'week where one is configured (flagged default_week), otherwise flagged no_work_contract.';
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
        foreach ($this->summary->rows($this->scope->query($arguments, $user), ['user', 'category']) as $row) {
            $id = (int) $row->userId;
            $logged[$id] ??= ['absence' => 0.0, 'worked' => 0.0, 'billable' => 0.0];
            if ($row->category === TimeCategoryProviderInterface::ABSENCE) {
                $logged[$id]['absence'] += $row->hours();
            } else {
                $logged[$id]['worked'] += $row->hours();
                $logged[$id]['billable'] += $row->billableHours();
            }
        }

        $rows = [];
        $defaultUsed = false;
        foreach ($people as $person) {
            [$contractSeconds, $basis] = $this->contract($person, $from, $to);
            $contract = Format::hours($contractSeconds);
            $time = $logged[(int) $person->getId()] ?? ['absence' => 0.0, 'worked' => 0.0, 'billable' => 0.0];
            $available = max(0.0, round($contract - $time['absence'], 2));

            $row = [
                'user' => ['id' => $person->getId(), 'name' => $person->getDisplayName()],
                'contract_hours' => $contract,
                'absence_hours' => round($time['absence'], 2),
                'available_hours' => $available,
                'worked_hours' => round($time['worked'], 2),
                'billable_hours' => round($time['billable'], 2),
                'worked_percent_of_available' => Format::percent($time['worked'], $available),
                'billable_percent_of_available' => Format::percent($time['billable'], $available),
                'billable_percent_of_worked' => Format::percent($time['billable'], $time['worked']),
            ];
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
            if (array_diff($ids, [$user->getId()]) !== []) {
                throw new ToolException('You can only see your own time: your Kimai role lacks the permission to view other people\'s timesheets.');
            }

            return [$user];
        }

        if ($ids !== []) {
            return array_values($this->users->findByIds($ids));
        }

        $query = new UserQuery();
        $query->setCurrentUser($user);
        $query->setVisibility(VisibilityInterface::SHOW_VISIBLE);
        $query->setSystemAccount(false);

        return $this->people->only($this->users->getUsersForQuery($query));
    }

    /**
     * Expected seconds from the first to the last day: from the person's contract, else from the capacity provider,
     * else none.
     *
     * @return array{0: int, 1: string}
     */
    private function contract(User $person, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $last = $to->format('Y-m-d');
        $seconds = 0;
        $basis = ExpectedHours::NONE;
        // midday, so a step of one day never lands on the same date twice across a daylight saving change
        for ($day = $from->setTime(12, 0); $day->format('Y-m-d') <= $last; $day = $day->modify('+1 day')) {
            [$forDay, $dayBasis] = $this->expected->forDay($person, $day);
            if ($forDay === null) {
                return [0, self::BASIS_NONE];
            }
            $seconds += $forDay;
            $basis = $dayBasis;
        }

        return match ($basis) {
            ExpectedHours::CONTRACT => [$seconds, self::BASIS_CONTRACT],
            ExpectedHours::DEFAULT => [$seconds, self::BASIS_DEFAULT],
            default => [0, self::BASIS_NONE],
        };
    }
}

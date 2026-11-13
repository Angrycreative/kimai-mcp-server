<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Capacity;

use App\Entity\User;
use App\WorkingTime\Calculator\WorkingTimeCalculator;
use App\WorkingTime\Mode\WorkingTimeModeNone;
use App\WorkingTime\WorkingTimeService;

/**
 * What a person is expected to work on a day: from their work contract, else from the capacity provider another
 * plugin registers, else unknown. Days outside their first and last working day expect nothing, and so do the public
 * holidays they have off, where a holiday calendar is installed (PublicHolidaysInterface).
 */
final class ExpectedHours
{
    public const CONTRACT = 'contract';
    public const DEFAULT = 'default';
    public const NONE = 'none';

    /** @var array<int, WorkingTimeCalculator|false> per user ID; false when they have no contract */
    private array $calculators = [];
    /** @var array<string, array<string, string>> holiday names by date, per user ID and year */
    private array $holidays = [];

    public function __construct(
        private readonly WorkingTimeService $workingTime,
        private readonly WorkingCapacity $capacity,
        private readonly ?PublicHolidaysInterface $calendar = null,
    ) {
    }

    /**
     * The name of the public holiday the person has off on this day, or null.
     */
    public function holiday(User $user, \DateTimeInterface $day): ?string
    {
        if ($this->calendar === null) {
            return null;
        }
        $year = $day->format('Y');
        $key = $user->getId() . '-' . $year;
        if (!\array_key_exists($key, $this->holidays)) {
            $zone = new \DateTimeZone($user->getTimezone());
            $this->holidays[$key] = $this->calendar->between(
                $user,
                new \DateTimeImmutable($year . '-01-01 00:00:00', $zone),
                new \DateTimeImmutable($year . '-12-31 23:59:59', $zone)
            );
        }

        return $this->holidays[$key][$day->format('Y-m-d')] ?? null;
    }

    /**
     * @param bool $ignoreHoliday what the day would expect if it were not a public holiday, e.g. to log the holiday
     *                            itself as absence
     * @return array{0: int|null, 1: self::CONTRACT|self::DEFAULT|self::NONE} seconds (null when unknown) and where
     *                                                                        the figure came from
     */
    public function forDay(User $user, \DateTimeInterface $day, bool $ignoreHoliday = false): array
    {
        $key = $day->format('Y-m-d');
        $start = $user->getWorkStartingDay()?->format('Y-m-d');
        $end = $user->getLastWorkingDay()?->format('Y-m-d');

        $calculator = $this->calculator($user);
        if ($calculator !== null) {
            $outside = ($start !== null && $key < $start) || ($end !== null && $key > $end);

            return [$outside || (!$ignoreHoliday && $this->holiday($user, $day) !== null) ? 0 : $calculator->getWorkHoursForDay($day), self::CONTRACT];
        }

        $provider = $this->capacity->provider();
        $seconds = $provider?->secondsWithoutContract($user, $day);
        if ($seconds === null) {
            return [null, self::NONE];
        }
        $outside = ($start !== null && $key < $start) || ($end !== null && $key > $end);

        return [$outside || (!$ignoreHoliday && $this->holiday($user, $day) !== null) ? 0 : max(0, $seconds), self::DEFAULT];
    }

    private function calculator(User $user): ?WorkingTimeCalculator
    {
        $id = (int) $user->getId();
        if (!\array_key_exists($id, $this->calculators)) {
            $mode = $this->workingTime->getContractMode($user);
            $this->calculators[$id] = $mode->getId() === WorkingTimeModeNone::ID ? false : $mode->getCalculator($user);
        }

        return $this->calculators[$id] === false ? null : $this->calculators[$id];
    }
}

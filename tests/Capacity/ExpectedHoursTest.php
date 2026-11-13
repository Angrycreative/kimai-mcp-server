<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Capacity;

use App\WorkingTime\Mode\WorkingTimeModeNone;
use App\WorkingTime\WorkingTimeService;
use KimaiPlugin\McpServerBundle\Capacity\ExpectedHours;
use KimaiPlugin\McpServerBundle\Capacity\PublicHolidaysInterface;
use KimaiPlugin\McpServerBundle\Capacity\WorkingCapacity;
use KimaiPlugin\McpServerBundle\Capacity\WorkingCapacityProviderInterface;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use PHPUnit\Framework\TestCase;

/**
 * Expected hours leave out the public holidays a person has off, which is what keeps utilisation from counting
 * Christmas Day as hours they failed to work.
 */
final class ExpectedHoursTest extends TestCase
{
    private function expected(?PublicHolidaysInterface $calendar): ExpectedHours
    {
        $workingTime = $this->createMock(WorkingTimeService::class);
        $workingTime->method('getContractMode')->willReturn(new WorkingTimeModeNone());
        $week = $this->createMock(WorkingCapacityProviderInterface::class);
        $week->method('secondsWithoutContract')->willReturn(8 * 3600);

        return new ExpectedHours($workingTime, new WorkingCapacity([$week]), $calendar);
    }

    private function day(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 12:00', new \DateTimeZone('Europe/Stockholm'));
    }

    public function testAPublicHolidayExpectsNothing(): void
    {
        $calendar = $this->createMock(PublicHolidaysInterface::class);
        $calendar->method('between')->willReturn(['2026-12-25' => 'Christmas Day']);
        $expected = $this->expected($calendar);
        $user = TestUser::make(5);

        self::assertSame([0, ExpectedHours::DEFAULT], $expected->forDay($user, $this->day('2026-12-25')));
        self::assertSame([8 * 3600, ExpectedHours::DEFAULT], $expected->forDay($user, $this->day('2026-12-23')));
        self::assertSame('Christmas Day', $expected->holiday($user, $this->day('2026-12-25')));
        self::assertNull($expected->holiday($user, $this->day('2026-12-23')));
    }

    public function testTheCalendarIsAskedOncePerPersonAndYear(): void
    {
        $calendar = $this->createMock(PublicHolidaysInterface::class);
        $calendar->expects(self::exactly(2))->method('between')->willReturn([]);
        $expected = $this->expected($calendar);
        $user = TestUser::make(5);

        foreach (['2026-12-01', '2026-12-02', '2026-12-31', '2027-01-01', '2027-01-02'] as $date) {
            $expected->forDay($user, $this->day($date));
        }
    }

    public function testWithoutACalendarAHolidayIsAWorkingDay(): void
    {
        $expected = $this->expected(null);

        self::assertSame([8 * 3600, ExpectedHours::DEFAULT], $expected->forDay(TestUser::make(5), $this->day('2026-12-25')));
        self::assertNull($expected->holiday(TestUser::make(5), $this->day('2026-12-25')));
    }
}

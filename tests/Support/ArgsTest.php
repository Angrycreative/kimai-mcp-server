<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Support;

use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use PHPUnit\Framework\TestCase;

class ArgsTest extends TestCase
{
    public function testDatesAreDaysInTheUsersTimezone(): void
    {
        $user = TestUser::make(1, 'Europe/London');

        $from = Args::date(['from' => '2026-10-01'], 'from', $user);
        $to = Args::date(['to' => '2026-10-31'], 'to', $user, true);

        $this->assertSame('2026-10-01T00:00:00+01:00', $from?->format('c'));
        $this->assertSame('2026-10-31T23:59:59+00:00', $to?->format('c'));
    }

    public function testInvalidDatesAreRefusedWithTheirName(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('"from" must be a date written as YYYY-MM-DD, got "2026-02-30".');

        Args::date(['from' => '2026-02-30'], 'from', TestUser::make(1));
    }

    public function testRangeMustBeInOrderAndCanBeRequired(): void
    {
        $user = TestUser::make(1);

        $this->assertSame([null, null], Args::range([], $user));

        try {
            Args::range(['from' => '2026-10-02', 'to' => '2026-10-01'], $user);
            $this->fail('A range ending before it starts must be refused.');
        } catch (ToolException $e) {
            $this->assertSame('"from" is after "to".', $e->getMessage());
        }

        $this->expectException(ToolException::class);
        Args::range(['from' => '2026-10-01'], $user, true);
    }

    public function testIdsDropJunkAndDuplicates(): void
    {
        $this->assertSame([3, 7], Args::ids(['ids' => [3, '7', 3, 0, -1, 'x', null]], 'ids'));
        $this->assertSame([], Args::ids([], 'ids'));
    }

    public function testLimitStaysWithinBounds(): void
    {
        $this->assertSame(Args::DEFAULT_LIMIT, Args::limit([]));
        $this->assertSame(1, Args::limit(['limit' => 0]));
        $this->assertSame(Args::MAX_LIMIT, Args::limit(['limit' => 100000]));
        $this->assertSame(20, Args::limit(['limit' => 20]));
    }
}

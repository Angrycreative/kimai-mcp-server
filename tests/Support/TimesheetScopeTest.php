<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Support;

use App\Entity\Customer;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use KimaiPlugin\McpServerBundle\Support\TimesheetScope;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class TimesheetScopeTest extends TestCase
{
    /**
     * @param list<string> $granted
     */
    private function scope(array $granted, ?UserRepository $users = null, ?CustomerRepository $customers = null): TimesheetScope
    {
        $auth = $this->createMock(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (string $attribute): bool => \in_array($attribute, $granted, true));

        return new TimesheetScope(
            $this->createMock(TimesheetRepository::class),
            $customers ?? $this->createMock(CustomerRepository::class),
            $this->createMock(ProjectRepository::class),
            $this->createMock(ActivityRepository::class),
            $users ?? $this->createMock(UserRepository::class),
            $auth,
        );
    }

    public function testWithoutViewOtherTimesheetOnlyOwnTimeIsQueried(): void
    {
        $user = TestUser::make(5);
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findByIds');

        $query = $this->scope(['view_own_timesheet'], $users)->query([], $user);

        $this->assertSame($user, $query->getUser());
        $this->assertSame($user, $query->getCurrentUser());
    }

    public function testWithoutViewOtherTimesheetAskingForSomeoneElseIsRefused(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('You can only see your own time');

        $this->scope(['view_own_timesheet'])->query(['user_ids' => [5, 6]], TestUser::make(5));
    }

    public function testAskingForYourselfWithoutTheOtherPermissionIsFine(): void
    {
        $user = TestUser::make(5);

        $this->assertSame($user, $this->scope(['view_own_timesheet'])->query(['user_ids' => [5]], $user)->getUser());
    }

    public function testWithViewOtherTimesheetTheChosenPeopleAreQueriedWhenKimaiLetsTheCallerSeeThem(): void
    {
        $other = TestUser::make(6);
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('findByIds')->with([6])->willReturn([$other]);

        $query = $this->scope(['view_other_timesheet', 'access_user'], $users)->query(['user_ids' => [6]], TestUser::make(5));

        $this->assertNull($query->getUser());
        $this->assertSame([$other], $query->getUsers());
    }

    public function testSomeoneTheCallerMayNotSeeIsRefusedNotQueried(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->method('findByIds')->willReturn([TestUser::make(6)]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('Kimai does not let you see the time of user 6');

        $this->scope(['view_other_timesheet'], $users)->query(['user_ids' => [6]], TestUser::make(5));
    }

    public function testFiltersMapOntoKimaisQuery(): void
    {
        $customer = new Customer('ACME');
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, 9);
        $customers = $this->createMock(CustomerRepository::class);
        $customers->method('findByIds')->with([9])->willReturn([$customer]);

        $query = $this->scope(['view_other_timesheet'], null, $customers)->query([
            'from' => '2026-10-01',
            'to' => '2026-10-31',
            'customer_ids' => [9],
            'billable' => true,
            'exported' => false,
            'search' => 'BINT-25',
        ], TestUser::make(5));

        $this->assertSame('2026-10-01 00:00:00', $query->getBegin()?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 23:59:59', $query->getEnd()?->format('Y-m-d H:i:s'));
        $this->assertSame([$customer], $query->getCustomers());
        $this->assertTrue($query->isBillable());
        $this->assertTrue($query->isNotExported());
        $this->assertSame('BINT-25', $query->getSearchTerm()?->getOriginalSearch());
    }

    public function testUnknownIdsAreAnErrorRatherThanNoFilter(): void
    {
        $customers = $this->createMock(CustomerRepository::class);
        $customers->method('findByIds')->willReturn([]);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No customer with ID 999.');

        $this->scope(['view_other_timesheet'], null, $customers)->query(['customer_ids' => [999]], TestUser::make(5));
    }

    public function testExportedFalseAndTrue(): void
    {
        $scope = $this->scope(['view_other_timesheet']);

        $this->assertTrue($scope->query(['exported' => true], TestUser::make(5))->isExported());
        $this->assertSame(TimesheetQuery::STATE_ALL, $scope->query([], TestUser::make(5))->getExported());
    }
}

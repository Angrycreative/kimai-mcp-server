<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests;

use App\Configuration\SystemConfiguration;
use App\Doctrine\UTCDateTimeImmutableType;
use App\Doctrine\UTCDateTimeType;
use App\Entity\User;
use App\Security\RolePermissionManager;
use App\Timesheet\LockdownService;
use App\User\PermissionService;
use App\Voter\ActivityVoter;
use App\Voter\CustomerVoter;
use App\Voter\ProjectVoter;
use App\Voter\RolePermissionVoter;
use App\Voter\TimesheetVoter;
use App\Voter\UserVoter;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Kimai's real rules for tests, where mocks would only echo what the test already assumes: its own voters over a
 * permission map, and Doctrine over Kimai's own entity mappings, to compile the queries core and the tools build.
 */
final class Kimai
{
    /**
     * Kimai's voters for this user, with these permissions per role, as System > Roles would hold them.
     *
     * @param array<string, list<string>> $permissions role => permissions
     */
    public static function auth(User $user, array $permissions): AuthorizationCheckerInterface
    {
        $service = (new \ReflectionClass(PermissionService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PermissionService::class, 'cacheAll'))->setValue($service, []);

        $map = [];
        $names = [];
        foreach ($permissions as $role => $granted) {
            foreach ($granted as $permission) {
                $map[$role][$permission] = true;
                $names[$permission] = true;
            }
        }
        $manager = new RolePermissionManager($service, $map, $names);

        // no lockdown period configured, as on a fresh install
        $lockdown = new LockdownService((new \ReflectionClass(SystemConfiguration::class))->newInstanceWithoutConstructor());
        (new \ReflectionProperty(LockdownService::class, 'isActive'))->setValue($lockdown, false);

        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));

        return new AuthorizationChecker($tokens, new AccessDecisionManager([
            new RolePermissionVoter($manager),
            new UserVoter($manager),
            new CustomerVoter($manager),
            new ProjectVoter($manager),
            new ActivityVoter($manager),
            new TimesheetVoter($manager, $lockdown),
        ]));
    }

    /**
     * An entity manager over Kimai's own mappings and a fresh in-memory SQLite database with Kimai's schema: the
     * queries core and the tools build run for real, so a clash of aliases or a wrong join fails here and not in
     * production, and nothing touches real data.
     */
    public static function entityManager(): EntityManager
    {
        Type::overrideType('datetime', UTCDateTimeType::class);
        Type::overrideType('datetime_immutable', UTCDateTimeImmutableType::class);
        $kimai = getenv('KIMAI_DIR') ?: \dirname(__DIR__, 2) . '/kimai';
        $config = ORMSetup::createAttributeMetadataConfiguration([$kimai . '/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));

        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}

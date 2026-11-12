<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Category;

use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\McpServerBundle\Category\DefaultTimeCategoryProvider;
use KimaiPlugin\McpServerBundle\Category\TimeCategories;
use KimaiPlugin\McpServerBundle\Category\TimeCategoryProviderInterface;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;
use KimaiPlugin\McpServerBundle\Tests\Settings\McpSettingsTest;
use KimaiPlugin\McpServerBundle\Tests\TestUser;
use PHPUnit\Framework\TestCase;

class DefaultTimeCategoryProviderTest extends TestCase
{
    private function provider(): DefaultTimeCategoryProvider
    {
        return new DefaultTimeCategoryProvider(
            McpSettingsTest::settings([McpSettings::ABSENCE_PROJECTS => '528,1492']),
            $this->createMock(EntityManagerInterface::class),
        );
    }

    public function testAbsenceProjectsAreAbsenceAndTheRestIsOther(): void
    {
        $provider = $this->provider();

        $this->assertSame('absence', $provider->categoryFor(528, 40, 37));
        $this->assertSame('other', $provider->categoryFor(1815, 583, 17));
        $this->assertSame('other', $provider->categoryFor(null, null, null));
        $this->assertArrayHasKey(TimeCategoryProviderInterface::ABSENCE, $provider->getCategories());
        $this->assertSame([528, 1492], $provider->getAbsenceProjectIds());
    }

    public function testAnotherPluginsProviderReplacesTheDefault(): void
    {
        $default = $this->provider();
        $other = $this->createMock(TimeCategoryProviderInterface::class);

        $this->assertSame($other, (new TimeCategories([$default, $other], $default))->provider());
        $this->assertSame($default, (new TimeCategories([$default], $default))->provider());
    }

    public function testWithOneAbsenceProjectEveryoneGetsIt(): void
    {
        $project = new Project();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('find')->with(Project::class, 528)->willReturn($project);
        $em->expects($this->never())->method('createQueryBuilder');

        $provider = new DefaultTimeCategoryProvider(McpSettingsTest::settings([McpSettings::ABSENCE_PROJECTS => '528']), $em);

        $this->assertSame($project, $provider->getAbsenceProjectFor(TestUser::make(5)));
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Settings;

use App\Configuration\ConfigLoaderInterface;
use App\Configuration\SystemConfiguration;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;
use PHPUnit\Framework\TestCase;

class McpSettingsTest extends TestCase
{
    /**
     * @param array<string, string|null> $stored
     */
    public static function settings(array $stored): McpSettings
    {
        $loader = new class($stored) implements ConfigLoaderInterface {
            /** @param array<string, string|null> $stored */
            public function __construct(private readonly array $stored)
            {
            }

            public function getConfigurations(): array
            {
                return $this->stored;
            }
        };

        return new McpSettings(new SystemConfiguration($loader));
    }

    public function testDefaultsWhenNothingIsSaved(): void
    {
        $settings = self::settings([]);

        $this->assertTrue($settings->logReads());
        $this->assertSame(90, $settings->retentionDays());
        $this->assertSame([], $settings->absenceProjectIds());
    }

    public function testSavedValues(): void
    {
        $settings = self::settings([
            McpSettings::LOG_READS => '0',
            McpSettings::RETENTION_DAYS => '30',
            McpSettings::ABSENCE_PROJECTS => '528, 1492,x,528,0',
        ]);

        $this->assertFalse($settings->logReads());
        $this->assertSame(30, $settings->retentionDays());
        $this->assertSame([528, 1492], $settings->absenceProjectIds());
    }

    public function testRetentionIsNeverBelowOneDay(): void
    {
        $this->assertSame(1, self::settings([McpSettings::RETENTION_DAYS => '0'])->retentionDays());
        $this->assertSame(90, self::settings([McpSettings::RETENTION_DAYS => 'soon'])->retentionDays());
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration;
use App\Form\Type\YesNoType;
use KimaiPlugin\McpServerBundle\Form\Type\ProjectIdListType;
use KimaiPlugin\McpServerBundle\Settings\McpSettings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Adds an "MCP" section to System > Settings: read logging, call log retention, and the absence projects the
 * default time category provider uses.
 */
final class SettingsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            SystemConfigurationEvent::class => ['onSystemConfiguration', 100],
        ];
    }

    public function onSystemConfiguration(SystemConfigurationEvent $event): void
    {
        $section = (new SystemConfiguration('mcp'))
            ->setTranslation('mcp.settings.title')
            ->setTranslationDomain('messages');

        $section->addConfiguration(
            (new Configuration(McpSettings::LOG_READS))
                ->setLabel('mcp.settings.log_reads')
                ->setTranslationDomain('messages')
                ->setOptions(['help' => 'mcp.settings.log_reads_help'])
                ->setRequired(false)
                ->setType(YesNoType::class)
                ->setValue(McpSettings::DEFAULT_LOG_READS)
        );

        $section->addConfiguration(
            (new Configuration(McpSettings::RETENTION_DAYS))
                ->setLabel('mcp.settings.retention_days')
                ->setTranslationDomain('messages')
                ->setOptions(['help' => 'mcp.settings.retention_days_help'])
                ->setRequired(true)
                ->setType(IntegerType::class)
                ->setConstraints([new Range(min: 1, max: 3650)])
                ->setValue(McpSettings::DEFAULT_RETENTION_DAYS)
        );

        $section->addConfiguration(
            (new Configuration(McpSettings::ABSENCE_PROJECTS))
                ->setLabel('mcp.settings.absence_projects')
                ->setTranslationDomain('messages')
                ->setOptions([
                    'help' => 'mcp.settings.absence_projects_help',
                    ProjectIdListType::OPTION_SETTING_KEY => McpSettings::ABSENCE_PROJECTS,
                ])
                ->setRequired(false)
                ->setType(ProjectIdListType::class)
        );

        $event->addConfiguration($section);
    }
}

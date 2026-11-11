<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use KimaiPlugin\McpServerBundle\Category\TimeCategoryProviderInterface;
use KimaiPlugin\McpServerBundle\Tool\ToolInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class McpServerExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public const TOOL_TAG = 'kimai_mcp_server.tool';
    public const CATEGORY_PROVIDER_TAG = 'kimai_mcp_server.time_category_provider';

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Any service implementing ToolInterface, in this bundle or another plugin, becomes a tool.
        $container->registerForAutoconfiguration(ToolInterface::class)->addTag(self::TOOL_TAG);
        // A plugin implementing this replaces the bundle's default time categories.
        $container->registerForAutoconfiguration(TimeCategoryProviderInterface::class)->addTag(self::CATEGORY_PROVIDER_TAG);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    /**
     * Two permissions, held by super admins by default and given to other roles under System > Role permissions:
     * mcp_access to use the endpoint at all, and mcp_write as a deliberate second switch for tools with side effects.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'sets' => [
                    'MCP_SERVER' => ['mcp_access', 'mcp_write'],
                ],
                'roles' => [
                    'ROLE_SUPER_ADMIN' => ['mcp_access', 'mcp_write'],
                ],
            ],
        ]);
    }
}

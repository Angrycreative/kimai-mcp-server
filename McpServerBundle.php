<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class McpServerBundle extends Bundle implements PluginInterface
{
    /**
     * Where Strauss puts the MCP SDK and its dependencies, every class prefixed with
     * KimaiPlugin\McpServerBundle\Vendor\ so nothing can clash with Kimai's own vendor directory.
     */
    public const VENDOR_AUTOLOAD = __DIR__ . '/vendor-prefixed/autoload.php';

    /**
     * Kimai never installs a plugin's Composer dependencies, so the bundle brings its own autoloader. It is registered
     * here because the kernel constructs every bundle before the container is built or a request handled. A checkout
     * without the prefixed vendor directory still boots; the endpoint then answers 503 instead of taking Kimai down.
     */
    public function __construct()
    {
        if (is_file(self::VENDOR_AUTOLOAD)) {
            require_once self::VENDOR_AUTOLOAD;
        }
    }

    public static function isVendorInstalled(): bool
    {
        return is_file(self::VENDOR_AUTOLOAD);
    }
}

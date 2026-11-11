<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Kimai supplies its own classes and Symfony; the bundle and its prefixed libraries are loaded from this checkout.
$kimai = getenv('KIMAI_DIR') ?: dirname(__DIR__, 2) . '/kimai';
if (!is_file($kimai . '/vendor/autoload.php')) {
    fwrite(\STDERR, "Set KIMAI_DIR to a Kimai checkout with its vendor directory installed.\n");
    exit(1);
}
require $kimai . '/vendor/autoload.php';

$namespaces = [
    'KimaiPlugin\\McpServerBundle\\Tests\\' => __DIR__,
    'KimaiPlugin\\McpServerBundle\\' => dirname(__DIR__),
];
// ahead of Kimai's own autoloader, which maps KimaiPlugin\ to var/plugins: where this bundle is installed there too,
// the tests would otherwise run against the installed copy instead of this checkout
spl_autoload_register(static function (string $class) use ($namespaces): void {
    foreach ($namespaces as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}, true, true);

require dirname(__DIR__) . '/vendor-prefixed/autoload.php';

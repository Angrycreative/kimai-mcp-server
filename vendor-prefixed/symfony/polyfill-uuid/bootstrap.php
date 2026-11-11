<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use KimaiPlugin\McpServerBundle\Vendor\Symfony\Polyfill\Uuid as p;

if (extension_loaded('uuid')) {
    return;
}

if (\PHP_VERSION_ID >= 80000) {
    return require __DIR__.'/bootstrap80.php';
}

if (!defined('KIMAI_MCP_SERVER_UUID_VARIANT_NCS')) {
    define('KIMAI_MCP_SERVER_UUID_VARIANT_NCS', 0);
}
if (!defined('KIMAI_MCP_SERVER_UUID_VARIANT_DCE')) {
    define('KIMAI_MCP_SERVER_UUID_VARIANT_DCE', 1);
}
if (!defined('KIMAI_MCP_SERVER_UUID_VARIANT_MICROSOFT')) {
    define('KIMAI_MCP_SERVER_UUID_VARIANT_MICROSOFT', 2);
}
if (!defined('KIMAI_MCP_SERVER_UUID_VARIANT_OTHER')) {
    define('KIMAI_MCP_SERVER_UUID_VARIANT_OTHER', 3);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_DEFAULT')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_DEFAULT', 0);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_TIME')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_TIME', 1);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_SECURITY')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_SECURITY', 2);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_TIME_V6')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_TIME_V6', 6);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_TIME_V7')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_TIME_V7', 7);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_VENDOR')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_VENDOR', 8);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_MD5')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_MD5', 3);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_DCE')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_DCE', 4); // Deprecated alias
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_NAME')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_NAME', 1); // Deprecated alias
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_RANDOM')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_RANDOM', 4);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_SHA1')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_SHA1', 5);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_NULL')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_NULL', -1);
}
if (!defined('KIMAI_MCP_SERVER_UUID_TYPE_INVALID')) {
    define('KIMAI_MCP_SERVER_UUID_TYPE_INVALID', -42);
}

if (!function_exists('kimaipluginmcpserver_uuid_create')) {
    function kimaipluginmcpserver_uuid_create($uuid_type = \KIMAI_MCP_SERVER_UUID_TYPE_DEFAULT) { return p\Uuid::uuid_create($uuid_type); }
}
if (!function_exists('kimaipluginmcpserver_uuid_generate_md5')) {
    function kimaipluginmcpserver_uuid_generate_md5($uuid_ns, $name) { return p\Uuid::uuid_generate_md5($uuid_ns, $name); }
}
if (!function_exists('kimaipluginmcpserver_uuid_generate_sha1')) {
    function kimaipluginmcpserver_uuid_generate_sha1($uuid_ns, $name) { return p\Uuid::uuid_generate_sha1($uuid_ns, $name); }
}
if (!function_exists('kimaipluginmcpserver_uuid_is_valid')) {
    function kimaipluginmcpserver_uuid_is_valid($uuid) { return p\Uuid::uuid_is_valid($uuid); }
}
if (!function_exists('kimaipluginmcpserver_uuid_compare')) {
    function kimaipluginmcpserver_uuid_compare($uuid1, $uuid2) { return p\Uuid::uuid_compare($uuid1, $uuid2); }
}
if (!function_exists('kimaipluginmcpserver_uuid_is_null')) {
    function kimaipluginmcpserver_uuid_is_null($uuid) { return p\Uuid::uuid_is_null($uuid); }
}
if (!function_exists('kimaipluginmcpserver_uuid_type')) {
    function kimaipluginmcpserver_uuid_type($uuid) { return p\Uuid::uuid_type($uuid); }
}
if (!function_exists('kimaipluginmcpserver_uuid_variant')) {
    function kimaipluginmcpserver_uuid_variant($uuid) { return p\Uuid::uuid_variant($uuid); }
}
if (!function_exists('kimaipluginmcpserver_uuid_time')) {
    function kimaipluginmcpserver_uuid_time($uuid) { return p\Uuid::uuid_time($uuid); }
}
if (!function_exists('kimaipluginmcpserver_uuid_mac')) {
    function kimaipluginmcpserver_uuid_mac($uuid) { return p\Uuid::uuid_mac($uuid); }
}
if (!function_exists('kimaipluginmcpserver_uuid_parse')) {
    function kimaipluginmcpserver_uuid_parse($uuid) { return p\Uuid::uuid_parse($uuid); }
}
if (!function_exists('kimaipluginmcpserver_uuid_unparse')) {
    function kimaipluginmcpserver_uuid_unparse($uuid) { return p\Uuid::uuid_unparse($uuid); }
}

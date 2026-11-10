<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Server;

use KimaiPlugin\McpServerBundle\Vendor\Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The SDK is prefixed, so it expects the prefixed PSR-3 interface, which Kimai's Monolog logger does not implement.
 * This passes the SDK's log lines through to Kimai's logger unchanged.
 */
final class LoggerBridge extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}

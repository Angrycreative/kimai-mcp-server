<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

/**
 * An error the model should see as a tool result (isError: true) rather than a protocol failure, so it can correct
 * itself: the message is written for the model and must not leak anything the user may not see.
 */
final class ToolException extends \RuntimeException
{
}

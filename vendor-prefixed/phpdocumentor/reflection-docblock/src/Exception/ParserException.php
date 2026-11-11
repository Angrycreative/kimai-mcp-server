<?php

declare(strict_types=1);

namespace KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\Exception;

use InvalidArgumentException;

final class ParserException extends InvalidArgumentException implements ReflectionDocblockException
{
    public static function from(\KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Parser\ParserException $exception): self
    {
        return new self(
            'Failed to parse docblock: ' . $exception->getMessage(),
            0,
            $exception
        );
    }
}

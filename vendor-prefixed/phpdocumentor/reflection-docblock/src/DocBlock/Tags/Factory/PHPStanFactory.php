<?php

declare(strict_types=1);

namespace KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\Types\Context;
use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;

interface PHPStanFactory
{
    public function create(PhpDocTagNode $node, Context $context): Tag;

    public function supports(PhpDocTagNode $node, Context $context): bool;
}

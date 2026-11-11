<?php

declare(strict_types=1);

namespace KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Throws;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\TypeResolver;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\Types\Context;
use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use KimaiPlugin\McpServerBundle\Vendor\Webmozart\Assert\Assert;

use function is_string;

/**
 * @internal This class is not part of the BC promise of this library.
 */
final class ThrowsFactory implements PHPStanFactory
{
    private DescriptionFactory $descriptionFactory;
    private TypeResolver $typeResolver;

    public function __construct(TypeResolver $typeResolver, DescriptionFactory $descriptionFactory)
    {
        $this->descriptionFactory = $descriptionFactory;
        $this->typeResolver = $typeResolver;
    }

    public function create(PhpDocTagNode $node, Context $context): Tag
    {
        $tagValue = $node->value;
        Assert::isInstanceOf($tagValue, ThrowsTagValueNode::class);

        $description = $tagValue->getAttribute('description');
        if (is_string($description) === false) {
            $description = $tagValue->description;
        }

        return new Throws(
            $this->typeResolver->createType($tagValue->type, $context),
            $this->descriptionFactory->create($description, $context)
        );
    }

    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof ThrowsTagValueNode;
    }
}

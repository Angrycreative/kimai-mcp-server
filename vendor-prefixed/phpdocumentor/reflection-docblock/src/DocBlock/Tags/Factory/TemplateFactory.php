<?php

declare(strict_types=1);

namespace KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tag;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\DocBlock\Tags\Template;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\TypeResolver;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\Types\Context;
use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use KimaiPlugin\McpServerBundle\Vendor\Webmozart\Assert\Assert;

use function is_string;

/**
 * @internal This class is not part of the BC promise of this library.
 */
final class TemplateFactory implements PHPStanFactory
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

        Assert::isInstanceOf($tagValue, TemplateTagValueNode::class);
        $name = $tagValue->name;

        $description = $tagValue->getAttribute('description');
        if (is_string($description) === false) {
            $description = $tagValue->description;
        }

        return new Template(
            $name,
            $this->typeResolver->createType($tagValue->bound, $context),
            $this->typeResolver->createType($tagValue->default, $context),
            $this->descriptionFactory->create($description, $context)
        );
    }

    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof TemplateTagValueNode && $node->name === '@template';
    }
}

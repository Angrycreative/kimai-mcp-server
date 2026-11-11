<?php

declare(strict_types=1);

namespace KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\PseudoTypes;

use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\PseudoType;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\Type;
use KimaiPlugin\McpServerBundle\Vendor\phpDocumentor\Reflection\Types\Object_;

use function implode;

/** @psalm-immutable */
final class ObjectShape extends Object_ implements PseudoType
{
    /** @var ObjectShapeItem[] */
    private $items;

    public function __construct(ObjectShapeItem ...$items)
    {
        $this->items = $items;
    }

    /**
     * @return ObjectShapeItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function underlyingType(): Type
    {
        return new Object_();
    }

    public function __toString(): string
    {
        return 'object{' . implode(', ', $this->items) . '}';
    }
}

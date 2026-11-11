<?php declare(strict_types = 1);

namespace KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\NodeVisitor;

use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\Attribute;
use KimaiPlugin\McpServerBundle\Vendor\PHPStan\PhpDocParser\Ast\Node;

final class CloningVisitor extends AbstractNodeVisitor
{

	public function enterNode(Node $originalNode): Node
	{
		$node = clone $originalNode;
		$node->setAttribute(Attribute::ORIGINAL_NODE, $originalNode);

		return $node;
	}

}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Tool;

use KimaiPlugin\McpServerBundle\Tool\AbstractWriteTool;
use KimaiPlugin\McpServerBundle\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every tool in this bundle promises clients: a unique snake_case name, a description written for the model,
 * and an object schema that refuses arguments it does not know.
 */
class ToolContractTest extends TestCase
{
    /**
     * @return iterable<string, array{ToolInterface}>
     */
    public static function tools(): iterable
    {
        $files = glob(\dirname(__DIR__, 2) . '/Tool/*Tool.php') ?: [];
        // the planner tools load only where PlannerBundle is installed
        if (class_exists('KimaiPlugin\\PlannerBundle\\Entity\\PlannedActivity')) {
            $files = [...$files, ...(glob(\dirname(__DIR__, 2) . '/Planner/*Tool.php') ?: [])];
        }
        foreach ($files as $file) {
            $class = 'KimaiPlugin\\McpServerBundle\\' . basename(\dirname($file)) . '\\' . basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(ToolInterface::class)) {
                continue;
            }
            /** @var ToolInterface $tool */
            $tool = $reflection->newInstanceWithoutConstructor();
            yield $tool->getName() => [$tool];
        }
    }

    #[DataProvider('tools')]
    public function testDescribesItselfForTheModel(ToolInterface $tool): void
    {
        $this->assertMatchesRegularExpression('/^[a-z][a-z_]*[a-z]$/', $tool->getName());
        $this->assertGreaterThan(60, \strlen($tool->getDescription()));
        $this->assertStringNotContainsString('—', $tool->getDescription());
    }

    #[DataProvider('tools')]
    public function testSchemaIsAClosedObjectWithDescribedProperties(ToolInterface $tool): void
    {
        $schema = $tool->getInputSchema();

        $this->assertSame('object', $schema['type']);
        $properties = (array) $schema['properties'];
        foreach ($properties as $name => $property) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', (string) $name);
            $this->assertIsArray($property);
            if ($name !== 'limit' && $name !== 'page') {
                $this->assertNotEmpty($property['description'] ?? '', $tool->getName() . '.' . $name . ' has no description');
            }
        }
        foreach ($schema['required'] ?? [] as $required) {
            $this->assertArrayHasKey($required, $properties);
        }
        if ($properties !== []) {
            $this->assertFalse($schema['additionalProperties'] ?? true, $tool->getName() . ' accepts unknown arguments');
        }
    }

    #[DataProvider('tools')]
    public function testWriteToolsDemandConfirmation(ToolInterface $tool): void
    {
        if ($tool->isReadOnly()) {
            $this->addToAssertionCount(1);

            return;
        }

        $schema = $tool->getInputSchema();
        $this->assertContains(AbstractWriteTool::CONFIRM, $schema['required'] ?? []);
        $this->assertSame('boolean', ((array) $schema['properties'])[AbstractWriteTool::CONFIRM]['type'] ?? null);
    }

    public function testNamesAreUnique(): void
    {
        $names = array_keys(iterator_to_array(self::tools()));

        $this->assertSame($names, array_unique($names));
        $this->assertContains('whoami', $names);
        $this->assertGreaterThanOrEqual(17, \count($names));
        $this->assertContains('log_absence', $names);
    }
}

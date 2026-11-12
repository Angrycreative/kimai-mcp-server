<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tests\Tool;

use App\Entity\User;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Tool\AbstractWriteTool;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\McpServerBundle\Tool\WritePlan;
use PHPUnit\Framework\TestCase;

class AbstractWriteToolTest extends TestCase
{
    private function tool(int &$applied, string &$state = 'two plannings'): AbstractWriteTool
    {
        return new class($applied, $state) extends AbstractWriteTool {
            public function __construct(private int &$applied, private string &$state)
            {
            }

            public function getName(): string
            {
                return 'test_write';
            }

            public function getDescription(): string
            {
                return 'A write tool for the test.';
            }

            public function getRequiredPermissions(): array
            {
                return [];
            }

            protected function getArgumentSchema(): array
            {
                return Schema::object(['name' => ['type' => 'string', 'description' => 'A name']], ['name']);
            }

            protected function plan(array $arguments, User $user): WritePlan
            {
                if (($arguments['name'] ?? '') === 'bad') {
                    throw new ToolException('Refused before any preview.');
                }

                return new WritePlan(['rename_to' => $arguments['name'], 'state' => $this->state], function (): array {
                    ++$this->applied;

                    return ['renamed' => true, ToolHandlerAdapter::CHANGED => ['thing:1']];
                });
            }
        };
    }

    public function testSchemaRequiresConfirmation(): void
    {
        $applied = 0;
        $schema = $this->tool($applied)->getInputSchema();

        $this->assertSame(['name', 'user_confirmed'], $schema['required']);
        $this->assertSame('boolean', $schema['properties']['user_confirmed']['type']);
        $this->assertFalse($this->tool($applied)->isReadOnly());
    }

    public function testWithoutConfirmationOnlyPreviews(): void
    {
        $applied = 0;
        $tool = $this->tool($applied);

        foreach ([['name' => 'x'], ['name' => 'x', 'user_confirmed' => false], ['name' => 'x', 'user_confirmed' => 'true']] as $arguments) {
            $result = $tool->call($arguments, new User());
            $this->assertTrue($result['preview']);
            $this->assertSame(['rename_to' => 'x', 'state' => 'two plannings'], $result['would']);
            $this->assertIsString($result['preview_token']);
        }
        $this->assertSame(0, $applied);
    }

    public function testConfirmationWithThePreviewsTokenApplies(): void
    {
        $applied = 0;
        $tool = $this->tool($applied);
        $token = $tool->call(['name' => 'x'], new User())['preview_token'];

        $result = $tool->call(['name' => 'x', 'user_confirmed' => true, 'preview_token' => $token], new User());

        $this->assertSame(1, $applied);
        $this->assertTrue($result['done']);
        $this->assertSame(['thing:1'], $result[ToolHandlerAdapter::CHANGED]);
    }

    public function testConfirmationWithoutAPreviewOnlyPreviews(): void
    {
        $applied = 0;
        $result = $this->tool($applied)->call(['name' => 'x', 'user_confirmed' => true], new User());

        $this->assertSame(0, $applied);
        $this->assertTrue($result['preview']);
        $this->assertStringContainsString('needs the preview_token', $result['not_done']);
    }

    public function testAChangeSinceThePreviewIsPreviewedAgainNotApplied(): void
    {
        $applied = 0;
        $state = 'two plannings';
        $tool = $this->tool($applied, $state);
        $token = $tool->call(['name' => 'x'], new User())['preview_token'];

        $state = 'three plannings';
        $result = $tool->call(['name' => 'x', 'user_confirmed' => true, 'preview_token' => $token], new User());

        $this->assertSame(0, $applied);
        $this->assertSame('three plannings', $result['would']['state']);
        $this->assertStringContainsString('no longer the change that was previewed', $result['not_done']);
    }

    public function testATokenIsForTheseArgumentsOnly(): void
    {
        $applied = 0;
        $tool = $this->tool($applied);
        $token = $tool->call(['name' => 'x'], new User())['preview_token'];

        $result = $tool->call(['name' => 'y', 'user_confirmed' => true, 'preview_token' => $token], new User());

        $this->assertSame(0, $applied);
        $this->assertTrue($result['preview']);
    }

    public function testRefusalHappensBeforeThePreview(): void
    {
        $applied = 0;
        $this->expectException(ToolException::class);
        $this->tool($applied)->call(['name' => 'bad', 'user_confirmed' => true], new User());
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Tool;

use App\Entity\User;

/**
 * A tool that changes data. The server, not the client, enforces the confirmation rule: called without
 * user_confirmed: true the tool changes nothing and returns a preview of exactly what would happen. Everything is
 * checked before the preview (permissions, Kimai's own validation), so a preview that reads fine is a change that
 * will go through.
 *
 * The preview carries a token for that exact change. A confirmation saves only with the token, and only while the
 * change worked out again is still the one previewed: if someone changed the data in between (a planning added to
 * the week being cleared, say), the person gets the new preview instead of a change they never saw. The token is a
 * digest, not a secret: it stops a confirmation that skipped the preview or outlived it, not a client that forges it.
 */
abstract class AbstractWriteTool implements ToolInterface
{
    public const CONFIRM = 'user_confirmed';
    public const TOKEN = 'preview_token';

    final public function isReadOnly(): bool
    {
        return false;
    }

    final public function getInputSchema(): array
    {
        $schema = $this->getArgumentSchema();
        /** @var array<string, mixed> $properties */
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $properties[self::CONFIRM] = [
            'type' => 'boolean',
            'description' => 'Set to true only when the person asked for this exact change, or said yes when it was '
                . 'described to them. Otherwise leave it false: nothing changes and you get a preview to describe '
                . 'to them first.',
        ];
        $properties[self::TOKEN] = [
            'type' => 'string',
            'description' => 'With user_confirmed: true, the preview_token the preview of this exact change returned.',
        ];
        $schema['properties'] = $properties;
        /** @var list<string> $required */
        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $schema['required'] = array_values(array_unique([...$required, self::CONFIRM]));

        return $schema;
    }

    final public function call(array $arguments, User $user): array
    {
        $plan = $this->plan($arguments, $user);
        $token = $this->token($plan, $arguments, $user);

        if (($arguments[self::CONFIRM] ?? false) !== true) {
            return $this->preview($plan, $token);
        }

        $given = $arguments[self::TOKEN] ?? null;
        if ($given === null || $given === '') {
            return $this->preview($plan, $token, 'Nothing has changed: a confirmation needs the preview_token of the preview the person agreed to.');
        }
        if (!\is_string($given) || !hash_equals($token, $given)) {
            return $this->preview($plan, $token, 'Nothing has changed: this is no longer the change that was previewed, because the data or the arguments changed since. Describe this new preview to the person.');
        }

        return ['done' => true] + $plan->apply();
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(WritePlan $plan, string $token, ?string $reason = null): array
    {
        $preview = ['preview' => true];
        if ($reason !== null) {
            $preview['not_done'] = $reason;
        }

        return $preview + [
            'would' => $plan->summary,
            'preview_token' => $token,
            'next' => 'Nothing has changed. Describe this to the person and ask them to confirm. Call again with '
                . 'the same arguments, user_confirmed: true and this preview_token only if they say yes.',
        ];
    }

    /**
     * A digest of who asks, which tool, the arguments and the change worked out from them.
     *
     * @param array<string, mixed> $arguments
     */
    private function token(WritePlan $plan, array $arguments, User $user): string
    {
        unset($arguments[self::CONFIRM], $arguments[self::TOKEN]);
        ksort($arguments);
        $subject = json_encode([$this->getName(), $user->getId(), $arguments, $plan->summary], \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return substr(hash('sha256', (string) $subject), 0, 16);
    }

    /**
     * The arguments this tool takes, without user_confirmed and preview_token, which are added for every write tool.
     *
     * @return array<string, mixed>
     */
    abstract protected function getArgumentSchema(): array;

    /**
     * Checks everything and works out the change, without making it.
     *
     * @param array<string, mixed> $arguments
     * @throws ToolException
     */
    abstract protected function plan(array $arguments, User $user): WritePlan;
}

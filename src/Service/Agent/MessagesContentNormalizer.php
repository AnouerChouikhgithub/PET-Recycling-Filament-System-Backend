<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Shared formatting of messages in the Anthropic Messages API block shape:
 *
 *   ['role' => 'user'|'assistant', 'content' => [['type' => 'text', 'text' => ...]]]
 *   ['role' => 'tool_result', 'content' => [['type' => 'tool_result', 'content' => [...]]]]
 *
 * Tool results are ALWAYS JSON-encoded INSIDE a tool_result block - never
 * interpolated into text (docs/ai-agent-risks.md R1).
 */
final class MessagesContentNormalizer
{    /** @return array<string, mixed> */
    public static function userText(string $text): array
    {
        return ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /** @return array<string, mixed> */
    public static function assistantText(string $text): array
    {
        return ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * @param array<string, mixed> $result tool result payload as-is
     *
     * @return array<string, mixed>
     */
    public static function toolResult(string $id, string $name, array $result): array
    {
        return self::toolResults([['id' => $id, 'name' => $name, 'result' => $result]]);
    }

    /**
     * Build one internal result message for every tool call in a provider
     * response, preserving call order as required by the Messages API.
     *
     * @param list<array{id: string, name: string, result: array<string, mixed>}> $results
     *
     * @return array<string, mixed>
     */
    public static function toolResults(array $results): array
    {
        return ['role' => 'tool_result', 'content' => array_map(
            static fn (array $item): array => [
                'type' => 'tool_result',
                'tool_use_id' => $item['id'],
                'name' => $item['name'],
                'content' => $item['result'],
            ],
            $results,
        )];
    }

    /**
     * @param array<string, mixed> $message
     */
    public static function textOf(array $message): string
    {
        $text = '';
        foreach ($message['content'] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null)) {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        return $text;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Transport seam to the LLM. The default environment binds this alias to
 * AnthropicClient (real HTTP); the test environment rebinds it to
 * ScriptedStubLlmClient in config/packages/test/services.yaml so the suite
 * NEVER performs a live request. Rebinding the alias changes nothing else.
 *
 * Message shape (INTERNAL orchestration format, identical for every
 * implementation - AnthropicClient translates it to the provider wire
 * format, where system is a top-level parameter and tool results ride in
 * the following user turn):
 *
 *   ['role' => 'system'|'user'|'assistant', 'content' => string|content-blocks]
 *   ['role' => 'tool_result', 'content' => [tool_result block]]  (JSON data inside)
 *
 * Tool definitions carry name + description + input_schema.
 */
interface LlmClientInterface
{
    /**
     * Run one model turn.
     *
     * @param list<array<string, mixed>> $messages internal orchestration turns
     * @param list<array<string, mixed>> $tools    tool definitions
     *
     * @return array<string, mixed> response with keys: role, content blocks
     *                              (text / tool_use), usage{input,output}
     */
    public function complete(array $messages, array $tools): array;
}

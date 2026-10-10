<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * Transport seam to the LLM. Phase 1 binds the alias to
 * ScriptedStubLlmClient; the real Anthropic HTTP client arrives in a later
 * session and rebinds ONLY services.yaml - nothing else changes.
 */
interface LlmClientInterface
{
    /**
     * Run one model turn.
     *
     * @param list<array<string, mixed>> $messages  conversation turns (same
     *                                              shape as the Anthropic
     *                                              Messages API)
     * @param list<array<string, mixed>> $tools     tool definitions
     *
     * @return array<string, mixed> response with keys: role, content blocks
     *                              (text / tool_use), usage{input,output}
     */
    public function complete(array $messages, array $tools): array;
}

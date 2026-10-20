<?php

declare(strict_types=1);

namespace App\Service\Agent;

/**
 * A single LLM tool: name, JSON schema for the model, and a server-side
 * executor. Tools NEVER trust user-controlled identity - they resolve
 * machines through the existing MachineAccess service - and NEVER dispatch
 * machine commands (phase 1 is read-only; docs/ai-agent-test-plan.md T4.*).
 */
interface AgentTool
{
    /** Stable identifier sent to the model. */
    public function name(): string;

    /** Short description shown to the model. */
    public function description(): string;

    /**
     * JSON schema of the input (always additionalProperties: false).
     *
     * @return array<string, mixed>
     */
    public function inputSchema(): array;

    /**
     * Execute server-side. MUST return a serializable envelope and never
     * throw into the LLM loop: {ok: true, data: ...} or
     * {ok: false, code: ...}.
     *
     * @param array<string, mixed> $input validated input
     *
     * @return array<string, mixed>
     */
    public function execute(array $input): array;
}

# AI Agent - Phased Implementation Plan (docs only)

Small, reviewable steps; each ends with stated acceptance criteria. Nothing
here is implemented yet on this branch. Backend steps come first; each web /
mobile step depends only on the backend contract, so they can be reviewed in
parallel. Claims marked Implemented / Prepared / Future per the session rule:
this document itself is "Prepared" design; all commits here are docs-only.

## Phase 0 - Guardrails before any agent code

B0. Backend: AgentLlmClientInterface + stub implementation + AGENT_ENABLED
    flag + POST /api/agent/chat returning 404 AGENT_DISABLED.
    Acceptance: flag-off build green (phpstan, phpunit untouched suite);
    curl the route -> 404 envelope; no env key required to run tests.
B1. Backend: Migration M1 (agent_conversation, agent_message) + entities.
    Acceptance: doctrine:migrations:migrate on a scratch DB;
    doctrine:schema:validate green; entities are inert (no route writes yet).
B2. Backend: TurnGuard (rate limit + daily token cap) with unit tests.
    Acceptance: T3.1-T3.4 pass with the stub; caps read from env.

## Phase 1 - Read-only agent (backend)

B3. AnthropicClient (real HTTP, env model/key) behind the same interface +
    timeouts + one-retry rule. Acceptance: contract test against a fake
    AGENT_BASE_URL (wiremock-style local server) proves sent headers, model
    read from env, no key in any log at debug level.
B4. ToolRegistry + the five read-only tools + FaultCodeTable, each calling
    MachineAccess/MachineQueryService/AuditRepository as specified in
    ai-agent-design.md section 4. Acceptance: T4.1, T4.2, T1.1-T1.3,
    T2.3, T5.1-T5.3 green.
B5. AgentOrchestrator loop (context build, 4-round cap, persistence) +
    non-streaming JSON endpoint. Acceptance: T2.1-T2.4, T1.4, T1.5 and
    envelope-shape tests green; conversation rows written with usage.
B6. SSE streaming + ping + disconnect handling. Acceptance: T6.1-T6.4.
B7. Delete endpoint + prune command + monolog agent channel (metadata-only).
    Acceptance: T7.1-T7.3; grep for content markers in var/log stays empty.
B8. Docs: update docs/api-contract.md with the new endpoint + env vars in
    .env.example placeholders (no real values). Acceptance: contract doc
    updated in the same PR as the code, per its own rule.

Phase 1 acceptance as a whole: a user can chat about their machines only;
every test T1-T7 passes; no write tool exists; audit/guard/MQTT code
diff-vs-main shows zero changes.

## Phase 2 - Confirmed actions

C1. Backend: agent_pending_action migration M2 + proposal channel in the
    model contract + pending_action frame + expiry sweep.
    Acceptance: T4.3 written and green (range equality with
    MachineCommandGuard, incl. COMMAND_HEATER_MAX_C); expiry sweep test.
C2. Backend: confirm/cancel visibility - pending rows exposed as DATA to the
    next turn; audit attribution via the optional X-Agent-Action header.
    Acceptance: dispatch of a confirmed action goes through the UNCHANGED
    endpoint (watchdog test: context building includes existing
    machine_command_audit rows only after a real 202).
C3. Web: PendingCard + confirm wiring in AgentChatPage.
    Acceptance: confirm calls the normal command endpoint; card terminal
    states; no other diff to existing pages.
C4. Mobile: same, on AgentChatScreen.
    Acceptance: ditto; AppState pause handles the card correctly.

## Phase 1 UI (parallel track, after B6)

W1. Web: api/agent.ts SSE reader + AgentChatPage skeleton (thread, error
    states, rate-limit states). Acceptance: manual smoke against a stubbed
    backend set; states table of docs/ai-agent-ui.md rendered per case.
W2. Web: ToolPill + StaleBanner. Acceptance: stale fixture shows banner;
    pill states per section 3.
M1. Mobile: non-streaming turn (primary path) + FlatList thread + error
    states. Acceptance: smoke on iOS/Android simulators; AppState pause.
M2. Mobile: ToolPill + banner. Acceptance: as W2.

## Explicit deferrals (Future)

- Real streaming on RN (transport-limited), WebSocket/SSE hub reuse for
  chat, conversation titles via a dedicated model call, admin analytics,
  vector/RAG over docs, voice input. None of them weaken the constraints.

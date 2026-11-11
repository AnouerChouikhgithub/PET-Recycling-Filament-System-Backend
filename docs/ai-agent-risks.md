# AI Agent Threat Model (docs only)

Scope: the agent surface (POST /api/agent/chat, tool loop, conversation
storage) and its interaction with the existing machine platform. Threats are
listed with mitigation + covering test (see docs/ai-agent-test-plan.md).

## R1 Prompt injection from untrusted data

Threat: telemetry text, machine names, event payloads, fault codes, or
cached chat content carry directives ("ignore instructions", fake tool
results, commands) that the model treats as instructions. Impact: unwanted
tool calls, disclosure of rules, or the model relaying false state.

Mitigations (layered):
- Tool results are returned ONLY as tool_result content blocks (data), never
  spliced into the system prompt or assistant text; untrusted strings are
  JSON-encoded, never concatenated.
- Tool payloads pass server-side schema validation first; fault codes are
  exact-matched against a static ASCII table (FaultCodeTable).
- Phase-1 registry has no write tool, so even a successful injection has no
  actuator reach: unknown tool names are rejected by the loop.
- System prompt rule 3/4 harden the model's behavior (defense in depth,
  never the primary control).
- Constants: Injection is realistic here - machine identifiers and names are
  operator-editable and MQTT payloads cross the broker.

Covers: T2.1-T2.4. Residual: the model may echo injected text as content;
acceptable, since text has no authority and no write tool exists.

## R2 Data leakage between users

Threat: user B's agent turn reads machines, telemetry, audits, or
conversations of user A; enumeration of machine UUIDs.

Mitigations:
- Every tool resolves machines through the existing MachineAccess (same 404
  for unknown/foreign ids) - enforced in code, verified by test, not in the
  prompt. list_my_machines uses visibleMachines(); get_audit_log is a
  user-scoped query.
- Conversations are user-scoped with FK + owner check; foreign conversation
  id = AGENT_NOT_FOUND.
- Context building includes ONLY the current conversation's messages.

Covers: T1.1-T1.5. Residual: none known; admins do see all machines, which
matches existing platform behavior and is not a leak.

## R3 Cost abuse

Threat: a user (or a compromised client) drives unlimited LLM calls /
oversized contexts to burn budget (Anthropic bill), possibly with scripted
clients hammering the endpoint.

Mitigations:
- 20 messages / 5 min / user (429 AGENT_RATE_LIMITED, keyed per USER not IP
  so NAT workplaces are not punished jointly and one stolen token cannot
  multiply by IP rotation).
- Per-user per-UTC-day token cap (AGENT_DAILY_TOKEN_CAP, default 200k) with
  usage counted even when a turn fails; missing usage counted conservatively
  (1k) so payload changes cannot silently lift the cap.
- Message 1..4000 chars, context = last 12 messages, max 4 tool rounds,
  max_tokens 1024 => bounded worst case per turn.
- No key in any client; a client-side "free rider" cannot call the model at
  all.

Covers: T3.1-T3.4.

## R4 Hallucinated machine state

Threat: the model asserts "heater is off / motor stopped / temperature is X"
without basis, or converts null channels into zeros; user acts on it.

Mitigations:
- Tool contract: channels with null values arrive as literal "Not reported";
  every row carries recordedAt + dataAgeSeconds and staleness labels
  ("stale" / "very stale, telemetry missing") the model must quote.
- System prompt rules 1-2 forbid present-tense physical claims without a
  fresh timestamp; refusal wording is prescribed.
- Command "acceptance" is never described by the agent as execution,
  matching the platform's honesty rules (delivery vs execution).

Covers: T5.1-T5.3. Residual: model wording is probabilistic; deterministic
controls live in the tool payload, which is testable.

## R5 Key exposure

Threat: ANTHROPIC_API_KEY leaks to web/mobile bundles, docs, logs, error
envelopes (500 details), or VCS.

Mitigations:
- Key read from env (.env.local / process env only), never in code,
  never in .env.example with a real value, never in any committed doc.
- The key crosses only the backend-to-Anthropic HTTPS call.
- Error responses: internal details never exposed (existing envelope rule);
  agent logs are metadata-only.
- Frontends have no model URL and no key by design; mobile uses the same
  API endpoint as web.

Covers: T4.1 (registry/tool-list lock), T7.3 (log hygiene), plus manual
review item: grep the repo for the key prefix before each release.

## R6 Logging private content

Threat: user chat text, machine names, or telemetry content land in log
files (dev stream / prod stderr JSON), then to any log sink.

Mitigations:
- /api/agent/* logging is metadata-only: route, user id, conversation id,
  duration, error codes - no request/response bodies, no LLM payloads.
- Monolog: dedicated `agent` channel with a metadata formatter; existing
  HTTP-level logging excludes this route.
- Conversations live in the DB (per-user, deleted on request), retention
  AGENT_RETENTION_DAYS with a prune command + delete endpoint.

Covers: T7.3 (+ T7.1/T7.2 for retention correctness).

## Explicitly out of scope (unchanged by design)

- The agent is not a safety layer; do not justify any write-path change by
  "the agent would catch it". MachineCommandGuard stays the only gate.
- Firmware may still refuse commands; agent claims about execution remain
  forbidden forever (see R4).

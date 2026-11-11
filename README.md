# 3awedlou — Backend (Symfony API)

Shared backend for the **3awedlou** plastic-recycling machine ecosystem. It
serves the React web app and the React Native / Expo mobile app — one API, one
JWT scheme, one contract (this README is that contract).

```text
             ┌─────────────────┐
             │ React Web App   │
             └────────┬────────┘
                      │  REST + JWT (HTTPS)
                      ▼
             ┌─────────────────┐
             │  Symfony API    │  this repository
             └────────┬────────┘
                      │
        ┌────────────┼──────────────┐
        ▼            ▼              ▼
   PostgreSQL     MQTT / IoT      Realtime seam
   (Doctrine)     (prepared)      (logging only)
                      │
                      ▼
                   ESP32 ── Arduino Mega ── 3awedlou machine
```

**Scope:** authentication, machine/telemetry/session domain, read API,
telemetry **ingestion**, guarded machine **commands** (MQTT-ready), offline
detection, a read-only **AI assistant**, and a realtime fan-out seam.

## Status

`Implemented` = code plus a passing automated test in this repository.
`Prepared` = code exists but is only exercised by fakes/mocks, or its transport
has never run live. `Future` = not implemented.

| Area | State | Evidence / limit |
|---|---|---|
| Auth (register, login, refresh, logout) | Implemented | `tests/Api/AuthApiTest.php`, `RefreshTokenFlowTest.php` |
| Machines, dashboard, telemetry reads, sessions, production, recycling | Implemented | `tests/Api/MachineApiTest.php`, `SecurityIsolationTest.php` |
| Telemetry ingestion (`TelemetryProcessor`, HTTP) | Implemented | `tests/Api/IotApiTest.php`, `MachineApiTest.php` |
| Guarded machine commands + audit trail | Implemented | `tests/Api/OwnershipAndAuditTest.php`, `MachineApiTest.php` |
| AI assistant (read-only, 5 tools) | Prepared | Functionally tested with a scripted stub (`tests/Agent/*`); **no live Anthropic call has ever run** |
| MQTT publisher and consumer | Prepared | Fake-broker tests only; real-broker tests skip without `MQTT_TEST_HOST` |
| Local Mosquitto broker + per-device ACL | Prepared | Dev-only, plaintext loopback, ACL never verified live |
| Device acknowledgement of commands | Prepared | `commandId`/`expiresAt` on the wire; `deviceAcknowledged` stays `false` |
| Device-originated events | Prepared | Validated + logged, no side effects |
| Realtime push transport | Future | `RealtimeBroadcaster` logs only; clients poll honestly every 10 s |
| Web scaffold (AssetMapper/Twig/Stimulus) | Prepared | Dev-only scaffold, not used by the JSON API |
| ESP32 firmware, TLS broker, deployment | Future | Not in this repository |

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.3+** | extensions: `pdo_pgsql`, `sodium`, `openssl`, `mbstring` |
| Composer | 2.10 | |
| Symfony | **7.4 LTS** | pinned as `symfony/*: 7.4.*` |
| PostgreSQL | **17** | any ≥ 14 works; tests use a separate `db_3awedlou_test` schema |
| Docker | optional | dev database and Mosquitto broker |

## Fresh-clone setup

```bash
cp .env.example .env          # .env is git-ignored; it has never been tracked since cleanup
composer install              # runs cache:clear, assets:install, importmap:install
```

Generate JWT keys once (git-ignored under `config/jwt/`):

```bash
php bin/console lexik:jwt:generate-keypair
```

Start PostgreSQL and apply migrations:

```bash
docker compose up -d                        # postgres:17 on 127.0.0.1:5432
composer db:setup                           # create + migrate dev AND test databases
php bin/console doctrine:fixtures:load      # optional dev fixtures, wipes the DB
```

Run the API:

```bash
symfony serve -d
# or: php -S 0.0.0.0:8000 -t public
```

API base URL: `http://127.0.0.1:8000/api`. Bind `0.0.0.0` when a phone on the
LAN must reach it.

### The `symfony serve` DATABASE_URL trap

`symfony serve` can inject its own `DATABASE_URL` and point the app at a second
PostgreSQL container. `bash bin/dev-check` / `.\bin\dev-check.ps1` prints the
database the app actually resolves, warns about multiple postgres containers and
exits non-zero on pending migrations, mapping drift or missing JWT keys.

### Dev fixtures

`php bin/console doctrine:fixtures:load` loads 2 users, 2 machines, telemetry
samples, sessions, filament batches and recycling records. Local-only accounts:
`anouer@3awedlou.app` and `sara@3awedlou.app`, password `3awedlou-dev`
(`AppFixtures::DEV_PASSWORD`). Never load fixtures in production.

## Environment variables

Tracked templates hold placeholders only. Real values belong in `.env` /
`.env.local` (both git-ignored) or the process environment.

| Variable | Required | Default | Meaning |
|---|---|---|---|
| `APP_ENV` | no | `dev` | `dev` \| `prod` \| `test` |
| `APP_SECRET` | yes | — | framework secret (`php bin/console secret:generate`) |
| `APP_SHARE_DIR` | no | `var/share` | shared directory for framework files |
| `DATABASE_URL` | yes | local PostgreSQL DSN | dev/test database |
| `JWT_SECRET_KEY` / `JWT_PUBLIC_KEY` | yes | `config/jwt/*.pem` | RS256 key paths (git-ignored) |
| `JWT_PASSPHRASE` | no | empty in dev | key passphrase |
| `CORS_ALLOW_ORIGIN` | no | localhost regex | allowed browser origins |
| `AUTH_REGISTER_ENABLED` | no | `true` | `false` closes open registration |
| `MACHINE_OFFLINE_AFTER_MINUTES` | no | `10` | derived-offline threshold |
| `COMMAND_HEATER_MAX_C` | no | `260` | backend heater safety cap (°C) |
| `COMMAND_MOTOR_MAX` | no | `100` | backend motor cap (%) |
| `COMMAND_FAN_MAX` | no | `100` | backend fan cap (%) |
| `AGENT_ENABLED` | no | `false` | `false` = every `/api/agent` route answers 404 `AGENT_DISABLED` |
| `ANTHROPIC_API_KEY` | only for live answers | — | **secret**; `.env.local`/process env only |
| `AGENT_MODEL` | no | `claude-sonnet-4-5` | model name from env, never hard-coded |
| `AGENT_BASE_URL` | no | empty | provider/proxy override |
| `AGENT_MAX_TOKENS` | no | `1024` | per model response |
| `AGENT_MAX_TOOL_ROUNDS` | no | `4` | model rounds per turn (one response = one round) |
| `AGENT_CONTEXT_MESSAGES` | no | `12` | transcript window |
| `AGENT_RATE_LIMIT_MESSAGES` | no | `20` | messages per window per user |
| `AGENT_RATE_LIMIT_INTERVAL_SEC` | no | `300` | window length |
| `AGENT_DAILY_TOKEN_CAP` | no | `200000` | per user per UTC day |
| `AGENT_MSG_MAX_CHARS` | no | `4000` | message length |
| `AGENT_CONNECT_TIMEOUT_SEC` / `AGENT_REQUEST_TIMEOUT_SEC` | no | `5` / `30` | provider timeouts |
| `MQTT_ENABLED` | no | `false` | `true` = real broker publisher |
| `MQTT_HOST` / `MQTT_PORT` | no | `localhost` / `1883` | broker address |
| `MQTT_USERNAME` / `MQTT_PASSWORD` | for a live broker | — | **credentials; `.env.local` only** |
| `MQTT_CLIENT_ID` | no | `3awedlou-backend` | prefix; a random suffix is appended per connection |
| `MQTT_TLS` | no | `false` | deployment must use TLS (8883) |
| `MQTT_PREFIX` | no | `3awedlou` | topic prefix, must match the broker ACL |
| `MQTT_QOS` | no | `1` | outbound QoS and inbound subscription |
| `MQTT_COMMAND_TTL_SECONDS` | no | `30` | command `expiresAt` |
| `MQTT_MAX_PAYLOAD_BYTES` | no | `4096` | inbound payload cap |
| `MQTT_TELEMETRY_MAX_PER_SECOND` | no | `2` | per-machine telemetry budget |

Frontends never receive secrets: web uses `VITE_*`, mobile uses `EXPO_PUBLIC_*`.

## API contract

Base URL `http://127.0.0.1:8000/api` (dev). Mobile on a physical device uses
the developer machine's LAN IP.

### Envelope and errors

```json
{ "success": true, "data": { }, "meta": { "count": 2 } }
```

```json
{
  "success": false,
  "error": {
    "code": "MACHINE_NOT_FOUND",
    "message": "Machine not found.",
    "details": { "field": ["message"] }
  }
}
```

`details` appears only on validation errors. Error codes: `UNAUTHORIZED`,
`INVALID_CREDENTIALS`, `INVALID_TOKEN`, `TOKEN_EXPIRED`, `TOKEN_NOT_FOUND`,
`FORBIDDEN`, `MACHINE_NOT_FOUND`, `VALIDATION_FAILED`, `BAD_REQUEST`, `NOT_FOUND`,
`INTERNAL_ERROR`, `MQTT_UNAVAILABLE` (503), `AGENT_DISABLED` (404),
`AGENT_NOT_FOUND` (404), `AGENT_RATE_LIMITED` (429), `AGENT_DAILY_TOKEN_CAP`
(429), `AGENT_LLM_UNAVAILABLE` (503). Internal 500 details are never exposed.

### Authentication and roles

User routes take `Authorization: Bearer <JWT>` (RS256, 1 h TTL). Telemetry
ingest takes a per-machine `X-Device-Token`; a user JWT is rejected there.
Machine REST routes allow the owner or `ROLE_ADMIN`; the agent is **owner-only**
with no admin bypass. Whether admins may command machines is an open policy
decision.

### Routes

| Method | Path | Auth | Ownership / role | Rate limit |
|---|---|---|---|---|
| POST | `/api/auth/register` | public | — | 3 / 10 min per IP |
| POST | `/api/auth/login` | public | — | 5 / 5 min per IP |
| POST | `/api/auth/token/refresh` | refresh token | token owner | 30 / 5 min per IP |
| POST | `/api/auth/logout` | JWT + refresh token | caller's token only | — |
| GET | `/api/me` | JWT | self | — |
| GET | `/api/machines` | JWT | owner or admin | — |
| GET | `/api/machines/{id}` | JWT | owner or admin | — |
| GET | `/api/machines/{id}/status` | JWT | owner or admin | — |
| GET | `/api/machines/{id}/dashboard` | JWT | owner or admin | — |
| GET | `/api/machines/{id}/telemetry` | JWT | owner or admin | — |
| POST | `/api/machines/{id}/telemetry` | device token | token bound to that machine | — |
| GET | `/api/machines/{id}/sessions` | JWT | owner or admin | — |
| GET | `/api/machines/{id}/production` | JWT | owner or admin | — |
| GET | `/api/machines/{id}/recycling` | JWT | owner or admin | — |
| POST | `/api/machines/{id}/commands` | JWT | owner or admin (policy open) | — |
| POST | `/api/agent/chat` | JWT | **owner only** | 20 / 5 min per user + daily token cap |
| DELETE | `/api/agent/conversations/{id}` | JWT | **owner only** | — |
| DELETE | `/api/agent/conversations` | JWT | **owner only** | — |

No `Cache-Control` streaming endpoints exist; agent answers are non-streaming.

### Machines and telemetry

Machine: `{id, name, identifier, status, reportedStatus, lastSeenAt,
secondsSinceLastSeen, createdAt, updatedAt, owner}`.

Status values: `idle`, `heating`, `extruding`, `paused`, `error`, `offline`.
`offline` is **derived, never stored**: a machine is offline when `lastSeenAt`
is older than `MACHINE_OFFLINE_AFTER_MINUTES`. Devices report only the first five
values; the API returns the effective status in `status` and the raw value in
`reportedStatus`.

Telemetry sample (all channels nullable; any subset is valid):

```json
{
  "id": "uuid", "machineId": "uuid",
  "temperature": 192.4, "targetTemperature": 195.0,
  "heaterState": true, "motorState": true, "motorSpeed": 42, "fanState": true,
  "filamentSpeed": 3.1, "filamentDiameter": 1.75, "energyConsumption": 60.5,
  "extra": { }, "recordedAt": "2026-09-30T01:00:00+00:00"
}
```

`GET /telemetry?limit=&offset=&since=` returns newest first (`limit` ≤ 200,
`since` is ISO-8601; relative or impossible dates → 422).

Ingest one sample as a device:

```bash
curl -X POST http://127.0.0.1:8000/api/machines/{id}/telemetry \
  -H "X-Device-Token: $DEVICE_TOKEN" -H "Content-Type: application/json" \
  -d '{"temperature":192.4,"targetTemperature":195,"heaterState":true,"motorState":true,"motorSpeed":42,"fanState":true,"filamentSpeed":3.1,"filamentDiameter":1.75,"status":"extruding"}'
```

Unknown fields are rejected, ranges enforced, `lastSeenAt` refreshed, and a
reported `status` applied. The HTTP endpoint and the MQTT consumer share the
same `TelemetryProcessor` — one pipeline:

```text
MQTT message or HTTP POST → TelemetryProcessor → validation
  → machine_telemetry + machine.status/lastSeenAt
  → TelemetryProcessedEvent → RealtimeListener → RealtimeBroadcaster (logging)
```

### Sessions, production, recycling

| Method | Path | Query | Returns |
|---|---|---|---|
| GET | `/api/machines/{id}/sessions` | `limit, offset, status` | session list |
| GET | `/api/machines/{id}/production` | `limit, offset` | filament batches |
| GET | `/api/machines/{id}/recycling` | `limit, offset` | `{records, totals}` |

Session: `{id, machineId, operator, startedAt, endedAt, status, materialInput,
materialOutput, durationMinutes, notes}`. Production: `{id, sessionId,
batchCode, diameterTarget, diameterActual, diameterSamples, weightGrams,
lengthMeters, durationMinutes, material, color, colorHex, quality, notes,
producedAt}`. Recycling record: `{id, sessionId, machineId, inputMaterial,
inputMassGrams, outputMaterial, outputMassGrams, durationMinutes,
avgTemperature, notes, recycledAt}`.

### Commands

```json
// request
{ "command": "setTargetTemperature", "value": 195 }
// 202
{ "success": true, "data": {
  "machineId": "…", "identifier": "3awedlou-001", "command": "setTargetTemperature",
  "value": 195, "topic": "3awedlou/machines/3awedlou-001/commands",
  "accepted": true, "commandId": "uuid",
  "delivery": "published_to_broker",
  "deviceAcknowledged": false, "sentAt": "…", "expiresAt": "…" } }
```

Commands: `start` `pause` `resume` `stop` `setTargetTemperature` `setMotorSpeed` `setFan`.

Guard: offline machines accept nothing; `start` only from idle/error; `resume`
only from paused; `pause`/`stop` only while running; numeric values within
`COMMAND_*` limits. Violations → `422 VALIDATION_FAILED`.

`delivery` is `published_to_broker` (the **broker** accepted it) or
`buffered_not_sent` (nothing left the process). `deviceAcknowledged` is always
`false` until firmware acknowledgements exist. **Acceptance ≠ execution** — only
fresh telemetry/status proves what the machine did. Broker publish failure →
`503 MQTT_UNAVAILABLE`.

## MQTT contract

Phase 3 code is wired to a real Mosquitto client when `MQTT_ENABLED=true`; the
default (`false`) uses an in-memory buffering publisher. **No live broker run
has been recorded in this repository**, and there is no ESP32 firmware here.

### Topic tree

Prefix = `MQTT_PREFIX` (default `3awedlou`). The frontend never publishes MQTT:
Web/Mobile → Symfony → MQTT → ESP32.

| Topic | Direction | QoS | Retain | Purpose |
|---|---|---|---|---|
| `{prefix}/machines/{identifier}/telemetry` | device → backend | 1 | no | sensor samples |
| `{prefix}/machines/{identifier}/status` | device → backend | 1 | no | lifecycle `{"online": bool}` incl. Last Will |
| `{prefix}/machines/{identifier}/events` | device → backend | 1 | no | log-worthy events |
| `{prefix}/machines/{identifier}/commands` | backend → device | `MQTT_QOS` | **never** | command envelope |

`{identifier}` is `Machine.identifier`, not the UUID. A retained command would
replay on every reconnect, so retain is forbidden.

### Authentication and ACL

`allow_anonymous false`, `password_file` + `acl_file`
(`docker/mosquitto/mosquitto.conf`), loopback-only `127.0.0.1:1883` in dev.
**The broker username IS the machine identity**:

* device `%u` may write only `{prefix}/machines/%u/{telemetry,status,events}`;
* device `%u` may read only `{prefix}/machines/%u/commands`;
* the backend user `backend` has `readwrite {prefix}/#`.

Credentials are created by `bin/mqtt-user.ps1` / `bin/mqtt-user.sh` into the
git-ignored `docker/mosquitto/passwd`. Never edit that file by hand.

### Payloads

Telemetry uses the same validation as HTTP ingest; unknown fields are rejected,
all channels nullable:

```json
{ "temperature": 192.4, "targetTemperature": 245.0, "heaterState": true,
  "motorState": true, "motorSpeed": 42,
  "recordedAt": "2026-01-01T00:00:00+00:00", "status": "extruding" }
```

`temperature`/`targetTemperature`/`heaterState`/`motorState` are backed by
current hardware. `motorSpeed`, `fanState`, `filamentSpeed`, `filamentDiameter`,
`energyConsumption`, `extra` and `status` are accepted and stored but not
reported by the current hardware. Identity comes from the topic only; a payload
naming another machine is rejected.

Status is exactly `{"online": true|false}`. `online=true` refreshes
`lastSeenAt`; `online=false` (graceful disconnect or Last Will) makes the derived
offline state effective immediately. `offline` is never written to the column.

Events are a non-empty JSON object, optionally with a string `type`; they are
validated and logged metadata-only, with no side effects yet.

Commands:

```json
{ "v": 1, "commandId": "01991b2e-…", "type": "setTargetTemperature",
  "value": 195, "issuedAt": "…", "expiresAt": "…" }
```

`v` is the schema version, `commandId` equals the `machine_command_audit.id`,
`type` is one of the seven commands, `value` is `null` for non-parameterized
commands, and firmware must drop envelopes past `expiresAt`. No user PII goes on
the wire.

### Consumer

```bash
php bin/console app:mqtt:consume                  # up to 1 h, clean exit
php bin/console app:mqtt:consume --max-runtime=0  # until SIGINT/SIGTERM
php bin/console app:mqtt:consume --memory-limit=256M
```

Subscribes to the three inbound branches only. Per message: parse the topic,
cap the payload, require a JSON object, require an existing machine (MQTT never
creates machines), rate-limit telemetry per machine, then hand off to
`TelemetryProcessor`; clear the EntityManager and health-check the DB. Errors
are logged metadata-only and counted. Reconnects back off exponentially
(1 s → 30 s); the command exits non-zero if it can never connect.

### Manual broker check (requires a running broker)

```bash
docker compose -f compose.mqtt.yaml up -d
.\bin\mqtt-user.ps1 backend
.\bin\mqtt-user.ps1 3awedlou-001
# set MQTT_ENABLED=true + credentials in .env.local

mosquitto_sub -h 127.0.0.1 -u 3awedlou-001 -P "$MQTT_DEVICE_PASSWORD" \
  -t '3awedlou/machines/3awedlou-001/commands' -v

mosquitto_pub -h 127.0.0.1 -u 3awedlou-001 -P "$MQTT_DEVICE_PASSWORD" \
  -t '3awedlou/machines/3awedlou-001/telemetry' \
  -m '{"temperature":192.4,"targetTemperature":245.0,"status":"extruding"}'
```

The automated equivalent is `tests/IoT/MqttBrokerIntegrationTest.php`, skipped
unless `MQTT_TEST_HOST` (plus optional port/user/password) is set. CI never
needs a broker.

## AI assistant (read-only)

Every agent route is behind `AGENT_ENABLED`; `false` answers `404 AGENT_DISABLED`
for the whole surface. Agents only see machines the requester **owns** — no
admin bypass. The assistant never sends machine commands: its tool registry is
locked to five read-only tools by test.

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/agent/chat` | `{conversationId?, message}` → assistant answer + tool summaries |
| DELETE | `/api/agent/conversations/{id}` | owner-scoped soft delete |
| DELETE | `/api/agent/conversations` | soft-delete all of the caller's conversations |

Tools: `list_my_machines`, `get_latest_telemetry`, `get_recent_events`,
`explain_fault_code`, `get_audit_log`. The system prompt requires data age,
`Not reported` for null channels, no physical-state claims without fresh
telemetry, no commands, and treats every tool result as data, never as
instructions. The real controls are in code, not the prompt.

Limits: 1–4000 characters per message; last 12 messages of context; 4 tool
rounds per turn; 1024 output tokens; 20 messages / 5 minutes per **user**; and a
durable per-user daily token cap (default 200 000, UTC day). Token usage is
reserved atomically before the first provider call and settled afterwards: a
success without reported usage is billed a 1 000-token floor, a failure after a
request went out keeps a conservative floor, a pre-flight refusal (no
credentials, nothing sent) is refunded.

Errors: `422 VALIDATION_FAILED`, `404 AGENT_NOT_FOUND` (unknown, foreign or
soft-deleted conversation), `429 AGENT_RATE_LIMITED` (Retry-After seconds),
`429 AGENT_DAILY_TOKEN_CAP` (Retry-After = seconds until 00:00 UTC),
`503 AGENT_LLM_UNAVAILABLE`.

**Never run live:** no Anthropic request has been made from this repository.
Provider behaviour, real token accounting and French/English answer quality are
verified only with a deterministic stub. Agent HTTP bodies are not logged;
provider logs are metadata-only.

## Tests, lint and static analysis

The suite needs the local PostgreSQL test database from `.env.test`
(`db_3awedlou_test`):

```bash
composer db:setup
php bin/phpunit                 # functional API + IoT + agent tests
php vendor/bin/phpstan analyse  # static analysis
php bin/console lint:container  # DI wiring
composer validate --no-check-publish
php bin/console debug:router    # route table
```

Current results (2026-11-11): **148 tests, 872 assertions, 2 skipped** (the
skipped tests are the real-broker MQTT integration tests). PHPStan: **no
errors**. `lint:container` and `composer validate` pass. `git diff --check`
is clean.

## Project structure

```text
.
├── bin/                        # console entry + dev-check, check-schema, mqtt-user, phpunit
├── compose.yaml                # dev PostgreSQL
├── compose.mqtt.yaml           # dev Mosquitto (separate file on purpose)
├── config/                     # Symfony config and firewalls
├── docker/                     # initdb SQL + Mosquitto config/acl
├── migrations/                 # Doctrine migrations
├── public/index.php
├── src/
│   ├── Api/                    # envelope, exception/headers subscribers
│   ├── Command/                # device-token, MQTT consumer, telemetry pruning
│   ├── Controller/Api/         # thin HTTP controllers
│   ├── Dto/                    # request DTOs + entity→JSON view factories
│   ├── Entity/                 # Doctrine entities + enums
│   ├── Repository/             # queries
│   ├── Security/               # JWT/device auth, ownership gate
│   └── Service/
│       ├── Agent/              # orchestrator, tools, provider client, limits
│       ├── Auth/
│       ├── IoT/                # MQTT seams, command guard/service, ingest pipeline
│       ├── Machine/            # dashboard/query/connectivity
│       └── Realtime/           # broadcaster seam + listener
├── tests/                      # functional API, agent, IoT tests
├── assets/ templates/          # dev scaffold kept because installed bundles register it
├── .env.example / .env.test    # tracked templates (no secrets)
├── composer.json / README.md
```

`README.md` is the only documentation file; screenshots and media are not part
of this repository.

## Security notes

* Owner/device isolation is enforced by `MachineAccess`; device tokens are
  hashed at rest and honour the machine kill switch.
* JWT is RS256 with a 1 h TTL. Refresh tokens are single-use and hashed; the
  backend can revoke them, but neither client stores or rotates one yet.
* Auth surface is rate-limited per IP (login 5/5 min, register 3/10 min, refresh
  30/5 min). Behind a proxy, configure trusted proxies so `getClientIp()` is
  trustworthy.
* `.env`, `.env.local`, JWT keys and broker passwords are git-ignored. `.env` is
  no longer tracked; rotate anything that existed in Git history.
* Dev broker is plaintext loopback only. Never expose it; deployment requires
  TLS and per-device credentials.
* The agent never logs request/response bodies; provider logs carry metadata
  only.

## Deployment checklist

- `APP_ENV=prod`, strong `APP_SECRET`, production `DATABASE_URL`, migrations run.
- Fresh JWT keypair (or inject key paths/passphrase as environment variables).
- Pin `CORS_ALLOW_ORIGIN` to the real web origin; never `*` with credentials.
- Set `trusted_proxies` for the actual reverse proxy / load balancer.
- Point `cache.app` at a shared store (Redis) before running more than one node —
  the agent rate-limit window is node-local on the filesystem today.
- Broker: TLS on 8883, per-device credentials, no anonymous access, ACL loaded.
- Rotate every secret that was ever committed (including historical `.env` and
  the `.env.test` JWT passphrase) before exposing the service.
- Enable backups and the telemetry pruning command on a schedule.
- Decide the admin-command policy and the refresh-token transport before go-live.

## Not run live (honest list)

No live Anthropic call; no physical device; no real MQTT broker/ACL run; no VPS
or TLS deployment; no browser/device UI verification from this repository;
migrations were applied to the local test database only.

## Related repositories

- Web: https://github.com/AnouerChouikhgithub/PET-Recycling-Filament-System-Web
- Mobile: https://github.com/AnouerChouikhgithub/PET-Recycling-Filament-System-Mobile
- Hardware: https://github.com/AnouerChouikhgithub/pet-recycling-filament-system

## License

TODO(anouer): no LICENSE file exists in this repository — add one.

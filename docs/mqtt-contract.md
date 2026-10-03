# 3awedlou MQTT Contract

Phase 3 — the transport is **wired**: the backend publishes commands through a
real Mosquitto client (`MQTT_ENABLED=true`) and consumes device messages with
`app:mqtt:consume`. With `MQTT_ENABLED=false` (the default, and what tests use)
nothing touches a socket and the historical buffering publisher is used.

**Honesty rules that shape this contract**

* `delivery: published_to_broker` means *the broker accepted the publish* —
  it does **not** mean the machine executed the command. Execution is only
  observable through subsequent telemetry/status.
* `delivery: buffered_not_sent` means *nothing left the process* — the API
  never claims a broker publish that did not happen.
* There is no ESP32 firmware in this repository yet. Every payload below is
  the contract firmware must implement; manual tests stand in with
  `mosquitto_pub` until a device exists.

---

## Topic tree

Prefix configurable via `MQTT_PREFIX` (default `3awedlou`). The frontend never
publishes MQTT — flow is Web/Mobile → Symfony → MQTT → ESP32.

| Topic | Direction | QoS (default) | Retain | Purpose |
|---|---|---|---|---|
| `{prefix}/machines/{identifier}/telemetry` | device → backend | 1 | no | sensor samples |
| `{prefix}/machines/{identifier}/status` | device → backend | 1 | no | lifecycle `{"online": …}` (incl. Last Will) |
| `{prefix}/machines/{identifier}/events` | device → backend | 1 | no | log-worthy events (logged, not acted on) |
| `{prefix}/machines/{identifier}/commands` | backend → device | `MQTT_QOS` | **never** | remote control envelope |

`{identifier}` is `Machine.identifier` (e.g. `3awedlou-001`), **not** the
machine UUID. A retained command would replay on every device reconnect, so
retention is forbidden on all four branches.

## Authentication & ACL (per-device identity)

* Broker: `allow_anonymous false`, `password_file` + `acl_file`
  (`docker/mosquitto/mosquitto.conf`), loopback-only `127.0.0.1:1883` in dev.
* **The username IS the machine identity**: a device's broker username must
  equal its `Machine.identifier`, so Mosquitto's `%u` patterns pin it:
  * `pattern write {prefix}/machines/%u/{telemetry,status,events}` — a device
    can only *write* its own branches; publishing on another machine's topic
    matches no rule and is **denied**.
  * `pattern read {prefix}/machines/%u/commands` — a device only *reads* its
    own command topic.
* The backend user `backend` has `topic readwrite {prefix}/#`.
* Credentials are created by `bin/mqtt-user.ps1` / `bin/mqtt-user.sh` into the
  **git-ignored** `docker/mosquitto/passwd`. Broker passwords never appear in
  committed files or logs.

## Payloads

### Telemetry — `{prefix}/machines/{id}/telemetry`

Validated by the **same** `TelemetryProcessor` as `POST /api/machines/{id}/telemetry`
— one pipeline, no duplicated validation. Unknown fields are rejected; ranges
are enforced; all channels are nullable (any subset is a valid sample).

```json
{
  "temperature": 192.4,
  "targetTemperature": 245.0,
  "heaterState": true,
  "motorState": true,
  "motorSpeed": 42,
  "recordedAt": "2026-01-01T00:00:00+00:00",
  "status": "extruding"
}
```

| Channel | Backed by hardware today? | Notes |
|---|---|---|
| `temperature` | **real** | sensor reading, −10…400 °C |
| `targetTemperature` | **real** | heater setpoint, ≤ 300 °C (safe max) |
| `heaterState` | **real** | heater on/off as reported by the device |
| `motorState` | **real** | motor on/off as reported by the device |
| `motorSpeed` | prepared | accepted + stored; firmware may emit later (0–10000 RPM) |
| `fanState`, `filamentSpeed`, `filamentDiameter`, `energyConsumption`, `extra` | prepared | accepted + stored; not reported by current hardware |
| `status` | prepared | one of the five device lifecycle values, **never** `offline` |
| `recordedAt` | optional | ISO-8601; defaults to ingestion time |

Identity comes from the **topic only** — a payload naming another machine
(e.g. a `machineId` field) is rejected; one device can never write another
machine's data (and the broker ACL would refuse the publish anyway).

### Status — `{prefix}/machines/{id}/status`

Exactly one key, a boolean — anything else is dropped:

```json
{"online": true}
{"online": false}
```

* `online=true` → refresh `lastSeenAt` (same atomic update as telemetry).
* `online=false` → graceful disconnect or **Last Will**: makes the *derived*
  offline state effective immediately instead of waiting
  `MACHINE_OFFLINE_AFTER_MINUTES`. `offline` is never written to the status
  column — it stays derived, exactly as in the HTTP contract.

### Events — `{prefix}/machines/{id}/events`

**Prepared, not acted on.** A non-empty JSON object, optionally with a string
`type`; validated and logged (field names only, never values) — no side
effects anywhere in the system yet:

```json
{"type": "filament_jam", "section": "puller"}
```

### Commands — `{prefix}/machines/{id}/commands`

Published with `MQTT_QOS` (default 1), **retain = false**, envelope versioned:

```json
{
  "v": 1,
  "commandId": "01991b2e-…",       
  "type": "setTargetTemperature",
  "value": 195,
  "issuedAt": "2026-10-03T01:00:00+00:00",
  "expiresAt": "2026-10-03T01:00:30+00:00"
}
```

| Field | Meaning |
|---|---|
| `v` | payload schema version — firmware must ignore envelopes it does not understand |
| `commandId` | correlation id; **equals the `machine_command_audit.id`**, so a future device ack can be matched to the audit row |
| `type` | `start` `pause` `resume` `stop` `setTargetTemperature` `setMotorSpeed` `setFan` |
| `value` | numeric parameter for the parameterized commands, `null` otherwise |
| `issuedAt` | audit timestamp (also returned as `sentAt` by the API) |
| `expiresAt` | `issuedAt + MQTT_COMMAND_TTL_SECONDS` (default 30 s) — firmware must drop stale commands |

No user PII is ever put on the wire: no e-mail, no user id, no account name —
the topic carries the machine identity only.

## HTTP ↔ MQTT interplay

`POST /api/machines/{id}/commands` → 202 with:

```json
{ "success": true, "data": {
  "machineId": "…", "identifier": "3awedlou-001", "command": "start",
  "value": null, "topic": "3awedlou/machines/3awedlou-001/commands",
  "accepted": true, "commandId": "…",
  "delivery": "published_to_broker",
  "deviceAcknowledged": false,
  "sentAt": "…", "expiresAt": "…" } }
```

| `delivery` | Meaning | Audit `transport` |
|---|---|---|
| `published_to_broker` | broker accepted the publish (`MQTT_ENABLED=true`) | `mqtt` |
| `buffered_not_sent` | no broker configured — in-memory buffer only | `buffered-log` |
| — publish failed — | HTTP **503 `MQTT_UNAVAILABLE`**; the audit row records the failure | `mqtt-unavailable` |

The safety guard runs **before** anything is audited or published (offline
machines accept nothing; state transitions and value ranges enforced) — see
`docs/api-contract.md`.

## Consumer (`app:mqtt:consume`)

```bash
php bin/console app:mqtt:consume                  # runs up to 1h (default), clean exit after
php bin/console app:mqtt:consume --max-runtime=0  # run until SIGINT/SIGTERM
php bin/console app:mqtt:consume --memory-limit=256M   # exits non-zero at 90% for a supervisor
```

Subscribes to the three inbound branches only
(`{prefix}/machines/+/telemetry|status|events`) — commands are published here,
never consumed. Behaviour per message, in order:

1. topic must parse to `{prefix}/machines/{identifier}/{branch}` (anything else
   is dropped);
2. payload ≤ `MQTT_MAX_PAYLOAD_BYTES` (default 4096);
3. payload must be a JSON **object** (arrays, scalars, malformed → dropped);
4. the machine must already exist — **MQTT never creates machines**, an unknown
   identifier is logged and dropped;
5. telemetry is rate-limited per machine to `MQTT_TELEMETRY_MAX_PER_SECOND`
   (default 2) per **rolling second** (sliding window, in-memory, reset on
   restart), then handed to `TelemetryProcessor`;
6. the EntityManager is cleared after every message and the DB connection is
   health-checked — a long-running worker must not leak or die on a dead
   socket. Nothing here ever throws: errors are logged metadata-only (never
   the payload) and counted.

Reconnect uses exponential backoff (1 s → 30 s max); the command exits
non-zero if it can never connect, and prints a counter summary
(`received`, `telemetry`, `status_online`, `status_offline`, `event`,
`dropped`, `rate_limited`, `failed`) on shutdown.

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `MQTT_ENABLED` | `false` | `true` → real broker publisher; `false` → buffering logger, no socket |
| `MQTT_HOST` / `MQTT_PORT` | `localhost` / `1883` | broker address |
| `MQTT_USERNAME` / `MQTT_PASSWORD` | — | broker credentials (**`.env.local` only**, never committed) |
| `MQTT_CLIENT_ID` | `3awedlou-backend` | used as a prefix; each connection appends a random suffix (MQTT allows one live connection per client id) |
| `MQTT_TLS` | `false` | dev is plaintext loopback; any deployment **must** use TLS on 8883 |
| `MQTT_PREFIX` | `3awedlou` | topic prefix — must match `docker/mosquitto/acl` |
| `MQTT_QOS` | `1` | QoS for outbound commands and inbound subscriptions |
| `MQTT_COMMAND_TTL_SECONDS` | `30` | command expiry → `expiresAt` |
| `MQTT_MAX_PAYLOAD_BYTES` | `4096` | inbound payload cap |
| `MQTT_TELEMETRY_MAX_PER_SECOND` | `2` | per-machine inbound telemetry budget |

## Manual test recipes (require a running broker)

Start the stack: `docker compose -f compose.mqtt.yaml up -d`, create users
(`bin/mqtt-user.ps1 backend` and one per machine identifier), set
`MQTT_ENABLED=true` + credentials in `.env.local`.

```bash
# 1) pretend to be the machine (username = identifier), watch commands arrive
mosquitto_sub -h 127.0.0.1 -p 1883 -u <identifier> -P "$MQTT_DEVICE_PASSWORD" \
  -t '3awedlou/machines/<identifier>/commands' -v

# 2) send a command through the API — the sub above must show the envelope
curl -X POST http://127.0.0.1:8000/api/machines/{id}/commands \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"command":"setTargetTemperature","value":195}'

# 3) feed telemetry as the device would (run app:mqtt:consume first)
mosquitto_pub -h 127.0.0.1 -p 1883 -u <identifier> -P "$MQTT_DEVICE_PASSWORD" \
  -t '3awedlou/machines/<identifier>/telemetry' \
  -m '{"temperature":192.4,"targetTemperature":245.0,"status":"extruding"}'

# 4) ACL negative test: another machine's topic must be REFUSED by the broker
mosquitto_pub -h 127.0.0.1 -p 1883 -u <identifier> -P "$MQTT_DEVICE_PASSWORD" \
  -t '3awedlou/machines/some-other-machine/telemetry' -m '{}'
#    -> "Error: ... not authorised" expected

# 5) Last Will / graceful offline
mosquitto_pub -h 127.0.0.1 -p 1883 -u <identifier> -P "$MQTT_DEVICE_PASSWORD" \
  -t '3awedlou/machines/<identifier>/status' -m '{"online":false}'
```

The automated equivalent of (1)–(3) is
`tests/IoT/MqttBrokerIntegrationTest.php`, which runs only when
`MQTT_TEST_HOST` (plus optional `MQTT_TEST_PORT` / `MQTT_TEST_USERNAME` /
`MQTT_TEST_PASSWORD`) is set and is skipped otherwise — CI never needs a
broker.

## Security notes

* Dev broker: plaintext, `127.0.0.1` only. **Never expose it to the internet** —
  anyone who can reach it can sniff credentials and inject machine commands.
  Any deployment requires TLS (8883) + per-device credentials; that is out of
  scope for this repository today and **not implemented**.
* Payloads are untrusted input: they are size-capped, schema-checked, and
  logged metadata-only — never echoed into logs.
* Commands carry no user PII on the wire.

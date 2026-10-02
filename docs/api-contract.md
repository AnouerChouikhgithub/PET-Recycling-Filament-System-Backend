# 3awedlou API Contract

The single API contract consumed by **both** the web app (`web-app/src/types/api.ts`)
and the mobile app (`mobile-app/data/api.ts`). The TypeScript copies must never
drift from this document — when the backend changes, update all three together.

Base URL: `http://127.0.0.1:8000/api` (dev) — mobile on physical devices uses the
developer machine's LAN IP (see `mobile-app/.env.example`).

---

## Envelope

Every response — success or error — uses the same envelope:

```json
// success (meta is optional, present on lists/paginated endpoints)
{ "success": true, "data": { }, "meta": { "count": 2 } }

// error
{
  "success": false,
  "error": {
    "code": "MACHINE_NOT_FOUND",
    "message": "Machine not found.",
    "details": { "field": ["message"] }   // details only on validation errors
  }
}
```

Error codes: `UNAUTHORIZED` `INVALID_CREDENTIALS` `INVALID_TOKEN` `TOKEN_EXPIRED`
`TOKEN_NOT_FOUND` `FORBIDDEN` `MACHINE_NOT_FOUND` `VALIDATION_FAILED` `BAD_REQUEST`
`NOT_FOUND` `NETWORK_ERROR` (client-side) `INTERNAL_ERROR`. Internal (500) details
are never exposed to clients.

## Authentication

Stateless JWT (RS256, 1 h TTL) via `Authorization: Bearer <JWT>`.

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/auth/register` | — | `{email, password, name}` → 201 `{token, tokenType:"Bearer", user}` |
| POST | `/api/auth/login` | — | `{email, password}` → 200 `{token, tokenType:"Bearer", user}` |
| GET | `/api/me` | JWT | Current user `{id, email, name, roles, createdAt}` |

## Machines

Machine representation (identical for web and mobile):

```json
{
  "id": "uuid",
  "name": "3awedlou Prototype 001",
  "identifier": "3awedlou-001",
  "status": "extruding",
  "reportedStatus": "extruding",
  "lastSeenAt": "2026-09-30T01:00:00+00:00",
  "secondsSinceLastSeen": 42,
  "createdAt": "…", "updatedAt": "…", "owner": "uuid|null"
}
```

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/machines` | JWT | All machines |
| GET | `/api/machines/{id}` | JWT | Machine + latest telemetry + active session |
| GET | `/api/machines/{id}/status` | JWT | Lightweight status poll |
| GET | `/api/machines/{id}/dashboard` | JWT | Machine + telemetry + recentTelemetry + activeSession + recyclingTotals |

### Status values & offline rule

`idle` · `heating` · `extruding` · `paused` · `error` · `offline`

`offline` is **derived, never stored**: a machine is offline when
`lastSeenAt` is older than `MACHINE_OFFLINE_AFTER_MINUTES` (default 10, set in the
backend `.env`). Devices report only the first five values; the API returns the
effective status in `status` and the raw device status in `reportedStatus`.

## Telemetry

Sample shape (list endpoints newest-first; `recentTelemetry` in dashboard is
oldest→newest for charts):

```json
{
  "id": "uuid", "machineId": "uuid",
  "temperature": 192.4, "targetTemperature": 195.0,
  "heaterState": true, "motorState": true, "motorSpeed": 42, "fanState": true,
  "filamentSpeed": 3.1, "filamentDiameter": 1.75, "energyConsumption": 60.5,
  "extra": { },                      // JSONB — future sensors without schema changes
  "recordedAt": "2026-09-30T01:00:00+00:00"
}
```

All channels are nullable — a sample may carry any subset.

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/machines/{id}/telemetry?limit=&offset=&since=` | JWT | History, newest first (limit ≤ 1000) |
| POST | `/api/machines/{id}/telemetry` | JWT | Ingest one sample → 201 |

### Ingestion (POST) — used by the ESP32/IoT layer

Body: any subset of the channels above, plus optionally `status` (one of the five
device-reportable lifecycle values — **never** `offline`) and `recordedAt`
(ISO-8601; defaults to ingestion time). Unknown fields are rejected; ranges are
enforced (temperature −10…400 °C, diameter 0.5–4 mm, motorSpeed 0–10000 RPM…).

Ingestion also refreshes `lastSeenAt` (connectivity signal) and applies a
device-reported `status` to the machine. The HTTP endpoint and the future MQTT
consumer share the **same** `TelemetryProcessor` — there is exactly one
ingestion pipeline:

```
MQTT message  or  HTTP POST
        ↓
TelemetryProcessor::process()
        ↓ validation
machine_telemetry (PostgreSQL)  +  machine.status / lastSeenAt refresh
        ↓
TelemetryProcessedEvent → RealtimeListener → RealtimeBroadcaster
```

## Sessions, production, recycling

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/api/machines/{id}/sessions?limit=&offset=&status=` | JWT | Machine sessions |
| GET | `/api/machines/{id}/production?limit=&offset=` | JWT | Filament production records |
| GET | `/api/machines/{id}/recycling?limit=&offset=` | JWT | `{records, totals}` |

Session: `{id, machineId, operator, startedAt, endedAt, status(in_progress|paused|completed|failed), materialInput, materialOutput, durationMinutes, notes}`.
Production: `{id, sessionId, batchCode, diameterTarget, diameterActual, diameterSamples, weightGrams, lengthMeters, durationMinutes, material, color, colorHex, quality(excellent|good|fair|poor), notes, producedAt}`.
Recycling record: `{id, sessionId, machineId, inputMaterial, inputMassGrams, outputMaterial, outputMassGrams, durationMinutes, avgTemperature, notes, recycledAt}`.

## Machine commands

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/api/machines/{id}/commands` | JWT | Validate + dispatch → **202** |

```json
// request — parameterized commands require "value"
{ "command": "start" }
{ "command": "setTargetTemperature", "value": 195 }
{ "command": "setFan", "value": 80, "params": { "ramp": true } }

// 202 response
{ "success": true, "data": {
  "machineId": "…", "identifier": "3awedlou-001", "command": "start",
  "value": null, "topic": "3awedlou/machines/3awedlou-001/commands",
  "accepted": true, "sentAt": "…" } }
```

Commands: `start` `pause` `resume` `stop` `setTargetTemperature` `setMotorSpeed` `setFan`.

**Safety guard** (backend-side, before any publish): offline machines accept
nothing; `start` only from idle/error; `resume` only from paused; `pause`/`stop`
only while running; numeric values are range-checked (heater ≤ 300 °C, motor/fan
0–100 %). Violations → `422 VALIDATION_FAILED`. **Acceptance ≠ execution** — the
ESP32 reports actual execution through telemetry/status.

## MQTT architecture (phase 2 — transport not yet wired)

Topic tree (prefix configurable via `MQTT_PREFIX`, default `3awedlou`):

```
{prefix}/machines/{identifier}/telemetry   ESP32 → backend   samples
{prefix}/machines/{identifier}/status      ESP32 → backend   lifecycle
{prefix}/machines/{identifier}/events      ESP32 → backend   log-worthy events
{prefix}/machines/{identifier}/commands    backend → ESP32   remote control
```

- The **frontend never publishes MQTT** — Web/Mobile → Symfony → MQTT → ESP32.
- Broker credentials live only in backend env (`MQTT_HOST`, `MQTT_PORT`,
  `MQTT_USERNAME`, `MQTT_PASSWORD`, `MQTT_CLIENT_ID`, `MQTT_TLS`, `MQTT_PREFIX`).
- Today `MqttPublisherInterface` is implemented by a buffering logger; the real
  broker client later swaps in via one alias in `config/services.yaml`.
- The MQTT consumer will call `TelemetryProcessor` directly (same pipeline as
  HTTP ingest — no duplicated logic).

## Realtime layer (abstraction in place, transport pluggable)

Both frontends program against an identical `RealtimeService` interface:

```
connect() · disconnect() · subscribeToMachine(id) · unsubscribe()
onTelemetry(cb) · onMachineStatus(cb) · onMachineEvent(cb)
```

- `VITE_REALTIME_URL` / `EXPO_PUBLIC_REALTIME_URL` set → WebSocket transport
  (wired when the backend hub ships; the interface is the stable contract).
- Empty → **honest polling** of `/dashboard` every 10 s, surfaced to the UI as
  `transport: 'polling'` — the apps never pretend polling is a live stream.
- Backend fan-out point: `RealtimeBroadcaster` (logging implementation today,
  WebSocket/SSE/Mercure hub later — one alias swap in `config/services.yaml`).

## Frontend env vars

| App | Variable | Purpose |
|---|---|---|
| web | `VITE_API_BASE_URL` | Symfony API base URL |
| web | `VITE_REALTIME_URL` | Realtime transport URL (empty = polling) |
| web | `VITE_ENVIRONMENT` | `development` \| `production` |
| mobile | `EXPO_PUBLIC_API_BASE_URL` | API base URL — **LAN IP for physical devices** |
| mobile | `EXPO_PUBLIC_REALTIME_URL` | Realtime transport URL (empty = polling) |
| mobile | `EXPO_PUBLIC_ENVIRONMENT` | `development` \| `production` |

No secrets ever live in the frontends; `.env` files are git-ignored,
`.env.example` files are committed.

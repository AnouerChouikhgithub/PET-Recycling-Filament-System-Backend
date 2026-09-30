# 3awedlou — Backend (Symfony API)

Shared backend for the **3awedlou** plastic-recycling machine ecosystem.
It serves the **React web app** (`../web-app`), the **React Native / Expo mobile
app** (`../mobile-app`) and — in a later phase — the **ESP32 → Arduino Mega**
machine controllers.

```
             ┌─────────────────┐
             │ React Web App   │  ../web-app
             └────────┬────────┘
                      │  REST + JWT (HTTPS)
                      ▼
             ┌─────────────────┐
             │  Symfony API    │  this repository
             └────────┬────────┘
                      │
        ┌─────────────┼──────────────┐
        ▼             ▼              ▼
   PostgreSQL     MQTT / IoT      Real-time layer
   (Doctrine)     (phase 2)       (later phase)
                      │
                      ▼
                   ESP32 ── Arduino Mega ── 3awedlou machine
```

**This repository:** authentication, machine/telemetry/session domain, read API,
telemetry **ingestion**, guarded machine **commands** (MQTT-ready), offline
detection and a realtime fan-out abstraction. Dev fixtures included.
Broker transport and the WebSocket hub are pluggable next steps — the seams
(`MqttPublisherInterface`, `RealtimeBroadcaster`) already exist.

**API contract:** every endpoint, payload and rule is documented in
[`docs/api-contract.md`](docs/api-contract.md) — the same document the web and
mobile clients are built against.

---

## Requirements

| Tool       | Version used | Notes                                        |
|------------|--------------|----------------------------------------------|
| PHP        | **8.3**      | extensions: `pdo_pgsql`, `sodium`, `openssl`, `mbstring` |
| Composer   | 2.10         |                                              |
| Symfony    | **7.4 LTS**  | supported until **July 2029**                |
| PostgreSQL | **17**       | any ≥ 14 works                               |
| Docker     | optional     | for the one-command dev database             |

> **Why Symfony 7.4 and not 8.x / 7.3?** Symfony 8 requires PHP ≥ 8.4
> (installed: 8.3). 7.3 reached end of its security window and is blocked by
> Composer's security-advisory policy. 7.4 is the current LTS — the newest
> version this environment can run, supported until 2029.

## Installation

```bash
cd backend
composer install
```

### PHP extensions (XAMPP)

`pdo_pgsql` and `sodium` (required by the JWT library) must be enabled in
`php.ini` (already done on this machine — backup at `C:\xampp\php\php.ini.bak-3awedlou`):

```ini
extension=pdo_pgsql
extension=pgsql
extension=sodium
```

## Environment variables

Configuration lives in `.env` (committed defaults) and `.env.local`
(**not committed** — your real secrets/overrides).

| Variable             | Purpose                                        | Default (dev)                          |
|----------------------|------------------------------------------------|----------------------------------------|
| `APP_ENV`            | `dev` / `prod` / `test`                        | `dev`                                  |
| `APP_SECRET`         | framework secret                               | dev value (override in prod)           |
| `DATABASE_URL`       | PostgreSQL DSN                                 | `postgresql://postgres:postgres@127.0.0.1:5432/db_3awedlou?serverVersion=17&charset=utf8` |
| `JWT_SECRET_KEY`     | path to RSA private key                        | `config/jwt/private.pem`               |
| `JWT_PUBLIC_KEY`     | path to RSA public key                         | `config/jwt/public.pem`                |
| `JWT_PASSPHRASE`     | passphrase of the private key                  | generated dev value                    |
| `CORS_ALLOW_ORIGIN`  | regex of allowed browser origins               | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` |
| `MQTT_*`             | **documented only** — phase 2 (not required)   | commented out in `.env`                |

```bash
# local overrides (never commit this file)
cp .env .env.local
# then edit .env.local
```

**Production checklist:** set `APP_ENV=prod`, a strong `APP_SECRET`, real
`DATABASE_URL` credentials, a dedicated `CORS_ALLOW_ORIGIN` (e.g.
`^https://app\.yourdomain\.com$`), and fresh JWT keys
(`php bin/console lexik:jwt:generate-keypair --overwrite` — or better, inject
`JWT_SECRET_KEY`/`JWT_PUBLIC_KEY`/`JWT_PASSPHRASE` as environment variables).

## PostgreSQL + database creation

Development database via Docker (recommended):

```bash
docker compose up -d          # postgres:17-alpine on localhost:5432
```

Then create the schema:

```bash
php bin/console doctrine:database:create
```

## Migrations

```bash
php bin/console make:migration                      # generate from entities
php bin/console doctrine:migrations:migrate         # apply
php bin/console doctrine:schema:validate            # verify mapping + sync
```

The initial migration creates: `user`, `machine`, `machine_telemetry`,
`machine_session`, `filament_production`, `recycling_session`.

## Fixtures (development only)

Realistic sample data (2 users, 2 machines, 24 telemetry samples, 4 sessions,
3 production batches, 4 recycling records) — mirrors what the frontends'
mock data shows today:

```bash
php bin/console doctrine:fixtures:load          # wipes the DB first!
```

Dev accounts (password **`3awedlou-dev`**, see `AppFixtures::DEV_PASSWORD`):

| Email                   | Name   |
|-------------------------|--------|
| `anouer@3awedlou.app`   | Anouer |
| `sara@3awedlou.app`     | Sara   |

> Fixtures are registered only in the `dev`/`test` environments
> (`doctrine/doctrine-fixtures-bundle` is a dev dependency). Never run them
> against production.

## Starting Symfony

```bash
symfony serve -d          # with the Symfony CLI (recommended)
# or
php -S 127.0.0.1:8000 -t public
```

## API base URL

```
http://127.0.0.1:8000/api
```

## Authentication

Stateless **JWT** (RS256) via `lexik/jwt-authentication-bundle` — one scheme
for web, mobile and future IoT devices. Tokens expire after 1 h
(`token_ttl`, configurable in `config/packages/lexik_jwt_authentication.yaml`).

```bash
# 1) login
curl -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"anouer@3awedlou.app","password":"3awedlou-dev"}'

# 2) call an authenticated endpoint
curl http://127.0.0.1:8000/api/me \
  -H "Authorization: Bearer <TOKEN>"
```

Notes:

* Registration (`POST /api/auth/register`) is public and returns a token
  immediately.
* There is **no server-side logout** to implement: with stateless JWT the
  client simply discards the token. Token invalidation/refresh arrives in a
  later phase (refresh tokens).
* `roles` live on the user; `ROLE_ADMIN` implies `ROLE_USER`
  (`role_hierarchy`).

## Available endpoints

| Method | Path                                  | Auth | Description                                  |
|--------|---------------------------------------|------|----------------------------------------------|
| POST   | `/api/auth/register`                  | —    | Create account, returns JWT                  |
| POST   | `/api/auth/login`                     | —    | Login, returns JWT                           |
| GET    | `/api/me`                             | JWT  | Current user                                 |
| GET    | `/api/machines`                       | JWT  | All machines                                 |
| GET    | `/api/machines/{id}`                  | JWT  | Machine + latest telemetry + active session  |
| GET    | `/api/machines/{id}/status`           | JWT  | Lightweight status poll                      |
| GET    | `/api/machines/{id}/dashboard`        | JWT  | One round trip for the dashboards            |
| GET    | `/api/machines/{id}/telemetry`        | JWT  | Telemetry history (`?limit=&offset=&since=`) |
| POST   | `/api/machines/{id}/telemetry`        | JWT  | **Ingest** one sample (ESP32 / IoT layer)    |
| GET    | `/api/machines/{id}/sessions`         | JWT  | Session history (`?limit=&offset=&status=`)  |
| GET    | `/api/machines/{id}/production`       | JWT  | Filament production records                  |
| GET    | `/api/machines/{id}/recycling`        | JWT  | Recycling records + impact totals            |
| POST   | `/api/machines/{id}/commands`         | JWT  | Guarded remote command → 202 (MQTT-ready)    |

Full request/response shapes: [`docs/api-contract.md`](docs/api-contract.md).

### Ingesting telemetry (quick start)

```bash
curl -X POST http://127.0.0.1:8000/api/machines/{id}/telemetry \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"temperature":192.4,"targetTemperature":195,"heaterState":true,
       "motorState":true,"motorSpeed":42,"fanState":true,
       "filamentSpeed":3.1,"filamentDiameter":1.75,"status":"extruding"}'
```

The sample is validated (unknown fields rejected, sane ranges enforced), stored,
bumps `lastSeenAt` and may update the machine's reported status. The same
`TelemetryProcessor` serves the future MQTT consumer — one pipeline, no duplicates.

### Sending a machine command

```bash
curl -X POST http://127.0.0.1:8000/api/machines/{id}/commands \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"command":"setTargetTemperature","value":195}'
```

Replies **202** with the resolved MQTT topic. The guard rejects (422) commands
from offline machines, invalid state transitions (`resume` when not paused) and
out-of-range values (heater ≤ 300 °C, motor/fan 0–100 %).

## Response format

Every response uses the same envelope:

```json
{ "success": true, "data": { }, "meta": { "count": 2 } }
```

Errors:

```json
{
  "success": false,
  "error": {
    "code": "MACHINE_NOT_FOUND",
    "message": "Machine not found."
  }
}
```

Validation failures return **422** with per-field details:

```json
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The provided data is invalid.",
    "details": { "password": ["This value is too short. It should have 8 characters or more."] }
  }
}
```

Error codes: `UNAUTHORIZED`, `INVALID_CREDENTIALS`, `INVALID_TOKEN`,
`TOKEN_EXPIRED`, `TOKEN_NOT_FOUND`, `FORBIDDEN`, `MACHINE_NOT_FOUND`,
`VALIDATION_FAILED`, `BAD_REQUEST`, `NOT_FOUND`, `INTERNAL_ERROR`.
Internal (500) details are **never** exposed to clients — they go to the logs.

## CORS

Handled by `nelmio/cors-bundle`, applied to `^/api/` only. Allowed origins
come from `CORS_ALLOW_ORIGIN`:

* **Development** (default): `^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$`
  — covers the Vite dev server (`http://localhost:5173`) and Expo web.
  *React Native on a device does not need CORS* (no browser), it calls the
  API directly — use your machine's LAN IP as the API host in the app config.
* **Production:** override in `.env.local`/env vars with the real origin, e.g.
  `CORS_ALLOW_ORIGIN='^https://app\.3awedlou\.example$'`. Never use `*` with
  credentials.

## Project structure

```
src/
├── Api/                    # response envelope + exception layer
│   ├── ApiResponse.php         # {success, data} / {success, error} helper
│   ├── Exception/              # domain exceptions → stable error codes
│   └── EventSubscriber/        # converts ALL /api exceptions to the envelope
├── Controller/Api/         # thin HTTP controllers (routing only)
├── Dto/
│   ├── Request/                # validated request bodies (#[MapRequestPayload])
│   └── Transformer/            # entity → JSON view factories
├── Entity/                 # Doctrine entities + enums
├── Repository/             # DB queries (Doctrine)
├── Security/               # JWT login handlers, user provider, entry point
├── Service/                # business logic (Controller → Service → Repository)
└── DataFixtures/           # dev-only sample data
```

## Tests

Functional API tests (real HTTP kernel, real test database):

```bash
# one-time: create + migrate the test DB
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction

php bin/phpunit
```

## Future phases (deliberately NOT implemented yet)

* **MQTT transport:** a real broker client behind `MqttPublisherInterface`
  (swap one alias in `config/services.yaml`) + an MQTT consumer command that
  feeds `TelemetryProcessor`. Env vars (`MQTT_HOST`…`MQTT_PREFIX`) are already
  declared. ESP32 auth (per-device tokens) ships with it.
* **Realtime transport:** a WebSocket/SSE/Mercure hub behind
  `RealtimeBroadcaster` (same one-alias swap). Both frontends already program
  against the final client interface and poll honestly in the meantime.
* **Later:** refresh tokens / token revocation, session start/write endpoints
  (currently the machine/firmware owns session lifecycle), push notifications,
  admin back-office, deployment (Docker image, CI/CD).

See `Tasks.xlsx` at the 3awedlou project root for the overall plan.

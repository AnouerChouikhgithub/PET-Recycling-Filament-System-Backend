# 3awedlou — Backend (Symfony API)

Shared backend for the **3awedlou** plastic-recycling machine ecosystem. It
serves the React web app and the React Native / Expo mobile app — one API, one
JWT scheme, one contract (see [`docs/api-contract.md`](docs/api-contract.md)).

```
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
   PostgreSQL     MQTT / IoT      Real-time layer
   (Doctrine)     (phase 2)       (later phase)
                      │
                      ▼
                   ESP32 ── Arduino Mega ── 3awedlou machine
```

**Scope:** authentication, machine/telemetry/session domain, read API,
telemetry **ingestion**, guarded machine **commands** (MQTT-ready), offline
detection, and a realtime fan-out abstraction. Dev fixtures included. Broker
transport and the WebSocket hub are pluggable next steps — the seams
(`MqttPublisherInterface`, `RealtimeBroadcaster`) already exist.

**API contract:** every endpoint, payload and rule is documented in
[`docs/api-contract.md`](docs/api-contract.md) — the same document the web and
mobile clients are built against.

---

## Requirements

| Tool | Version | Notes |
|---|---|---|
| PHP | **8.3** | extensions: `pdo_pgsql`, `sodium`, `openssl`, `mbstring` |
| Composer | 2.10 | |
| Symfony | **7.4 LTS** | supported until July 2029 |
| PostgreSQL | **17** | any ≥ 14 works |
| Docker | optional | for the one-command dev database |

> Symfony 7.4 is the current LTS the environment can run (Symfony 8 requires
> PHP ≥ 8.4). The project `composer.json` pins `symfony/*: 7.4.*`.

## Installation

```bash
cd backend
composer install
```

### PHP extensions (XAMPP)

`pdo_pgsql` and `sodium` (required by the JWT library) must be enabled in
`php.ini`. On this machine the originals were backed up as
`php.ini.bak-3awedlou`; enable:

```ini
extension=pdo_pgsql
extension=pgsql
extension=sodium
```

## Environment variables

Configuration lives in `.env` (committed defaults) and `.env.local`
(**not committed** — your real secrets/overrides). `.env.example` holds every
annotated default.

| Variable | Required | Default | Meaning | Example placeholder |
|---|---|---|---|---|
| `APP_ENV` | no | `dev` | `dev` \| `prod` \| `test` | `dev` |
| `APP_SECRET` | yes* | *(generated)* | framework secret | `CHANGE_ME_generate_with_bin_console_secret_generate` |
| `DATABASE_URL` | yes | `postgresql://postgres:postgres@127.0.0.1:5432/db_3awedlou?serverVersion=17&charset=utf8` | PostgreSQL DSN | use your own `postgres` / db name |
| `JWT_SECRET_KEY` | yes | `%kernel.project_dir%/config/jwt/private.pem` | RSA private key path | keep the default, keys are git-ignored |
| `JWT_PUBLIC_KEY` | yes | `%kernel.project_dir%/config/jwt/public.pem` | RSA public key path | keep the default |
| `JWT_PASSPHRASE` | no | *(empty in dev)* | passphrase of the private key | `""` (dev) |
| `CORS_ALLOW_ORIGIN` | no | `^https?://(localhost\|127\.0\.0\.1)(:[0-9]+)?$` | regex of allowed browser origins | `^https://app\.yourdomain\.com$` |
| `AUTH_REGISTER_ENABLED` | no | `true` | close open registration | `false` |
| `MACHINE_OFFLINE_AFTER_MINUTES` | no | `10` | machine treated offline after this many minutes | `10` |
| `COMMAND_HEATER_MAX_C` | no | `260` | backend heater safety cap (°C) | `260` |
| `COMMAND_MOTOR_MAX` | no | `100` | backend motor safety cap (%) | `100` |
| `COMMAND_FAN_MAX` | no | `100` | backend fan safety cap (%) | `100` |
| `MQTT_ENABLED` | no | `false` | real broker publisher when `true`; in-memory buffer otherwise | `true` |
| `MQTT_HOST` | no | `localhost` | broker address | `localhost` |
| `MQTT_PORT` | no | `1883` | broker port (plaintext dev) | `1883` |
| `MQTT_USERNAME` | no | `backend` | broker user — `backend` for the app, `Machine.identifier` for devices | `backend` |
| `MQTT_PASSWORD` | yes* | *(placeholder)* | broker password | `CHANGE_ME_broker_password_created_by_bin_mqtt-user` |
| `MQTT_CLIENT_ID` | no | `3awedlou-backend` | broker client id | `3awedlou-backend` |
| `MQTT_TLS` | no | `false` | TLS on 8883 (deployment only) | `false` |
| `MQTT_PREFIX` | no | `3awedlou` | topic prefix (must match `docker/mosquitto/acl`) | `3awedlou` |
| `MQTT_QOS` | no | `1` | QoS for commands + inbound subscriptions | `1` |
| `MQTT_COMMAND_TTL_SECONDS` | no | `30` | command expiry (TTL) | `30` |
| `MQTT_MAX_PAYLOAD_BYTES` | no | `4096` | consumer payload cap | `4096` |
| `MQTT_TELEMETRY_MAX_PER_SECOND` | no | `2` | per-machine telemetry rate limit | `2` |

\* Powers of the app: `APP_SECRET`, `DATABASE_URL`, `JWT_*`, `MQTT_PASSWORD`,
`CORS_ALLOW_ORIGIN` in prod. **Never commit `.env.local`.**

Local secrets live in `.env.local` (git-ignored). Committed files hold only
placeholders.

### Production checklist

- `APP_ENV=prod`, a strong `APP_SECRET`, real `DATABASE_URL` credentials
- a dedicated `CORS_ALLOW_ORIGIN` (e.g. `^https://app\.yourdomain\.com$`)
- fresh JWT keys: `php bin/console lexik:jwt:generate-keypair --overwrite`
  (or inject `JWT_SECRET_KEY`/`JWT_PUBLIC_KEY`/`JWT_PASSPHRASE` as env vars)
- **TLS on the broker (8883) with per-device credentials** — the dev broker is
  plaintext loopback only.

## PostgreSQL + database creation

Dev database via Docker (recommended):

```bash
docker compose up -d          # postgres:17-alpine on localhost:5432
```

Then:

```bash
php bin/console doctrine:database:create
```

The dev database container is `3awedlou-postgres` on `127.0.0.1:5432`.

### The `symfony serve` DATABASE_URL override trap

`compose.yaml` defines the postgres service, but `symfony serve` injects its
*own* DATABASE_URL (from its own postgres container) on top of `.env`
afterwards. This is the **two-database trap**:

- `docker compose up -d` starts the git-defined postgres on `127.0.0.1:5432`.
- `symfony serve` then rewrites the app's `DATABASE_URL` to its own container
  (a *second* postgres instance), so the app connects to the wrong database.

**How to avoid it:** use `bin/dev-check` (or `bin/dev-check.ps1`) to see which
database the app actually resolves and warn if more than one postgres container
is running. Prefer `symfony serve --no-tls --allow-all-ip` after fixing
`DATABASE_URL`, or stop the extra container.

## Local MQTT broker (development only)

The broker lives in its **own** compose file on purpose: `compose.yaml` stays
untouched, so the `symfony serve` `DATABASE_URL` auto-injection / two-database
trap cannot come back.

```powershell
# 1) backend broker user (password prompted with echo disabled)
.\bin\mqtt-user.ps1 backend

# 2) one user per machine, named EXACTLY like Machine.identifier
.\bin\mqtt-user.ps1 3awedlou-001

# 3) start the broker — bound to 127.0.0.1:1883 only
docker compose -f compose.mqtt.yaml up -d
```

`bin/mqtt-user.sh` is the Linux/macOS equivalent. Both run `mosquitto_passwd`
inside the container (or a one-shot container when the broker is down), pipe
the password over stdin — so it never appears in a command line — and write only
the hashed file `docker/mosquitto/passwd`, which is **git-ignored**. Mosquitto
reads that file at startup; if you add a user while the broker is already
running, run `docker compose -f compose.mqtt.yaml restart mosquitto`.

| Path | Purpose |
|---|---|
| `compose.mqtt.yaml` | one service: `eclipse-mosquitto:2`, `127.0.0.1:1883` |
| `docker/mosquitto/mosquitto.conf` | listener, `allow_anonymous false`, `password_file`, `acl_file`, persistence |
| `docker/mosquitto/acl` | `backend` → `readwrite {prefix}/#`; a device → its own topic branch only |
| `docker/mosquitto/passwd` | hashed broker credentials — **git-ignored secret** |

> **No TLS in development, on purpose.** Port 1883 is plaintext MQTT, bound to
> `127.0.0.1` only. **Never expose it to the internet.** Any deployment
> (VPS, LAN, Raspberry Pi) requires TLS on 8883 plus per-device credentials —
> that is out of scope and not implemented here.

Once the broker and users exist, turn the transport on in `.env.local`
(never committed):

```bash
MQTT_ENABLED=true
MQTT_USERNAME=backend
MQTT_PASSWORD=  # the password you chose in step 1 — value never committed
```

Consumer command: `php bin/console app:mqtt:consume` — full contract in
[`docs/mqtt-contract.md`](docs/mqtt-contract.md).

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
3 production batches, 4 recycling records) — mirrors what the frontends' mock
data shows today:

```bash
php bin/console doctrine:fixtures:load          # wipes the DB first!
```

Dev accounts (password `3awedlou-dev`, see `AppFixtures::DEV_PASSWORD` — the
same password is used by both fixtures):

| Email | Name |
|---|---|
| `anouer@3awedlou.app` | Anouer |
| `sara@3awedlou.app` | Sara |

> Fixtures are registered only in `dev`/`test` environments. Never run them
> against production. `anouer@3awedlou.app` / `sara@3awedlou.app` with password
> `3awedlou-dev` are **local development fixtures, never load in production**.

## Starting Symfony

```bash
symfony serve -d          # with the Symfony CLI (recommended)
# or
php -S 0.0.0.0:8000 -t public
```

> Use `0.0.0.0` (not `127.0.0.1`) so a phone on the LAN can reach the API
> during development. The nginx equivalent must bind all interfaces too.

## API base URL

```
http://127.0.0.1:8000/api
```

---

### Endpoints (from `src/Controller/Api`)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `POST` | `/api/auth/register` | public | Create account, returns JWT (rate-limited: 3/10 min) |
| `POST` | `/api/auth/login` | public | Login, returns access JWT + refresh token (rate-limited: 5/5 min) |
| `GET` | `/api/me` | JWT | Current user |
| `GET` | `/api/machines` | JWT | All machines (ownership-filtered) |
| `GET` | `/api/machines/{id}` | JWT | Machine + latest telemetry + active session |
| `GET` | `/api/machines/{id}/status` | JWT | Lightweight status poll (effective + reported status) |
| `GET` | `/api/machines/{id}/dashboard` | JWT | One round trip: machine + telemetry + recent + session + recycling totals |
| `GET` | `/api/machines/{id}/telemetry` | JWT | Telemetry history (`?limit=&offset=&since=`) |
| `POST` | `/api/machines/{id}/telemetry` | JWT | **Ingest** one sample (ESP32 / IoT layer) |
| `GET` | `/api/machines/{id}/sessions` | JWT | Session history (`?limit=&offset=&status=`) |
| `GET` | `/api/machines/{id}/production` | JWT | Filament production records (`?limit=&offset=`) |
| `GET` | `/api/machines/{id}/recycling` | JWT | Recycling records + impact totals |
| `POST` | `/api/machines/{id}/commands` | JWT | Guarded remote command → 202 (503 if broker down) |

Full request/response shapes: [`docs/api-contract.md`](docs/api-contract.md).

### Ingesting telemetry (quick start)

```bash
curl -X POST http://127.0.0.1:8000/api/machines/{id}/telemetry \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"temperature":192.4,"targetTemperature":195,"heaterState":true,"motorState":true,"motorSpeed":42,"fanState":true,"filamentSpeed":3.1,"filamentDiameter":1.75,"status":"extruding"}'
```

The sample is validated (unknown fields rejected, sane ranges enforced), stored,
bumps `lastSeenAt` and may update the machine's reported status. The same
`TelemetryProcessor` also serves the MQTT consumer — one pipeline, no duplicates.

### Sending a machine command

```bash
curl -X POST http://127.0.0.1:8000/api/machines/{id}/commands \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"command":"setTargetTemperature","value":195}'
```

Replies **202** with the resolved MQTT topic, a `commandId`
(correlated to the audit row) and `delivery`: `published_to_broker` when
`MQTT_ENABLED=true` and the broker accepted the publish, `buffered_not_sent`
otherwise. If the broker is enabled but unreachable the reply is **503
`MQTT_UNAVAILABLE`** — never a fake success.

The guard rejects (422) commands from offline machines, invalid state
transitions (`resume` when not paused) and out-of-range values (heater ≤ 300 °C,
motor/fan 0–100 %). **Acceptance ≠ execution**: the machine reports what
actually ran through telemetry/status.

---

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

Validation failures return **422** with per-field details (in the `details`
object).

Error codes: `UNAUTHORIZED`, `INVALID_CREDENTIALS`, `INVALID_TOKEN`,
`TOKEN_EXPIRED`, `TOKEN_NOT_FOUND`, `FORBIDDEN`, `MACHINE_NOT_FOUND`,
`VALIDATION_FAILED`, `BAD_REQUEST`, `NOT_FOUND`, `INTERNAL_ERROR`,
`NETWORK_ERROR` (client-side), `MQTT_UNAVAILABLE` (503).

Internal (500) details are never exposed to clients.

## CORS

Handled by `nelmio/cors-bundle`, applied to `^/api/` only. Allowed origins come
from `CORS_ALLOW_ORIGIN`:

- **Development** (default): `^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$` —
  covers the Vite dev server (`http://localhost:5173`).
  React Native on a device does not need CORS (no browser); it calls the API
  directly using the machine's LAN IP.
- **Production:** override in `.env.local` with the real origin, e.g.
  `CORS_ALLOW_ORIGIN='^https://app\.3awedlou\.example$'`. Never use `*` with
  credentials.

## Project structure

```text
.
├── bin/                          # console entry, helper scripts (mqtt-user, dev-check)
├── compose.mqtt.yaml             # Mosquitto dev broker (separate file — see below)
├── compose.yaml                  # postgres + app services (dev)
├── config/
│   ├── packages/                 # bundle configs (lexik jwt, rate limiter, nelmio cors, messenger, ...)
│   ├── services.yaml             # app services (IoT seam: SelectedMqttPublisher, ...)
│   └── bundles.php
├── docker/
│   └── mosquitto/                # broker config + ACL + (git-ignored) passwd
├── docs/
│   ├── api-contract.md           # endpoint + payload contract
│   └── mqtt-contract.md          # MQTT topics, payloads, consumer, ACL
├── migrations/
├── public/
├── scripts/                      # (none — this repo uses bin/ and composer scripts)
├── src/
│   ├── Api/                      # response envelope + exception layer
│   ├── Controller/Api/           # thin HTTP controllers (routing only)
│   ├── Dto/                      # validated request bodies + entity→JSON view factories
│   ├── Entity/                   # Doctrine entities + enums
│   ├── Repository/               # DB queries (Doctrine)
│   ├── Security/                 # JWT login handlers, user provider, device tokens, entry point
│   ├── Service/                  # business logic (Controller → Service → Repository)
│   │   └── IoT/                  # MQTT seams: MqttPublisherInterface, SelectedMqttPublisher,
│   │                           # MqttSubscriberInterface, TelemetryProcessor, MachineCommandService
│   └── DataFixtures/             # dev-only sample data
├── tests/                        # functional API tests + IoT component tests
├── translations/
├── .env / .env.example / .env.local / .env.test
├── CHANGELOG.md
├── composer.json
└── README.md
```

## Scripts / commands

| Command | What it does |
|---|---|
| `composer install` | Install dependencies |
| `php bin/console app:mqtt:consume` | MQTT consumer (telemetry in, commands out) |
| `php bin/console make:migration` | Generate a migration from entities |
| `php bin/console doctrine:migrations:migrate` | Apply migrations |
| `php bin/console doctrine:schema:validate` | Verify mapping + sync |
| `php bin/console doctrine:fixtures:load` | Load dev fixtures (wipes DB) |
| `php bin/phpunit` | Run the test suite |
| `symfony serve -d` | Start the Symfony dev server |
| `bash bin/dev-check` / `.\bin\dev-check.ps1` | Dev sanity check (database + migration status + the two-DB trap warning) |

## Testing and quality

Functional API tests (real HTTP kernel, real test database) plus IoT component
tests:

```bash
# one-time: create + migrate the test DB
php bin/console doctrine:database:create --env=test
php bin/console doctrine:migrations:migrate --env=test --no-interaction

php bin/phpunit
```

## Status: implemented / prepared / future

| Area | State |
|---|---|
| HTTP API (auth, machines, telemetry, sessions, commands) | Implemented |
| Telemetry ingestion (`TelemetryProcessor`) | Implemented |
| Guarded machine commands + audit trail | Implemented |
| MQTT publisher (`MQTT_ENABLED=true`) + audit trail | Implemented |
| MQTT consumer (`app:mqtt:consume` → `TelemetryProcessor`) | Implemented |
| Local Mosquitto dev broker with per-device ACL | Implemented (dev only, no TLS) |
| Device acknowledgement of commands | Prepared — `commandId`/`expiresAt` on the wire; `deviceAcknowledged` stays `false` until firmware acks |
| Device-originated events | Prepared — validated and logged, no side effects yet |
| Realtime transport (WebSocket/SSE/Mercure behind `RealtimeBroadcaster`) | Prepared — both frontends program against the final interface and poll honestly |
| ESP32 firmware (incl. device-side auth) | Future — not in this repo; the wire contract is `docs/mqtt-contract.md` |
| TLS + per-device broker credentials for deployment | Future — the dev broker is plaintext loopback only |
| Later | refresh-token revocation hardening, session start/write endpoints (machine/firmware owns session lifecycle today), push notifications, admin back-office, deployment (Docker image, CI/CD) |

## Security notes

* Owner/device isolation is enforced by `MachineAccess` (ownership check) and
  the `deviceTokensEnabled` kill switch.
* Device tokens are hashed at rest; JWT is RS256, 1 h TTL, single-use refresh
  token via `gesdinet/jwt-refresh-token-bundle`.
* Auth surface is rate-limited (login 5/5 min, register 3/10 min per IP).
* **Never commit** `.env.local`, JWT keys (`config/jwt/*.pem`) or broker
  passwords — all are git-ignored. Broker credentials are created by
  `bin/mqtt-user.ps1` / `bin/mqtt-user.sh` and live only in `.env.local`.
* The dev broker is **loopback-only** (`127.0.0.1:1883`, plaintext,
  `MQTT_TLS=false`). TLS + per-device credentials are required before any
  deployment and are not implemented here.
* Device-originated commands are not accepted today; only backend-issued
  commands flow to the machine.

## Related docs

* API contract: [`docs/api-contract.md`](docs/api-contract.md)
* MQTT contract: [`docs/mqtt-contract.md`](docs/mqtt-contract.md)
* Web: https://github.com/AnouerChouikhgithub/PET-Recycling-Filament-System-Web/blob/main/README.md
* Mobile: https://github.com/AnouerChouikhgithub/PET-Recycling-Filament-System-Mobile/blob/main/README.md
* Hardware: https://github.com/AnouerChouikhgithub/pet-recycling-filament-system/blob/main/README.md

## License

TODO(anouer): no LICENSE file found in this repo — add one.

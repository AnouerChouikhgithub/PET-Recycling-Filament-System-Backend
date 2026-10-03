# SESSION STATUS — feat/mqtt-integration (phase 3: MQTT)

Scope: connect the Symfony backend to a real Mosquitto broker — telemetry/status
inbound through the existing `TelemetryProcessor`, commands outbound through a
real `MqttPublisherInterface` implementation. Work happened **only** in
`PET-Recycling-Filament-System-Backend`, local commits only, nothing pushed.

## Git log (branch `feat/mqtt-integration`, 9 commits)

```
829b11d docs(mqtt): wire contract, additive API changes and phase status
210ebcc test(mqtt): cover publisher, dispatch, handler, API contract and broker
c92b1b3 refactor(mqtt): select the transport through MqttConfig and expose the selector
a481139 feat(mqtt): consume inbound telemetry, status and events from the broker
3a0a45f feat(mqtt): real broker publisher selected by MQTT_ENABLED
00e8b99 fix(test): make the test-schema probe actually probe
03d52bb feat(mqtt): php-mqtt/client behind our own connection/subscriber seams
1cdfacc feat(mqtt): local Mosquitto dev broker with per-device ACL
e6b7ae9 fix(test): make api-contract command-list assertion line-ending agnostic
```

Total: 40 files changed, +3672 / −105.

## Verification (before → after)

| Check | Baseline (pre-branch) | Final (this branch) |
|---|---|---|
| `php bin/phpunit` | 52 tests / 347 assertions, **1 pre-existing failure** (CRLF assertion, fixed in `e6b7ae9`) | **76 tests / 457 assertions, 0 failures**, 2 skipped (broker integration — needs `MQTT_TEST_HOST`) |
| `vendor/bin/phpstan analyse` (level 6) | OK, no errors | OK, no errors, **no new baseline entries** |
| Repeat runs | schema probe always failed → ~75 s + full rebuild | probe fixed (`00e8b99`) → ~5–12 s; 3 consecutive green runs (dirty tree, `config/reference.php` present) |
| `php -l` on all new/changed files | — | clean |

Hard rules honoured: no broker/consumer/HTTP probes run for verification (all
verification is `phpunit` / `phpstan` / `php -l`), no secrets printed, no
assertions weakened, additive-only HTTP changes documented in
`docs/api-contract.md`, every problem resolved within the 3-attempt budget.

## Library choice: `php-mqtt/client ^2.3`

Candidates considered; chosen **php-mqtt/client** (Marvin Mall, MIT,
v2.3.2, PHP ^8.0, psr/log ^1|^2|^3): QoS 0/1/2, Last Will, TLS, reconnect +
loop control (`loopOnce`, `interrupt`, loop-event handlers), PSR-3 logging,
active releases through Dec 2025. Alternatives: php-mqtt/client competitors
either lack QoS2/Last Will, are unmaintained, or are protocol implementations
rather than clients. It is wrapped behind **our** seams
(`MqttConnectionInterface`, `MqttSubscriberInterface`) so the socket stays one
injection away in tests.

## Findings (issue → how verified → fix → commit)

| # | Issue | Verified | Fix | Commit |
|---|---|---|---|---|
| 1 | api-contract command-list assertion `$`-anchored regex failed on CRLF checkout | baseline phpunit failure | normalize `"\r\n"`→`"\n"` before match (line-ending agnostic) | `e6b7ae9` |
| 2 | Test schema probe passed SQL via non-existent `dbal:run-sql --sql` option → always exit≠0 → full test-DB rebuild every run (~75 s) | probe exit 0 = ready, 7 = missing table | positional `['dbal:run-sql', 'SELECT …']` args | `00e8b99` |
| 3 | `MqttTopicBuilder::parse()` read `$segments[2]` on a 2-element array — PHP warning on 9 tests; every consumed message dropped | phpstan level 6 error + phpunit failures | `$segments[1]` | `a481139` |
| 4 | Rate guard used min-interval (500 ms) semantics — two back-to-back messages got rate-limited, contradicting `MQTT_TELEMETRY_MAX_PER_SECOND` ("per second") | phpunit: 2 tests failed with 0/1 rows | sliding 1-second window (max N per rolling second, bounded per-machine map) | `a481139` |
| 5 | New tests: `assertSame(42, (float)…)` int/float strict-compare; `pause` dispatched to an Idle machine (guard correctly rejects state violations) | phpunit | corrected expectations to the actual contract (42.0; `start` from Idle) | `210ebcc` |
| 6 | `SelectedMqttPublisher` took a raw bool (duplicating `MqttConfig`) and was single-use → inlined, so tests could not swap the transport for the 503 case | test-container inspection | constructor takes `MqttConfig`; service marked `public` deliberately | `c92b1b3` |

## Unresolved / deliberately out of scope

* `config/reference.php` shows as modified — **not changed by this session**
  (another actor); left unstaged and uncommitted.
* TLS on the broker: **not implemented** (dev broker is plaintext,
  `127.0.0.1`-only). Documented as required before any deployment.
* `deviceAcknowledged` stays `false` until firmware acknowledgement exists —
  honest by design.
* `events` branch is validated + logged only (no side effects yet).
* No `docs/ARCHITECTURE.md` exists in this repo; MQTT architecture is
  documented in `docs/api-contract.md` (diagram) + `docs/mqtt-contract.md`
  instead of creating a new top-level document.
* Broker integration test is skipped unless `MQTT_TEST_HOST` is set.
* Nothing pushed (per instructions).

## MANUAL TEST CHECKLIST (requires Docker + broker — NOT run here)

All automated verification above ran without a broker. The following steps
prove the live wiring and are **manual**; none were executed in this session.

- [ ] **(a)** Start the broker:
      `docker compose -f compose.mqtt.yaml up -d`
      (`docker ps` → `3awedlou-mosquitto` running, `127.0.0.1:1883`).
- [ ] **(b)** Create users (password never printed/committed):
      `.\bin\mqtt-user.ps1 backend`
      `.\bin\mqtt-user.ps1 <fixture-identifier>` (identifier of an existing
      machine, e.g. from fixtures — NOT the UUID). Restart the broker if it
      was already up: `docker compose -f compose.mqtt.yaml restart mosquitto`.
- [ ] **(c)** Turn the transport on in `.env.local` (never committed):
      `MQTT_ENABLED=true`, `MQTT_USERNAME=backend`, `MQTT_PASSWORD=…`;
      restart `symfony serve`.
- [ ] **(d)** Start the consumer: `php bin/console app:mqtt:consume`
      (stays connected; logs `Database connection re-established` noise-free).
- [ ] **(e)** **Manual telemetry test** — publish as the device:
      `mosquitto_pub -h 127.0.0.1 -p 1883 -u <identifier> -P "$MQTT_DEVICE_PASSWORD" -t "3awedlou/machines/<identifier>/telemetry" -m '{"temperature":192.4,"targetTemperature":245.0,"status":"extruding"}'`
      → consumer logs the sample; a new `machine_telemetry` row exists;
      `lastSeenAt` refreshed (GET `/api/machines/{id}`).
- [ ] **(f)** **Manual command test** — subscribe as the device
      (`mosquitto_sub … -t "3awedlou/machines/<identifier>/commands" -v`),
      then `POST /api/machines/{id}/commands {"command":"start"}` as an
      authenticated user → 202 with `delivery: published_to_broker`, and the
      subscriber sees the versioned envelope (`v`, `commandId`, `expiresAt`).
- [ ] **(g)** **ACL rejection** — publish to *another* machine's topic with
      this device's credentials → broker must answer "not authorised"
      (Mosquitto denies when no ACL rule matches).
- [ ] **(h)** **Broker down → honest failure** —
      `docker compose -f compose.mqtt.yaml stop`, then send a command →
      **503 `MQTT_UNAVAILABLE`** (not a 202), audit row transport
      `mqtt-unavailable`; consumer reconnects with backoff once the broker
      returns.

Optional automated equivalent of (e)–(f):
`$env:MQTT_TEST_HOST='127.0.0.1'; $env:MQTT_TEST_USERNAME='backend'; $env:MQTT_TEST_PASSWORD='…'`
then `php bin/phpunit` runs `MqttBrokerIntegrationTest` instead of skipping it.

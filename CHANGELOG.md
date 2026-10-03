# Changelog

Notable changes to the 3awedlou backend, newest first.
Format based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased] — MQTT transport (phase 3)

### Added

- **Real broker publisher**: `MqttPublisherInterface` resolves through
  `SelectedMqttPublisher` — `MQTT_ENABLED=true` publishes commands to Mosquitto
  (`BrokerMqttPublisher`), `false` (default) keeps the historical in-memory
  buffering logger. A broker failure becomes **503 `MQTT_UNAVAILABLE`** with
  audit transport `mqtt-unavailable`, never a fake 202.
- **MQTT consumer**: `app:mqtt:consume` subscribes to the three inbound
  branches and feeds every message through `MqttMessageHandler` → the *same*
  `TelemetryProcessor` as HTTP ingest, with size/JSON/machine-existence checks,
  a per-machine sliding-window rate guard (`MQTT_TELEMETRY_MAX_PER_SECOND`),
  Last Will `status {"online":bool}` handling via `MachineConnectivityService`,
  backoff reconnect, `--max-runtime` / `--memory-limit` and a counter summary.
- **Command envelope** on the wire: versioned (`v`), correlated (`commandId`
  = audit row id) and expirable (`issuedAt`/`expiresAt`,
  `MQTT_COMMAND_TTL_SECONDS`). The 202 response now carries `commandId`,
  `delivery` (`published_to_broker` | `buffered_not_sent`),
  `deviceAcknowledged`, `sentAt`, `expiresAt` — all additive.
- **Local Mosquitto dev broker**: `compose.mqtt.yaml` + `docker/mosquitto/*`
  with per-device ACL (username = `Machine.identifier`, patterns pin each
  device to its own topic branch), `bin/mqtt-user.ps1`/`.sh` for hashed
  credentials in a git-ignored `passwd` file. Loopback-only, no TLS — dev only.
- **Library**: `php-mqtt/client ^2.3` behind our own seams
  (`MqttConnectionInterface` / `MqttSubscriberInterface`), so the socket stays
  one injection away in tests.
- **Tests**: `tests/IoT/*` — publisher/dispatch/handler/API-contract coverage
  without a broker, plus an opt-in real-broker integration test
  (`MQTT_TEST_HOST`).
- **Docs**: `docs/mqtt-contract.md` (full wire contract), MQTT sections in
  `docs/api-contract.md` and `README.md`.

### Changed

- `SelectedMqttPublisher` reads `MqttConfig` instead of a raw bool (one source
  of truth for the transport switch) and is deliberately public so tests can
  swap/inspect it.
- `MachineConnectivityService` is the only writer of connectivity state:
  new race-safe `markSeen()` / `markOffline()`; `TelemetryProcessor` and the
  MQTT handler both go through them (`offline` remains derived, never stored).

### Fixed

- The api-contract command-list assertion is now line-ending agnostic (CRLF
  checkouts made the `$`-anchored regex fail).
- The test-schema probe passed SQL through a non-existent `dbal:run-sql`
  option, so it always failed and rebuilt the test database on every run; it
  now uses the positional argument (suite ~75 s → ~5 s).

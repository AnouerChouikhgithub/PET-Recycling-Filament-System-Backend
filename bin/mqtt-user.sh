#!/usr/bin/env sh
#
# Create or update a Mosquitto broker user for the 3awedlou local dev broker.
#
# Runs mosquitto_passwd INSIDE the broker container (or a one-shot container
# when the broker is not running yet), so the hashed password file stays on
# the host at docker/mosquitto/passwd — which is GIT-IGNORED.
#
# Security rules honoured here:
#   * the password comes from the MQTT_USER_PASSWORD environment variable or
#     from a hidden prompt (terminal echo disabled while reading); it is never
#     printed, never logged and never placed in a host-side command line —
#     only piped over stdin into the container;
#   * nothing is written to a git-tracked file.
#
# Usage:
#   MQTT_USER_PASSWORD=... sh bin/mqtt-user.sh <username>
#   sh bin/mqtt-user.sh <username>      # prompts, echo disabled
#
# <username> MUST be Machine.identifier (e.g. 3awedlou-001) for a device,
# because the broker ACL pins each device to {MQTT_PREFIX}/machines/%u/...
# The backend's own user is called `backend`.

set -eu

USERNAME="${1:-}"

case "$USERNAME" in
    '')      echo "usage: sh bin/mqtt-user.sh <username>" >&2; exit 2 ;;
    [a-zA-Z0-9]*) ;;
    *)       echo "Invalid username '$USERNAME': must start with a letter or digit." >&2; exit 2 ;;
esac
case "$USERNAME" in
    *[!a-zA-Z0-9_-]*) echo "Invalid username '$USERNAME': letters, digits, dash or underscore only." >&2; exit 2 ;;
esac
if [ "${#USERNAME}" -gt 64 ]; then
    echo 'Invalid username: at most 64 characters.' >&2
    exit 2
fi

CONTAINER_NAME="${MQTT_CONTAINER_NAME:-3awedlou-mosquitto}"
IMAGE="${MQTT_IMAGE:-eclipse-mosquitto:2}"

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
REPO_ROOT=$(dirname -- "$SCRIPT_DIR")
CONFIG_DIR="$REPO_ROOT/docker/mosquitto"

# --- password: env var first, hidden prompt otherwise -----------------------
if [ -z "${MQTT_USER_PASSWORD:-}" ]; then
    if [ ! -t 0 ]; then
        echo 'MQTT_USER_PASSWORD is required when stdin is not a terminal.' >&2
        exit 2
    fi
    printf 'Password for MQTT user %s: ' "$USERNAME" >&2
    stty -echo 2>/dev/null || true
    IFS= read -r MQTT_USER_PASSWORD || true
    stty echo 2>/dev/null || true
    printf '\n' >&2
fi

[ -n "${MQTT_USER_PASSWORD:-}" ] || {
    echo 'Refusing to create a broker user with an empty password.' >&2
    exit 2
}

RUNNING=0
if docker ps --format '{{.Names}}' | grep -Fxq "$CONTAINER_NAME"; then
    RUNNING=1
fi

# The password travels on stdin only: never as an argument on this machine.
if [ "$RUNNING" -eq 1 ]; then
    echo "Updating broker user '$USERNAME' in running container $CONTAINER_NAME ..."
    printf '%s\n' "$MQTT_USER_PASSWORD" \
        | docker exec -i "$CONTAINER_NAME" sh -c 'IFS= read -r P; exec mosquitto_passwd -b /mosquitto/config/passwd "$1" "$P"' sh "$USERNAME"
else
    echo "Container '$CONTAINER_NAME' is not running — creating docker/mosquitto/passwd with a one-shot container ..."
    printf '%s\n' "$MQTT_USER_PASSWORD" \
        | docker run --rm -i -v "$CONFIG_DIR:/mosquitto/config" "$IMAGE" sh -c 'IFS= read -r P; exec mosquitto_passwd -b /mosquitto/config/passwd "$1" "$P"' sh "$USERNAME"
fi
STATUS=$?

MQTT_USER_PASSWORD=''
if [ "$STATUS" -ne 0 ]; then
    echo "mosquitto_passwd failed (exit code $STATUS)." >&2
    exit "$STATUS"
fi

echo "OK: user '$USERNAME' written to docker/mosquitto/passwd (hashed, git-ignored)."
if [ "$RUNNING" -eq 1 ]; then
    echo 'NOTE: Mosquitto loads the password file at startup — restart the broker to pick up the change:'
    echo '      docker compose -f compose.mqtt.yaml restart mosquitto'
fi

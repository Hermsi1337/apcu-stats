#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="127.0.0.1"
PORT="8080"
BASE_URL="http://${HOST}:${PORT}"
TOKEN="apcu-stats-test-token"

COOKIE_JAR="$(mktemp)"
SERVER_LOG="$(mktemp)"
SERVER_PID=""

cleanup() {
    stop_server
    rm -f "$COOKIE_JAR" "$SERVER_LOG"
}

fail() {
    local message="$1"
    echo "FAIL: ${message}" >&2
    if [[ -f "$SERVER_LOG" ]]; then
        echo "--- PHP server log ---" >&2
        tail -n 120 "$SERVER_LOG" >&2 || true
    fi
    exit 1
}

assert_contains() {
    local haystack="$1"
    local needle="$2"
    local message="$3"
    if [[ "$haystack" != *"$needle"* ]]; then
        fail "${message} (missing '${needle}')"
    fi
}

assert_not_contains() {
    local haystack="$1"
    local needle="$2"
    local message="$3"
    if [[ "$haystack" == *"$needle"* ]]; then
        fail "${message} (unexpected '${needle}')"
    fi
}

wait_for_server() {
    local status
    for _ in $(seq 1 80); do
        status="$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/apcu-stats.php" 2>/dev/null || true)"
        if [[ "$status" != "000" ]]; then
            return
        fi
        sleep 0.2
    done
    fail "PHP built-in server did not become reachable."
}

stop_server() {
    if [[ -n "${SERVER_PID}" ]] && kill -0 "${SERVER_PID}" 2>/dev/null; then
        kill "${SERVER_PID}" >/dev/null 2>&1 || true
        wait "${SERVER_PID}" >/dev/null 2>&1 || true
    fi
    SERVER_PID=""
}

start_server() {
    local user="${1:-}"
    local pass="${2:-}"

    stop_server

    if [[ -n "$user" || -n "$pass" ]]; then
        APCU_STATS_TEST_TOKEN="${TOKEN}" \
        APCU_STATS_USER="${user}" \
        APCU_STATS_PASS="${pass}" \
        php -d apc.enable_cli=1 -S "${HOST}:${PORT}" -t "${ROOT_DIR}" >"${SERVER_LOG}" 2>&1 &
    else
        APCU_STATS_TEST_TOKEN="${TOKEN}" \
        php -d apc.enable_cli=1 -S "${HOST}:${PORT}" -t "${ROOT_DIR}" >"${SERVER_LOG}" 2>&1 &
    fi

    SERVER_PID=$!
    wait_for_server
}

call_helper() {
    local action="$1"
    local body
    body="$(curl -fsS "${BASE_URL}/tests/test-api.php?token=${TOKEN}&action=${action}")"
    assert_contains "$body" '"ok":true' "Helper action '${action}' failed"
}

extract_csrf() {
    local html="$1"
    local token
    token="$(printf '%s' "$html" | grep -oE 'name="csrf_token" value="[^"]+"' | head -n1 | cut -d'"' -f4)"
    if [[ -z "$token" ]]; then
        fail "Unable to extract CSRF token from dashboard response."
    fi
    printf '%s' "$token"
}

trap cleanup EXIT

echo "Running APCu dashboard integration tests..."

start_server
call_helper clear
call_helper seed

page="$(curl -fsS -c "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_contains "$page" "APCu Stats" "Dashboard did not render."
assert_contains "$page" "app:user:1" "Seed key app:user:1 is missing."
assert_contains "$page" "app:user:2" "Seed key app:user:2 is missing."
assert_contains "$page" "session:alpha" "Seed key session:alpha is missing."

filtered="$(curl -fsS "${BASE_URL}/apcu-stats.php?q=app%3Auser%3A&limit=1000")"
assert_contains "$filtered" "app:user:1" "Filter did not keep app:user:1."
assert_contains "$filtered" "app:user:2" "Filter did not keep app:user:2."
assert_not_contains "$filtered" "session:alpha" "Filter unexpectedly included session:alpha."

csrf_token="$(extract_csrf "$page")"

delete_response="$(curl -fsS -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=${csrf_token}" \
    --data-urlencode "action=delete" \
    --data-urlencode "key=app:user:1" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$delete_response" "Deleted key" "Delete action did not report success."
assert_contains "$delete_response" "app:user:1" "Delete action did not reference the expected key."

after_delete="$(curl -fsS -b "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_not_contains "$after_delete" "app:user:1" "Deleted key app:user:1 still present."
assert_contains "$after_delete" "app:user:2" "Remaining key app:user:2 disappeared unexpectedly."

invalid_csrf_response="$(curl -fsS -b "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=invalid-token" \
    --data-urlencode "action=clear" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$invalid_csrf_response" "Invalid CSRF token." "Invalid CSRF was not detected."

csrf_token_after_delete="$(extract_csrf "$after_delete")"
clear_response="$(curl -fsS -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=${csrf_token_after_delete}" \
    --data-urlencode "action=clear" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$clear_response" "APCu cache cleared." "Clear cache action did not report success."

after_clear="$(curl -fsS -b "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_contains "$after_clear" "No entries found." "Cache was not empty after clear."

stop_server

start_server "admin" "secret"

status_without_auth="$(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}/apcu-stats.php")"
if [[ "$status_without_auth" != "401" ]]; then
    fail "Expected 401 without auth, got ${status_without_auth}."
fi

status_with_auth="$(curl -sS -u "admin:secret" -o /dev/null -w '%{http_code}' "${BASE_URL}/apcu-stats.php")"
if [[ "$status_with_auth" != "200" ]]; then
    fail "Expected 200 with valid auth, got ${status_with_auth}."
fi

echo "All integration tests passed."

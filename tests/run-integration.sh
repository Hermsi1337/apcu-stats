#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOST="127.0.0.1"
PORT="${APCU_STATS_TEST_PORT:-8080}"
BASE_URL="http://${HOST}:${PORT}"
TOKEN="apcu-stats-test-token"

COOKIE_JAR="$(mktemp)"
SERVER_LOG="$(mktemp)"
SERVER_PID=""
AUTH_ROOT=""

cleanup() {
    stop_server
    rm -f "$COOKIE_JAR" "$SERVER_LOG"
    if [[ -n "$AUTH_ROOT" && -d "$AUTH_ROOT" ]]; then
        rm -rf "$AUTH_ROOT"
    fi
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
    local doc_root="${1:-$ROOT_DIR}"

    stop_server

    APCU_STATS_TEST_TOKEN="${TOKEN}" \
    php -d apc.enable_cli=1 -S "${HOST}:${PORT}" -t "${doc_root}" >"${SERVER_LOG}" 2>&1 &

    SERVER_PID=$!
    wait_for_server
}

call_helper() {
    local action="$1"
    local body
    body="$(curl -fsS "${BASE_URL}/tests/test-api.php?token=${TOKEN}&action=${action}")"
    assert_contains "$body" '"ok":true' "Helper action '${action}' failed"
}

assert_key_exists() {
    local key="$1"
    local expected="$2"
    local body
    body="$(curl -fsS "${BASE_URL}/tests/test-api.php?token=${TOKEN}&action=exists&key=${key}")"
    assert_contains "$body" '"ok":true' "Helper action 'exists' failed for key '${key}'"
    assert_contains "$body" "\"exists\":${expected}" "Unexpected existence state for key '${key}'"
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

prepare_auth_fixture() {
    AUTH_ROOT="$(mktemp -d)"
    mkdir -p "${AUTH_ROOT}/tests"
    cp "${ROOT_DIR}/apcu-stats.php" "${AUTH_ROOT}/apcu-stats.php"
    cp "${ROOT_DIR}/tests/test-api.php" "${AUTH_ROOT}/tests/test-api.php"

    sed -i "s/const APCU_STATS_EDIT_USER = '';/const APCU_STATS_EDIT_USER = 'admin';/" "${AUTH_ROOT}/apcu-stats.php"
    sed -i "s/const APCU_STATS_EDIT_PASS = '';/const APCU_STATS_EDIT_PASS = 'secret';/" "${AUTH_ROOT}/apcu-stats.php"
}

trap cleanup EXIT

echo "Running APCu dashboard integration tests..."

# Phase 1: default statistics-only mode (no credentials configured)
start_server
call_helper clear
call_helper seed
assert_key_exists "app:user:1" "true"
assert_key_exists "app:user:2" "true"
assert_key_exists "session:alpha" "true"

page="$(curl -fsS -c "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_contains "$page" "APCu Stats" "Dashboard did not render."
assert_contains "$page" "Statistics-only mode is active" "Statistics-only mode note is missing."
assert_not_contains "$page" "Search key" "Search UI must be hidden in statistics-only mode."
assert_not_contains "$page" "app:user:1" "Key app:user:1 must be hidden in statistics-only mode."
assert_not_contains "$page" "app:user:2" "Key app:user:2 must be hidden in statistics-only mode."
assert_not_contains "$page" "session:alpha" "Key session:alpha must be hidden in statistics-only mode."

filtered="$(curl -fsS "${BASE_URL}/apcu-stats.php?q=app%3Auser%3A&limit=1000")"
assert_contains "$filtered" "Statistics-only mode is active" "Filtered stats-only response missing mode note."
assert_not_contains "$filtered" "app:user:1" "Filtered view must not reveal keys."
assert_not_contains "$filtered" "app:user:2" "Filtered view must not reveal keys."
assert_not_contains "$filtered" "session:alpha" "Filtered view must not reveal keys."

delete_response="$(curl -fsS -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=invalid-token" \
    --data-urlencode "action=delete" \
    --data-urlencode "key=app:user:1" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$delete_response" "Statistics-only mode is active" "Statistics-only delete rejection is missing."

clear_response="$(curl -fsS -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=invalid-token" \
    --data-urlencode "action=clear" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$clear_response" "Statistics-only mode is active" "Statistics-only clear rejection is missing."

after_attempts="$(curl -fsS -b "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_not_contains "$after_attempts" "app:user:1" "Statistics-only mode must continue hiding keys."
assert_not_contains "$after_attempts" "app:user:2" "Statistics-only mode must continue hiding keys."
assert_not_contains "$after_attempts" "session:alpha" "Statistics-only mode must continue hiding keys."
assert_key_exists "app:user:1" "true"
assert_key_exists "app:user:2" "true"
assert_key_exists "session:alpha" "true"

status_auth_prompt="$(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}/apcu-stats.php?auth=1")"
if [[ "$status_auth_prompt" != "200" ]]; then
    fail "Expected 200 for ?auth=1 when no edit credentials are configured, got ${status_auth_prompt}."
fi

# Phase 2: credentials configured in-file + authenticated entry/write access
prepare_auth_fixture
start_server "${AUTH_ROOT}"
call_helper clear
call_helper seed

unauth_page="$(curl -fsS "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_contains "$unauth_page" "Statistics-only mode is active for unauthenticated requests." "Expected unauthenticated statistics-only notice is missing."
assert_not_contains "$unauth_page" "Search key" "Search UI must stay hidden without authentication."
assert_not_contains "$unauth_page" "app:user:1" "Keys must remain hidden without authentication when credentials are configured."

status_auth_prompt_locked="$(curl -sS -o /dev/null -w '%{http_code}' "${BASE_URL}/apcu-stats.php?auth=1")"
if [[ "$status_auth_prompt_locked" != "401" ]]; then
    fail "Expected 401 for ?auth=1 with configured credentials, got ${status_auth_prompt_locked}."
fi

status_auth_prompt_wrong_creds="$(curl -sS -u "admin:wrong" -o /dev/null -w '%{http_code}' "${BASE_URL}/apcu-stats.php?auth=1")"
if [[ "$status_auth_prompt_wrong_creds" != "401" ]]; then
    fail "Expected 401 for ?auth=1 with wrong credentials, got ${status_auth_prompt_wrong_creds}."
fi

auth_page="$(curl -fsS -u "admin:secret" -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?auth=1&limit=1000")"
assert_contains "$auth_page" "Search key" "Search UI should be available after authentication."
assert_contains "$auth_page" "app:user:1" "Seed key app:user:1 should be visible after authentication."
assert_contains "$auth_page" "app:user:2" "Seed key app:user:2 should be visible after authentication."
assert_contains "$auth_page" "session:alpha" "Seed key session:alpha should be visible after authentication."

filtered_auth="$(curl -fsS -u "admin:secret" "${BASE_URL}/apcu-stats.php?q=app%3Auser%3A&limit=1000")"
assert_contains "$filtered_auth" "app:user:1" "Authenticated filter did not keep app:user:1."
assert_contains "$filtered_auth" "app:user:2" "Authenticated filter did not keep app:user:2."
assert_not_contains "$filtered_auth" "session:alpha" "Authenticated filter unexpectedly included session:alpha."

status_write_without_auth="$(curl -sS -o /dev/null -w '%{http_code}' -X POST \
    --data-urlencode "csrf_token=invalid-token" \
    --data-urlencode "action=clear" \
    "${BASE_URL}/apcu-stats.php")"
if [[ "$status_write_without_auth" != "401" ]]; then
    fail "Expected 401 for write POST without auth when credentials are configured, got ${status_write_without_auth}."
fi

csrf_token="$(extract_csrf "$auth_page")"

invalid_csrf_response="$(curl -fsS -u "admin:secret" -b "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=invalid-token" \
    --data-urlencode "action=clear" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$invalid_csrf_response" "Invalid CSRF token." "Invalid CSRF was not detected for authenticated write action."

delete_response_auth="$(curl -fsS -u "admin:secret" -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=${csrf_token}" \
    --data-urlencode "action=delete" \
    --data-urlencode "key=app:user:1" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$delete_response_auth" "Deleted key" "Delete action did not report success in authenticated mode."
assert_key_exists "app:user:1" "false"

after_delete_auth="$(curl -fsS -u "admin:secret" -b "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_not_contains "$after_delete_auth" "app:user:1" "Deleted key app:user:1 still visible in authenticated mode."

csrf_token_after_delete="$(extract_csrf "$after_delete_auth")"
clear_response_auth="$(curl -fsS -u "admin:secret" -b "${COOKIE_JAR}" -c "${COOKIE_JAR}" -X POST \
    --data-urlencode "csrf_token=${csrf_token_after_delete}" \
    --data-urlencode "action=clear" \
    "${BASE_URL}/apcu-stats.php")"
assert_contains "$clear_response_auth" "APCu cache cleared." "Clear cache action did not report success in authenticated mode."

assert_key_exists "app:user:2" "false"
assert_key_exists "session:alpha" "false"

after_clear_auth="$(curl -fsS -u "admin:secret" -b "${COOKIE_JAR}" "${BASE_URL}/apcu-stats.php?limit=1000")"
assert_contains "$after_clear_auth" "No entries found." "No entries message missing after clear in authenticated mode."

echo "All integration tests passed."

# APCu Stats

Single-file APCu dashboard for modern PHP versions.

`apcu-stats.php` provides:
- APCu health/status metrics (hits, misses, rates, memory, fragmentation)
- cache entry browser with search, sorting, and limit
- delete single key
- clear entire APCu cache
- optional HTTP Basic Auth via environment variables

## Requirements

- PHP 8.1+ (tested with PHP 8.3)
- APCu extension enabled (`apc.enabled=1`)
- for CLI server/testing: `apc.enable_cli=1`

## Quick Start

1. Place `apcu-stats.php` in a web-accessible directory.
2. Open it in your browser, for example: `http://localhost/apcu-stats.php`
3. (Recommended) Protect access with server auth or the built-in env-based auth:
   - `APCU_STATS_USER`
   - `APCU_STATS_PASS`

Example (temporary, local):

```bash
APCU_STATS_USER=admin APCU_STATS_PASS=change-me php -d apc.enable_cli=1 -S 127.0.0.1:8080 -t .
```

Then open `http://127.0.0.1:8080/apcu-stats.php`.

## URL Parameters

- `q`: key search filter (substring)
- `sort`: `hits|size|ttl|created|access|key`
- `dir`: `asc|desc`
- `limit`: number of rows (`10-1000`)

Example:

```text
/apcu-stats.php?q=user:&sort=size&dir=desc&limit=200
```

## Actions

- `Delete` button per key: removes one APCu key
- `Clear cache`: clears complete user cache
- both actions are protected by CSRF token

## Docker

### Run dashboard

```bash
docker compose up --build apcu-stats
```

Open `http://127.0.0.1:8080/apcu-stats.php`.

The compose service sets default Basic Auth:
- username: `admin`
- password: `change-me`

You can override them:

```bash
APCU_STATS_USER=myuser APCU_STATS_PASS=mypassword docker compose up --build apcu-stats
```

## Tests

Integration tests are included and cover:
- dashboard render
- seeded entry listing
- filtering/search
- CSRF validation failure
- delete key flow
- clear cache flow
- optional Basic Auth behavior (401 without auth, 200 with auth)

### Run tests in Docker (recommended)

```bash
docker compose run --rm test
```

### Run tests locally (without Docker)

You need local PHP CLI with APCu enabled:

```bash
bash tests/run-integration.sh
```

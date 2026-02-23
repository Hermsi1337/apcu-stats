# APCu Stats

Single-file APCu dashboard for modern PHP versions.

`apcu-stats.php` provides:
- APCu health/status metrics (hits, misses, rates, memory, fragmentation)
- cache entry browser with search, sorting, and limit (only with authenticated edit credentials)
- delete single key
- clear entire APCu cache
- statistics-only default mode unless edit credentials are configured in the file

## Requirements

- PHP 8.1+ (tested with PHP 8.5)
- APCu extension enabled (`apc.enabled=1`)
- for CLI server/testing: `apc.enable_cli=1`

## Quick Start

1. Place `apcu-stats.php` in a web-accessible directory.
2. Open it in your browser, for example: `http://localhost/apcu-stats.php`
3. Optional: enable write actions by setting constants inside `apcu-stats.php`:
   - `APCU_STATS_EDIT_USER`
   - `APCU_STATS_EDIT_PASS`

Example (temporary, local):

```bash
php -d apc.enable_cli=1 -S 127.0.0.1:8080 -t .
```

Then open `http://127.0.0.1:8080/apcu-stats.php`.

## Edit Access

- By default (`APCU_STATS_EDIT_USER` / `APCU_STATS_EDIT_PASS` empty), dashboard runs in statistics-only mode.
- In statistics-only mode, key browsing/search and all write actions are hidden or server-side blocked.
- If credentials are configured, open `?auth=1` once to trigger HTTP Basic Auth in the browser, then entry browsing and write actions are unlocked.
- On some shared hosting (CGI/FastCGI), PHP does not receive Basic Auth variables automatically.
  In that case, add this to `.htaccess` in the same directory:

```apache
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]
```

## URL Parameters

- The parameters below are only effective after successful authentication (`?auth=1`).
- `q`: key search filter (substring)
- `sort`: `hits|size|ttl|created|access|key`
- `dir`: `asc|desc`
- `limit`: number of rows (`10-1000`)
- `refresh`: auto refresh interval in seconds (`0|5|10|30|60|120|300`)

Example:

```text
/apcu-stats.php?q=user:&sort=size&dir=desc&limit=200
```

## Actions

- `Delete` button per key: removes one APCu key
- `Clear cache`: clears complete user cache
- both actions are protected by CSRF token
- without configured and authenticated edit credentials, entry browsing and write actions are locked

## Notes

- The dashboard includes two dedicated info tiles in the stats grid:
  - `Scope`: shows that APCu metrics are per worker process
  - `Worker Status`: indicates whether the current worker started recently
- APCu `uptime` and visible entries are per PHP worker process, not global for all workers.
- On shared hosting, requests may hit different workers or restarted workers, so uptime can jump/reset and entry lists can differ between reloads.
- If cache fills up, APCu can evict entries automatically (`expunges` increases); this is expected behavior.

## Docker

### Run dashboard

```bash
docker compose up --build apcu-stats
```

Open `http://127.0.0.1:8080/apcu-stats.php`.

To allow write actions in Docker, edit `apcu-stats.php` and set `APCU_STATS_EDIT_USER` + `APCU_STATS_EDIT_PASS`.

## Tests

Integration tests are included and cover:
- dashboard render
- seeded entry listing
- statistics-only lock for key browsing/search
- statistics-only lock behavior for write actions
- server-side rejection of write POST requests in statistics-only mode
- auth prompt behavior (`?auth=1`) with and without configured credentials
- auth prompt rejection with wrong credentials
- authenticated entry browsing/search visibility
- authenticated delete, clear, and CSRF validation flows

### Run tests in Docker (recommended)

```bash
docker compose run --rm test
```

### Run tests locally (without Docker)

You need local PHP CLI with APCu enabled:

```bash
bash tests/run-integration.sh
```

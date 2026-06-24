# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this project does

A PHP CLI script that pulls DICT-company attendance data from BigQuery, renders it as an HTML table, converts that to JPEG(s) via `wkhtmltoimage`, and sends them to a Telegram channel. It runs unattended four times a day via cron at Manila time.

## Running the script

```bash
# Auto-detect window and mode (only sends if within 15 min of a scheduled time)
php bigquery_report.php

# Force a specific window — always sends regardless of wall-clock time
php bigquery_report.php --start=07:00 --end=now --mode=regular
php bigquery_report.php --start=19:00 --end=now --mode=regular
php bigquery_report.php --start=04:00 --end=now --mode=out
php bigquery_report.php --start=16:00 --end=now --mode=out
```

`--end` defaults to `now`; `--mode` defaults to `regular`. Both require `--start`.

## Dependencies

- PHP 8.1+ (`never` return type and `match` are used — verify with `php -v`)
- `php-curl` extension
- `wkhtmltoimage` at `/usr/bin/wkhtmltoimage` (Linux) — if it needs an X server, install `xvfb` and run via `xvfb-run`
- Outbound HTTPS to `googleapis.com` and `api.telegram.org`

## Credential files (gitignored — copy directly to server)

- `BigQueryCredentials.JSON` — GCP service-account key, must sit next to `bigquery_report.php`
- `telegram-config.php` — defines `TELEGRAM_BOT_TOKEN` and `TELEGRAM_CHAT_ID` constants

## Architecture

### `bigquery_report.php` (main entry point)

All core logic lives here:

1. **Window resolution** — `resolveAutoWindow()` picks `start` time and `mode` from the current hour. `isNearAutoSendTime()` checks whether the run is close enough to a scheduled time to actually send. `resolveReportWindow()` combines both, or uses explicit CLI flags.
2. **SQL injection** — `query.sql` contains a `/*__DIRECTION_FILTER__*/` sentinel. `applyDirectionFilter()` replaces it with the appropriate `AND (...)` fragment for `regular` vs `out` mode before sending to BigQuery.
3. **BigQuery auth** — `getAccessToken()` mints a RS256 JWT from the service-account credentials and exchanges it for an OAuth2 token directly via cURL — no Google SDK involved.
4. **Report rendering** — `renderReportHtml()` builds an HTML table (with continuous row numbers across chunks). `saveHtmlAsImage()` shells out to `wkhtmltoimage`.
5. **Telegram delivery** — rows are chunked at `TELEGRAM_CHUNK_ROW_COUNT = 40`. Each chunk is rendered as a separate JPEG, then sent as a Telegram photo album (batched ≤10 per `sendMediaGroup` call). An archival full-report JPEG is also saved to `images/` independently of the chunked send.

### `telegram-sender.php`

Three public functions:
- `sendTelegramPhotoAlbum(filePaths, caption)` — primary entry point; dispatches to single-photo or media-group as needed
- `sendTelegramMediaGroup(filePaths, caption)` — sends up to 10 photos in one album call
- `sendTelegramImage(filePath, caption)` — single-photo fallback
- `sendTelegramDocument(filePath, ...)` — unused by the main script but available for future CSV/Excel delivery

### `query.sql`

BigQuery SQL for the `anflo-dict-prd` project. Joins `bio_timelog_logistic` → `bio_masterdata` → `dim_machine`. Uses named parameters `@start_time` / `@end_time`. The `/*__DIRECTION_FILTER__*/` sentinel is mandatory — the script will throw if it's missing.

## Cron schedule (Manila time)

```cron
CRON_TZ=Asia/Manila
0 10  * * *  /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
0 22  * * *  /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
30 5  * * *  /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
30 17 * * *  /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
```

The `AUTO_SEND_TIMES` constant in `bigquery_report.php` (`['10:00', '17:30', '22:00', '05:30']`) must stay in sync with this crontab.

## Key constants to know

| Constant | Value | Purpose |
|---|---|---|
| `AUTO_SEND_TOLERANCE_MINUTES` | 15 | Window (±) around scheduled times for auto-send |
| `TELEGRAM_CHUNK_ROW_COUNT` | 40 | Rows per Telegram image chunk |
| `DIRECTION_FILTER_SENTINEL` | `/*__DIRECTION_FILTER__*/` | Placeholder in query.sql |

## Notes on `cacert.pem`

This bundle exists because the Windows dev machine sits behind TLS interception. On Ubuntu, the system CA store (`ca-certificates` package) may already suffice — if HTTPS requests fail with cert errors, that's the first thing to investigate.

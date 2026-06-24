# Ubuntu Deployment Guide

Steps to get `bigquery_report.php` running unattended via cron on the Ubuntu server (no Docker yet — that's separate future work; nothing here is Docker-specific, so it'll still apply if/when containerized).

## 1. Install PHP + required extensions

```bash
sudo apt update
sudo apt install -y php-cli php-curl
php -v   # confirm 8.1+ (the script uses the `never` return type and `match`)
```

## 2. Install wkhtmltoimage

```bash
sudo apt install -y wkhtmltopdf
which wkhtmltoimage   # confirm it's at /usr/bin/wkhtmltoimage
```

`WKHTMLTOIMAGE_PATH` in `bigquery_report.php` already picks `/usr/bin/wkhtmltoimage` automatically on non-Windows via `PHP_OS_FAMILY`. If your install puts the binary somewhere else, update that constant.

**Common gotcha**: wkhtmltoimage needs a display/X server to render. Ubuntu's repo package is usually already patched to not need one — but if you see errors like "cannot connect to X server" during testing, install `xvfb` and run via `xvfb-run`, or switch to the official static binary from wkhtmltopdf.org (patched Qt, no X server needed). Test this in step 5 before relying on cron.

## 3. Place the credentials files

`BigQueryCredentials.JSON` (BigQuery service-account key) and `telegram-config.php` (Telegram bot token + chat id) are excluded from git. Copy both to the server directly, not through git:

```bash
scp BigQueryCredentials.JSON telegram-config.php youruser@server:/path/to/cico/
```

Both must sit right next to `bigquery_report.php` (matches `CREDENTIALS_PATH = __DIR__ . '/BigQueryCredentials.JSON'` and the `require_once __DIR__ . '/telegram-config.php'` in `telegram-sender.php`).

## 4. Check permissions

The user that runs cron needs:
- Read access to `query.sql`, `cacert.pem`, `BigQueryCredentials.JSON`
- Write access to `images/`
- Write access to the system temp dir (used for HTML-to-image temp files and Telegram chunk images)

## 5. Test each mode manually before touching cron

```bash
cd /path/to/cico
php bigquery_report.php --start=07:00 --end=now --mode=regular
php bigquery_report.php --start=19:00 --end=now --mode=regular
php bigquery_report.php --start=04:00 --end=now --mode=out
php bigquery_report.php --start=16:00 --end=now --mode=out
```

For each, confirm: exits 0, a JPEG lands in `images/`, and a photo/album actually arrives in the Telegram channel.

Watch for network egress issues — the server needs outbound HTTPS to `googleapis.com` and `api.telegram.org`. Also worth checking whether the bundled `cacert.pem` is even necessary on this network — that file exists because the Windows dev machine sits behind something doing TLS interception; Ubuntu's own CA store (`ca-certificates` package) might already be fine without it. If a request fails with a cert error, that's the first thing to check.

## 6. Set up cron

Cron needs to fire at Manila wall-clock times regardless of the server's system timezone. Easiest: use `CRON_TZ` instead of changing the whole server's timezone:

```bash
crontab -e
```

```cron
CRON_TZ=Asia/Manila
0 10 * * * /usr/bin/php /path/to/cico/bigquery_report.php --start=07:00 --end=now --mode=regular >> /path/to/cico/cron.log 2>&1
0 22 * * * /usr/bin/php /path/to/cico/bigquery_report.php --start=19:00 --end=now --mode=regular >> /path/to/cico/cron.log 2>&1
30 5 * * * /usr/bin/php /path/to/cico/bigquery_report.php --start=04:00 --end=now --mode=out >> /path/to/cico/cron.log 2>&1
30 17 * * * /usr/bin/php /path/to/cico/bigquery_report.php --start=16:00 --end=now --mode=out >> /path/to/cico/cron.log 2>&1
```

Use full absolute paths everywhere — cron's environment/PATH is minimal. Redirecting to a log file matters since `error_log()` calls in the script otherwise go to PHP's default log destination, which may not be where you expect on a fresh install.

## 7. Let it run once unattended, then check

After the next scheduled fire time, check `cron.log` for any `error_log` output and confirm `images/` got a new file and Telegram got a message — that closes the loop on whether the whole pipeline survives unattended.

# Cron Fix — June 24, 2026

## Problem

The 5:30 PM Manila report was not sent to Telegram.

## Root Cause

Two things happened at the same time:

1. **The crontab was installed after the scheduled time had already passed.**
   - The crontab was saved at **6:04 PM Manila (10:04 UTC)**.
   - The 5:30 PM slot fires at **9:30 UTC** — already 34 minutes gone by the time cron knew about it.
   - Cron does not backfill missed jobs, so it was simply skipped.

2. **A manual test run at 6:00 PM was mistaken for the scheduled run.**
   - The script ran at 18:00:01 Manila, which is 30 minutes past 17:30.
   - Because it was outside the 15-minute tolerance window (`AUTO_SEND_TOLERANCE_MINUTES = 15`), `automated=no` — no image was saved and nothing was sent to Telegram.
   - This is correct script behavior, not a bug.

## Why `CRON_TZ` Was Not Used

The DEPLOYMENT.md originally instructed using `CRON_TZ=Asia/Manila` in the crontab. This works on many Linux systems but **not** on this server's cron build (`3.0pl1-184ubuntu2`). The workaround is to convert Manila times to UTC manually and use those in the crontab directly.

**Manila is UTC+8**, so subtract 8 hours:

| Manila time | UTC equivalent |
|-------------|----------------|
| 10:00 AM    | 02:00          |
| 5:30 PM     | 09:30          |
| 10:00 PM    | 14:00          |
| 5:30 AM     | 21:30 (previous UTC day) |

## What Was Fixed

### 1. Crontab — changed from `CRON_TZ` style to UTC times

The crontab now uses UTC times directly:

```cron
# Times are UTC. Manila = UTC+8.
# 02:00=10:00AM, 09:30=5:30PM, 14:00=10:00PM, 21:30=5:30AM(next day)
0 2 * * * /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
30 9 * * * /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
0 14 * * * /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
30 21 * * * /usr/bin/php /var/www/dict-cico/bigquery_report.php >> /var/www/dict-cico/cron.log 2>&1
```

### 2. DEPLOYMENT.md — updated to document the UTC approach

Replaced the `CRON_TZ=Asia/Manila` instructions with the UTC conversion table and the correct cron entries above.

## Verification Test

To confirm cron was working end-to-end, a temporary test entry was added to fire at **6:25 PM Manila (10:25 UTC)** with `--start=16:00 --end=now --mode=out` to force a send:

```cron
25 10 * * * /usr/bin/php /var/www/dict-cico/bigquery_report.php --start=16:00 --end=now --mode=out >> /var/www/dict-cico/cron.log 2>&1
```

The cron fired at exactly 6:25 PM, the log showed `automated=yes`, and a Telegram photo album with **104 rows** was successfully delivered. The test entry was then removed.

## How to Confirm Future Runs

After any scheduled fire time, check:

```bash
tail -5 /var/www/dict-cico/cron.log
```

A successful automated run looks like:

```
Resolved report window: 2026-06-24 16:00:00 to 2026-06-24 18:25:01, mode=out, automated=yes
```

The key indicator is `automated=yes`. If you see `automated=no`, the script ran outside the 15-minute tolerance window — either a manual run or a cron timing issue.

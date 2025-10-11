# SMS Audit Rotation Setup

This document explains how to set up automated SMS audit table rotation using the `rotate_audit.php` script.

## Overview

The `rotate_audit.php` script automatically prunes old records from SMS audit tables:
- `sms_audit` (inbound SMS records)
- `sms_outbound_audit` (outbound SMS records)
- `message_status` (Twilio status callbacks)

## Configuration

The retention period is configurable via the `sms.audit_retention_days` setting:
- Default: 90 days
- Can be changed via admin settings or database update

## Setup Options

### Option 1: Cron Job (Simple)

Add this line to your crontab (`crontab -e`):

```bash
# Run SMS audit rotation daily at 2 AM
0 2 * * * /path/to/your/project/scripts/rotate_audit.php >> /var/log/sms_audit_rotation.log 2>&1
```

### Option 2: Systemd Timer (Recommended for Production)

Create these two files:

#### `/etc/systemd/system/sms-audit-rotation.service`
```ini
[Unit]
Description=SMS Audit Table Rotation
After=network.target

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/path/to/your/project
ExecStart=/usr/bin/php /path/to/your/project/scripts/rotate_audit.php
StandardOutput=journal
StandardError=journal
```

#### `/etc/systemd/system/sms-audit-rotation.timer`
```ini
[Unit]
Description=Run SMS audit rotation daily
Requires=sms-audit-rotation.service

[Timer]
OnCalendar=daily
Persistent=true

[Install]
WantedBy=timers.target
```

Enable and start the timer:
```bash
sudo systemctl daemon-reload
sudo systemctl enable sms-audit-rotation.timer
sudo systemctl start sms-audit-rotation.timer
```

Check status:
```bash
sudo systemctl list-timers | grep sms-audit
sudo journalctl -u sms-audit-rotation.service
```

## Manual Testing

Test the script manually:
```bash
cd /path/to/your/project
php scripts/rotate_audit.php
```

Expected output:
```
Starting SMS audit rotation - retaining 90 days, cutoff: 2025-07-11T07:14:01+00:00
Table sms_audit: deleted 0 records (expected 0)
Table sms_outbound_audit: deleted 0 records (expected 0)
Table message_status: deleted 0 records (expected 0)
SMS audit rotation completed - total records deleted: 0
```

## Changing Retention Period

Update the setting via database:
```sql
UPDATE settings SET value = '30', updated_at = datetime('now') WHERE key = 'sms.audit_retention_days';
```

Or via the admin interface if available.

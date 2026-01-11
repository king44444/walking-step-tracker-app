# Changelog

## 0.2.3 - 2026-01-11
- Remove raw Twilio auth tokens from sms_bad_sig.log.

## 0.2.2 - 2026-01-11
- Add application audit report in docs.

## 0.2.1 - 2026-01-09
- Add admin awards settings usage audit report in docs.

## 0.2.0 - 2026-01-06
- Add support for optional name prefix when logging steps via SMS
  - New format: `[name?] [day?] <steps>`
  - Examples: `nikki fri 3345`, `Ben 250`
  - Case-insensitive name lookup
  - Anyone can log steps for anyone else (family app, admin-only knowledge)
  - Added `target_user_name` column to `sms_audit` table for tracking
  - Updated tests to cover new parsing logic
- Update deployment script to run Phinx migrations automatically
  - Fixes migration file permissions before running Phinx
  - Runs migrations as web user to avoid permission issues

## 0.1.0 - 2026-01-01
- Update site header messaging to January Walk-a-thon rules.

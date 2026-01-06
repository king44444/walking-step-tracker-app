# Changelog

## 0.2.0 - 2026-01-06
- Add support for optional name prefix when logging steps via SMS
  - New format: `[name?] [day?] <steps>`
  - Examples: `nikki fri 3345`, `Ben 250`
  - Case-insensitive name lookup
  - Anyone can log steps for anyone else (family app, admin-only knowledge)
  - Added `target_user_name` column to `sms_audit` table for tracking
  - Updated tests to cover new parsing logic

## 0.1.0 - 2026-01-01
- Update site header messaging to January Walk-a-thon rules.

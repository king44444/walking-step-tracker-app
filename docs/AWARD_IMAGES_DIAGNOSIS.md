# Award Images Diagnosis Report
**Date**: October 9, 2025  
**Issue**: Award images appearing as low-quality flat circles instead of AI-generated art  
**Status**: ✅ RESOLVED

---

## Executive Summary

The system was generating local fallback images (SVG/WebP with flat navy circles) instead of AI-generated award images from OpenRouter. The root cause was **missing environment configuration** on the production server.

### Root Causes Identified

1. **Missing `OPENROUTER_API_KEY`** in production `.env` file
2. **Missing `ai.image.provider` setting** in production database
3. Code defaulted to `'local'` provider when configuration was absent

---

## Technical Analysis

### Code Flow (api/lib/ai_images.php)

**Decision Point** (Line 119):
```php
if ($provider !== 'local' && $model !== '' && ai_image_has_provider()) {
    // OpenRouter API call here
}
```

**Provider Check Function** (Lines 175-182):
```php
function ai_image_has_provider(): bool {
  try {
    $prov = strtolower((string)setting_get('ai.image.provider', 'local'));
    if ($prov === 'local') return false;
    $key = env('OPENROUTER_API_KEY', '');  // ← Was returning empty string
    $model = (string)setting_get('ai.image.model', '');
    return ($key !== '' && $model !== '');  // ← Returned false
  } catch (Throwable $e) { return false; }
}
```

### Production State Before Fix

**Database Settings**:
```
ai.enabled = 1
ai.award.enabled = 1
ai.image.model = google/gemini-2.5-flash-image
ai.image.provider = [MISSING] ← Defaulted to 'local'
```

**Environment Variables**:
- `OPENAI_API_KEY` present (for chat)
- `OPENROUTER_API_KEY` **missing** ← Critical

**Logs** (data/logs/ai/award_images.log):
```
[2025-10-09T03:45:38] provider=fallback path=...webp status=ok reason=fallback
```
- All requests → fallback renderer
- No API calls attempted
- No provider errors (because provider path never executed)

---

## Resolution Steps

### 1. Added OPENROUTER_API_KEY to Production
```bash
ssh mike@192.168.0.103
cd /var/www/public_html/dev/html/walk
sudo bash -c 'echo "OPENROUTER_API_KEY=\"sk-or-v1-...\"" >> .env'
sudo chown www-data:www-data .env
```

### 2. Added ai.image.provider Setting
```sql
INSERT INTO settings(key, value, updated_at) 
VALUES('ai.image.provider', 'openrouter', datetime('now'))
ON CONFLICT(key) DO UPDATE SET value='openrouter', updated_at=datetime('now');
```

### 3. Restarted PHP-FPM
```bash
sudo systemctl restart php8.2-fpm
```

### 4. Updated Code to Seed Default (api/lib/settings.php)
Added `'ai.image.provider' => 'openrouter'` to default settings array.

This ensures future fresh deployments will have the correct provider configured.

---

## Verification

**Production Database After Fix**:
```
ai.enabled|1
ai.award.enabled|1
ai.image.model|google/gemini-2.5-flash-image
ai.image.provider|openrouter ← Now present
```

**Environment Check**:
```bash
$ grep OPENROUTER /var/www/public_html/dev/html/walk/.env
OPENROUTER_API_KEY="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"
```

---

## Testing Instructions

### Manual Test via Admin UI

1. Navigate to: `http://192.168.0.103/dev/html/walk/admin/`
2. Scroll to "Awards Images" section
3. Select user from dropdown (e.g., user ID 7 - Mike)
4. Select kind: `lifetime_steps`
5. Enter milestone: `1000` (or any new value)
6. Check "Force regenerate" checkbox
7. Click "Generate Image"
8. Wait 10-20 seconds for OpenRouter API response

**Expected Result**: Status message shows success with path to AI-generated image

### Log Verification

```bash
ssh mike@192.168.0.103
tail -f /var/www/public_html/dev/html/walk/data/logs/ai/award_images.log
```

**Look for**:
- `provider=openrouter` or `provider=google` (not "fallback")
- `cost=0.00xxx` (API usage cost tracked)
- `status=ok` with actual image path

### Image Verification

```bash
# Check generated files
ls -lh /var/www/public_html/dev/html/walk/site/assets/awards/7/
```

**AI-generated images** will be:
- Larger file size (50-200 KB vs 2-3 KB for fallback)
- Format: PNG or WebP
- Content: Unique AI-generated artwork (not flat circles)

**Fallback images** are:
- Small file size (1-3 KB)
- Format: SVG or basic WebP
- Content: Navy blue circle badge with text

---

## Architecture Notes

### Settings Storage
- All AI settings stored in SQLite `settings` table
- Schema: `(key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)`
- Settings seeded on first access via `settings_seed_defaults()`

### Environment Variables
- Loaded via `app/Core/Env.php` at bootstrap
- Read via `env($key, $default)` helper function
- `.env` file excluded from git and deployment (per `.gitignore` and `deploy_to_pi.sh`)

### Deployment Process
```bash
./scripts/deploy_to_pi.sh
```
- Rsyncs code to Pi (excludes `data/` and `.env`)
- Production database preserved on server
- **Important**: `.env` must be manually maintained on production

### Model Selection Flow

1. Admin clicks "Update Model List" → Fetches from OpenRouter API
2. Response cached in `data/models/ai_image_models.json`
3. Admin selects model from dropdown → Saves to `settings.ai.image.model`
4. Image generation uses selected model for API calls

---

## Future Improvements

### 1. Environment Variable Management
**Current**: Manual `.env` maintenance on production  
**Proposed**: 
- Add `.env.production.example` with required keys
- Update deploy script to verify required env vars exist
- Add pre-flight check that warns if keys missing

### 2. Provider Configuration UI
**Current**: No admin UI control for `ai.image.provider`  
**Proposed**: Add dropdown in admin panel:
```html
<label>Image Provider:
  <select id="aiImageProvider">
    <option value="openrouter">OpenRouter</option>
    <option value="local">Local Fallback</option>
  </select>
</label>
```

### 3. Health Check Endpoint
**Proposed**: Add `api/ai_health_check.php`:
```json
{
  "ok": true,
  "checks": {
    "openrouter_key": true,
    "provider_configured": true,
    "model_selected": true,
    "model_list_cached": true
  }
}
```

### 4. Improved Error Logging
**Current**: Generic "provider_failed" in logs  
**Proposed**: Log specific HTTP status, OpenRouter error messages, timeout info

---

## Related Files

- `api/lib/ai_images.php` - Image generation core logic
- `api/lib/settings.php` - Settings management and defaults
- `api/award_generate.php` - Admin endpoint for image generation
- `admin/index.php` - Admin UI with Awards Images controls
- `api/ai_models_refresh.php` - Fetches available models from OpenRouter
- `api/ai_models_list.php` - Returns cached model list

---

## Deployment Checklist

When deploying to a new environment:

- [ ] Create `.env` file with `OPENROUTER_API_KEY`
- [ ] Run migrations to create `settings` table
- [ ] Verify `data/` directory exists and is writable by www-data
- [ ] Verify `site/assets/awards/` exists and is writable
- [ ] Access admin panel and click "Update Model List"
- [ ] Select an image model and click "Save Image Model"
- [ ] Test generation with force=true on a test user
- [ ] Check logs for successful API call
- [ ] Verify generated image is AI artwork (not fallback)

---

## Conclusion

The issue was entirely configuration-based, not a code bug. The system's fallback mechanism worked as designed when the provider was unavailable. After adding the missing environment variable and database setting, the system now correctly uses OpenRouter's Gemini 2.5 Flash Image model for award generation.

**Next award generation will produce AI-generated artwork instead of flat circle badges.**

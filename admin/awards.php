<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/../api/lib/admin_auth.php';
require_admin();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$csrf = \App\Security\Csrf::token();

// Load users and awards data
$users = [];
$awards = [];
$stats = ['total' => 0, 'with_image' => 0, 'missing_image' => 0, 'errors' => 0];

try {
  $pdo = \App\Config\DB::pdo();
  ob_start(); require_once __DIR__ . '/../api/migrate.php'; ob_end_clean();
  
  // Get all users
  $users = $pdo->query("SELECT id, name FROM users ORDER BY LOWER(name)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  
  // Get all awards with user info
  $awardsStmt = $pdo->query("
    SELECT a.id, a.user_id, u.name, a.kind, a.milestone_value, a.image_path, a.created_at
    FROM ai_awards a
    JOIN users u ON u.id = a.user_id
    WHERE a.kind IN ('lifetime_steps', 'attendance_weeks')
    ORDER BY a.created_at DESC
    LIMIT 100
  ");
  $awards = $awardsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  
  // Calculate stats
  $stats['total'] = count($awards);
  foreach ($awards as $a) {
    if (!empty($a['image_path'])) {
      $stats['with_image']++;
    } else {
      $stats['missing_image']++;
    }
  }
} catch (Throwable $e) {
  $users = [];
  $awards = [];
}

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Awards Admin - KW</title>
  <link rel="icon" href="../favicon.ico" />
  <style>
    body { background:#0b1020; color:#e6ecff; font: 14px system-ui,-apple-system,"Segoe UI",Roboto,Arial; margin:0; }
    .wrap { max-width: 1400px; margin: 24px auto; padding: 0 16px; }
    .grid { display:grid; grid-template-columns: 1fr; gap:16px; }
    @media (min-width: 920px){ .grid{ grid-template-columns: 1fr 1fr; } }
    @media (min-width: 1200px){ .grid-3{ grid-template-columns: 1fr 1fr 1fr; } }
    .card { background:#0f1530; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:16px; }
    .hdr { display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
    .nav { display:flex; flex-wrap:wrap; gap:8px; }
    .btn { padding:8px 12px; border-radius:8px; background:#1a2350; border:1px solid #2c3a7a; color:#e6ecff; cursor:pointer; font-size:13px; white-space:nowrap; }
    .btn:hover { background:#1e2a5a; }
    .btn:disabled { opacity:0.5; cursor:not-allowed; }
    .btn.primary { background:#2a4580; border-color:#3a5a9a; }
    .btn.warn { background:#4d1a1a; border-color:#7a2c2c; }
    .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    label { display:flex; align-items:center; gap:6px; }
    input, select { background:#111936; color:#e6ecff; border:1px solid #1e2a5a; border-radius:8px; padding:6px 8px; font-size:13px; }
    input[type="checkbox"] { width:16px; height:16px; }
    .muted { color: rgba(230,236,255,0.6); font-size: 12px; }
    h1 { font-size: 24px; font-weight: 800; margin: 0; }
    h2 { font-size: 16px; font-weight: 700; margin: 0 0 12px; }
    .badge { display:inline-block; padding:3px 10px; border-radius:999px; border:1px solid rgba(255,255,255,0.15); font-size:12px; font-weight:600; }
    .badge.ok { background:rgba(124, 227, 161, 0.1); border-color:rgba(124, 227, 161, 0.3); color:#7ce3a1; }
    .badge.warn { background:rgba(255, 187, 102, 0.1); border-color:rgba(255, 187, 102, 0.3); color:#ffbb66; }
    .badge.err { background:rgba(255, 119, 153, 0.1); border-color:rgba(255, 119, 153, 0.3); color:#f79; }
    table { width:100%; border-collapse: collapse; font-size:13px; }
    th, td { padding:10px 8px; border-top:1px solid rgba(255,255,255,0.08); text-align:left; }
    th { font-weight:600; color:rgba(230,236,255,0.8); }
    tr:hover { background:rgba(255,255,255,0.02); }
    .link { color:#9ecbff; text-decoration: none; }
    .link:hover { text-decoration: underline; }
    .stat-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap:12px; }
    .stat-card { background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); border-radius:8px; padding:12px; }
    .stat-value { font-size:28px; font-weight:800; margin:4px 0; }
    .stat-label { font-size:11px; text-transform:uppercase; letter-spacing:0.5px; color:rgba(230,236,255,0.5); }
    #status { margin-top:8px; padding:8px; border-radius:6px; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.06); font-size:12px; }
    .img-thumb { width:40px; height:40px; border-radius:6px; object-fit:cover; border:1px solid rgba(255,255,255,0.1); }
    .full-width { grid-column: 1 / -1; }
  </style>
</head>
<body>
<div class="wrap">
  <div class="card hdr">
    <div>
      <h1>⭐ Awards Management</h1>
      <div class="muted">Manage lifetime and attendance awards</div>
    </div>
    <div class="nav">
      <a class="btn" href="index.php">← Admin Home</a>
      <a class="btn" href="users.php">Users</a>
      <a class="btn" href="ai.php">AI Console</a>
      <a class="btn" href="../site/">View Dashboard</a>
    </div>
  </div>

  <!-- Stats Overview -->
  <div class="card full-width">
    <h2>Statistics</h2>
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">Total Awards</div>
        <div class="stat-value"><?= number_format($stats['total']) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">With Images</div>
        <div class="stat-value" style="color:#7ce3a1"><?= number_format($stats['with_image']) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Missing Images</div>
        <div class="stat-value" style="color:#ffbb66"><?= number_format($stats['missing_image']) ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Coverage</div>
        <div class="stat-value" style="color:#9ecbff">
          <?= $stats['total'] > 0 ? number_format(($stats['with_image'] / $stats['total']) * 100, 0) : 0 ?>%
        </div>
      </div>
    </div>
  </div>

  <div class="grid">
    <!-- Generate Award -->
    <div class="card">
      <h2>Generate Award Image</h2>
      <div class="row" style="margin-bottom:8px">
        <label style="flex:1">
          User:
          <select id="genUser" style="width:100%">
            <option value="">Select user…</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="row" style="margin-bottom:8px">
        <label style="flex:1">
          Kind:
          <select id="genKind">
            <option value="lifetime_steps">Lifetime Steps</option>
            <option value="attendance_weeks">Attendance Weeks</option>
          </select>
        </label>
        <label style="flex:1">
          Milestone:
          <input id="genValue" type="number" min="1" placeholder="100000" style="width:100%">
        </label>
      </div>
      <div class="row" style="margin-bottom:8px">
        <label class="muted">
          <input type="checkbox" id="genForce"> Force regenerate if exists
        </label>
      </div>
      <div class="row">
        <button class="btn primary" id="genBtn">Generate Image</button>
      </div>
      <div id="awStatus" class="muted" style="margin-top:8px"></div>
    </div>

    <!-- Batch Operations -->
    <div class="card">
      <h2>Batch Operations</h2>
      <div class="row" style="margin-bottom:12px">
        <label style="flex:1">
          Filter by kind:
          <select id="batchKind" style="width:100%">
            <option value="">All kinds</option>
            <option value="lifetime_steps">Lifetime Steps</option>
            <option value="attendance_weeks">Attendance Weeks</option>
          </select>
        </label>
      </div>
      <div class="row" style="margin-bottom:8px">
        <button class="btn primary" id="regenMissingBtn">Regenerate Missing Images</button>
      </div>
      <div class="row" style="margin-bottom:8px">
        <button class="btn" id="clearCacheBtn">Clear Date Cache</button>
      </div>
      <div class="row">
        <button class="btn" id="refreshBtn">Refresh List</button>
      </div>
    </div>

    <!-- Milestones Management -->
    <div class="card">
      <h2>Milestones</h2>
      <div class="muted" style="margin-bottom:8px">Manage milestone lists for awards. Enter comma-separated integers.</div>

      <div class="row" style="margin-bottom:8px">
        <label style="flex:1">
          Lifetime steps:
          <input id="msLifetime" type="text" placeholder="100000,250000,500000" style="width:100%" />
        </label>
      </div>

      <div class="row" style="margin-bottom:8px">
        <label style="flex:1">
          Attendance weeks (checkins):
          <input id="msAttendance" type="text" placeholder="25,50,100" style="width:100%" />
        </label>
      </div>

      <div class="row" style="margin-bottom:8px">
        <div id="msPreview" class="muted" style="flex:1">Parsed: —</div>
      </div>

      <div class="row">
        <button class="btn primary" id="saveMsBtn">Save Milestones</button>
        <button class="btn" id="resetMsBtn" style="margin-left:8px">Reset to Defaults</button>
      </div>
    </div>
  </div>

  <!-- Awards List -->
  <div class="card full-width">
    <h2>Recent Awards (last 100)</h2>
    <div style="overflow-x:auto">
      <table>
        <thead>
          <tr>
            <th>Image</th>
            <th>User</th>
            <th>Kind</th>
            <th>Milestone</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="awardsTable">
          <?php if (empty($awards)): ?>
            <tr><td colspan="7" class="muted" style="text-align:center">No awards found</td></tr>
          <?php else: ?>
            <?php foreach ($awards as $a): ?>
              <tr>
                <td>
                  <?php
                    $img = $a['image_path'] ?? '';
                    if ($img) {
                      if (preg_match('~^https?://~', $img)) {
                        $src = $img;
                      } else {
                        // Normalize stored path and build a web path to site assets.
                        $normalized = preg_replace('#^site/#', '', ltrim($img, '/'));
                        if (strpos($normalized, 'assets/') === 0) {
                          // already includes assets/ prefix
                          $src = '../' . $normalized;
                        } else {
                          // common stored form: "awards/{uid}/file.webp" -> file is at site/assets/awards/...
                          $src = '../site/assets/' . $normalized;
                        }
                      }
                      echo '<img src="' . htmlspecialchars($src) . '" class="img-thumb" alt="Award" onerror="this.style.display=\'none\'">';
                    } else {
                      echo '<div class="img-thumb" style="background:rgba(255,255,255,0.05)"></div>';
                    }
                  ?>
                </td>
                <td><?= htmlspecialchars($a['name']) ?></td>
                <td><span class="muted"><?= htmlspecialchars($a['kind']) ?></span></td>
                <td><strong><?= number_format((int)$a['milestone_value']) ?></strong></td>
                <td>
                  <?php if (!empty($a['image_path'])): ?>
                    <span class="badge ok">✓ Image</span>
                  <?php else: ?>
                    <span class="badge warn">⚠ Missing</span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
                <td>
                  <a href="../site/user.php?id=<?= (int)$a['user_id'] ?>" class="link" target="_blank">View Page</a>
                  <button class="btn warn delete-award-btn" data-id="<?= (int)$a['id'] ?>" style="margin-left:8px">Delete</button>
                  <button class="btn regen-award-btn" style="margin-left:8px" 
                    data-user="<?= (int)$a['user_id'] ?>" 
                    data-kind="<?= htmlspecialchars($a['kind']) ?>" 
                    data-value="<?= (int)$a['milestone_value'] ?>">Regen</button>
                  <span class="muted row-status" style="margin-left:8px"></span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Status Message -->
  <div id="status" class="muted" style="display:none"></div>
</div>

<script>
const CSRF = "<?= htmlspecialchars($csrf) ?>";
const base = '../';

function showStatus(msg, type = 'info') {
  const el = document.getElementById('status');
  el.textContent = msg;
  el.style.display = 'block';
  el.style.borderColor = type === 'ok' ? 'rgba(124, 227, 161, 0.3)' : 
                         type === 'err' ? 'rgba(255, 119, 153, 0.3)' : 
                         'rgba(255,255,255,0.06)';
  setTimeout(() => el.style.display = 'none', 5000);
}

async function freshCsrf() {
  try {
    const r = await fetch(base + 'api/csrf_token.php', { cache: 'no-store' });
    const j = await r.json();
    return (j && j.token) ? String(j.token) : CSRF;
  } catch(e) { return CSRF; }
}

 // Generate single award
document.getElementById('genBtn').addEventListener('click', async () => {
  const uid = parseInt(document.getElementById('genUser').value, 10) || 0;
  const kind = document.getElementById('genKind').value.trim();
  const val = parseInt(document.getElementById('genValue').value, 10) || 0;
  const force = document.getElementById('genForce').checked;
  const status = document.getElementById('awStatus');
  
  if (!uid || !kind || !val) {
    alert('Please select user, kind, and milestone');
    return;
  }
  
  status.textContent = 'Generating…';
  try {
    const tk = await freshCsrf();
    const res = await fetch(base + 'api/award_generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': tk },
      body: JSON.stringify({ user_id: uid, kind, milestone_value: val, force })
    });
    const j = await res.json();
    
    if (j && j.ok && j.skipped) {
      status.textContent = 'Skipped: ' + (j.reason || 'already exists');
    } else if (j && j.ok) {
      status.textContent = '✓ Generated: ' + (j.path || '');
      setTimeout(() => location.reload(), 1500);
    } else {
      status.textContent = '✗ Error: ' + (j && j.error ? j.error : 'failed');
    }
  } catch (e) {
    status.textContent = '✗ Error generating image';
  }
});

 // Regenerate missing
document.getElementById('regenMissingBtn').addEventListener('click', async () => {
  const kind = document.getElementById('batchKind').value.trim();
  const status = document.getElementById('awStatus');
  const btn = document.getElementById('regenMissingBtn');
  
  if (!confirm('Regenerate all missing award images' + (kind ? ' for ' + kind : '') + '?')) {
    return;
  }
  
  // Disable button during processing
  btn.disabled = true;
  btn.textContent = 'Processing...';
  
  let totalGenerated = 0;
  let totalErrors = 0;
  let batchCount = 0;
  
  try {
    // Process in batches until all are complete
    while (true) {
      batchCount++;
      status.textContent = `Processing batch ${batchCount}...`;
      
      const body = (kind && kind !== 'custom') ? JSON.stringify({ kind, limit: 10 }) : JSON.stringify({ limit: 10 });
      const tk = await freshCsrf();
      const res = await fetch(base + 'api/award_regen_missing.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': tk }, body });
      const j = await res.json();
      
      if (!j || !j.ok) {
        status.textContent = 'Error: ' + (j && j.error ? j.error : 'failed');
        break;
      }
      
      totalGenerated += (j.generated || 0);
      totalErrors += (j.errors || 0);
      const remaining = j.remaining || 0;
      const total = j.total_missing || 0;
      
      // Update status with progress
      if (remaining > 0) {
        status.textContent = `Progress: ${totalGenerated} generated, ${totalErrors} errors, ${remaining} remaining...`;
        // Small delay between batches to avoid overwhelming the server
        await new Promise(resolve => setTimeout(resolve, 500));
      } else {
        // All done
        status.textContent = `✓ Complete: ${totalGenerated} generated, ${totalErrors} errors (from ${total} missing)`;
        break;
      }
    }
  } catch (e) {
    status.textContent = 'Error: ' + e.message;
  } finally {
    // Re-enable button
    btn.disabled = false;
    btn.textContent = 'Regenerate Missing Images';
  }
});

// Clear cache
document.getElementById('clearCacheBtn').addEventListener('click', async () => {
  if (!confirm('Clear all cached award dates? This will force recalculation next time dates are requested.')) {
    return;
  }
  
  showStatus('Clearing cache...');
  try {
    const tk = await freshCsrf();
    const res = await fetch(base + 'api/migrate.php', { cache: 'no-store' });
    await res.text();
    
    // Delete from cache table (we'd need a dedicated endpoint, but for now just inform)
    showStatus('⚠ Cache clear requires database access - use SQL: DELETE FROM user_awards_cache', 'info');
  } catch (e) {
    showStatus('✗ Error clearing cache', 'err');
  }
});

 // Refresh page
 document.getElementById('refreshBtn').addEventListener('click', () => {
   location.reload();
 });

 // Delegate delete and regen actions to the awardsTable for reliable event handling
 document.getElementById('awardsTable').addEventListener('click', async (ev) => {
   const btn = ev.target.closest('button');
   if (!btn) return;

   // Delete action
   if (btn.classList.contains('delete-award-btn')) {
     const id = btn.getAttribute('data-id');
     if (!id) return;
     if (!confirm('Delete this award? This will remove the DB row and the generated image file (if present).')) return;

     // Provide immediate UI feedback
     const origText = btn.textContent;
     btn.disabled = true;
     btn.textContent = 'Deleting...';
     showStatus('Deleting award...');

     try {
       const tk = await freshCsrf();
       const res = await fetch(base + 'api/admin_delete_award.php', {
         method: 'POST',
         headers: { 'Content-Type': 'application/json', 'X-CSRF': tk },
         body: JSON.stringify({ id: parseInt(id, 10) })
       });
       const j = await res.json();
       if (j && j.ok) {
         showStatus('✓ Deleted', 'ok');
         const row = btn.closest('tr');
         if (row) row.remove();
       } else {
         showStatus('✗ Error deleting award', 'err');
         btn.disabled = false;
         btn.textContent = origText;
       }
     } catch (err) {
       showStatus('✗ Error deleting award', 'err');
       btn.disabled = false;
       btn.textContent = origText;
     }
     return;
   }

   // Regen action (force regenerate image)
   if (btn.classList.contains('regen-award-btn')) {
     const userId = btn.getAttribute('data-user');
     const kind = btn.getAttribute('data-kind');
     const val = btn.getAttribute('data-value');
     if (!userId || !kind || !val) return;
     if (!confirm('Regenerate the award image for this user and milestone?')) return;

     // Immediate UI feedback
     const origText = btn.textContent;
     btn.disabled = true;
     btn.textContent = 'Regenerating...';
     const row = btn.closest('tr');
     const rowStatus = row ? row.querySelector('.row-status') : null;
     if (rowStatus) rowStatus.textContent = 'Regenerating...';

     try {
       const tk = await freshCsrf();
       const res = await fetch(base + 'api/award_generate.php', {
         method: 'POST',
         headers: { 'Content-Type': 'application/json', 'X-CSRF': tk },
         body: JSON.stringify({ user_id: parseInt(userId, 10), kind: kind, milestone_value: parseInt(val,10), force: true })
       });
       const j = await res.json();
       if (j && j.ok) {
         if (rowStatus) rowStatus.textContent = '✓ Regenerated';
         // Refresh the row image (if present) or reload
         if (row) {
           const imgEl = row.querySelector('.img-thumb');
           if (imgEl) {
             // Force reload by updating src if available in response.path
             if (j.path) {
               imgEl.src = '../' + j.path;
               imgEl.style.display = '';
             } else {
               // fallback: reload page to reflect changes
               setTimeout(() => location.reload(), 800);
             }
           } else {
             setTimeout(() => location.reload(), 800);
           }
         } else {
           setTimeout(() => location.reload(), 800);
         }
         // clear row status after a short delay
         setTimeout(() => { if (rowStatus) rowStatus.textContent = ''; }, 3000);
       } else {
         if (rowStatus) rowStatus.textContent = '✗ Error';
         btn.disabled = false;
         btn.textContent = origText;
       }
     } catch (err) {
       if (rowStatus) rowStatus.textContent = '✗ Error';
       btn.disabled = false;
       btn.textContent = origText;
     }
     return;
   }
 });

//
// Milestones management JS
//

function parseMilestones(str) {
  if (!str || typeof str !== 'string') return [];
  return Array.from(new Set(str.split(',').map(s => parseInt(s.trim(), 10)).filter(n => Number.isFinite(n) && n > 0)))
    .sort((a,b) => a - b);
}

function formatList(arr) {
  if (!Array.isArray(arr) || arr.length === 0) return '—';
  return arr.join(', ');
}

function updateMsPreview() {
  const l = parseMilestones(document.getElementById('msLifetime').value);
  const a = parseMilestones(document.getElementById('msAttendance').value);
  const el = document.getElementById('msPreview');
  el.textContent = `Lifetime: ${formatList(l)} · Attendance: ${formatList(a)}`;
}

async function loadMilestones() {
  try {
    const r = await fetch(base + 'api/settings_get.php', { cache: 'no-store' });
    const j = await r.json();
    if (j) {
      document.getElementById('msLifetime').value = j['milestones.lifetime_steps'] || '';
      document.getElementById('msAttendance').value = j['milestones.attendance_weeks'] || '';
      updateMsPreview();
    }
  } catch (e) {
    // ignore
  }
}

async function saveSetting(key, value) {
  const tk = await freshCsrf();
  const res = await fetch(base + 'api/settings_set.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': tk },
    body: JSON.stringify({ key, value })
  });
  return res.json();
}

document.getElementById('msLifetime').addEventListener('input', updateMsPreview);
document.getElementById('msAttendance').addEventListener('input', updateMsPreview);

document.getElementById('saveMsBtn').addEventListener('click', async () => {
  const lraw = document.getElementById('msLifetime').value;
  const araw = document.getElementById('msAttendance').value;
  const l = parseMilestones(lraw);
  const a = parseMilestones(araw);
  if (l.length === 0 || a.length === 0) {
    alert('Both milestone lists must contain at least one positive integer.');
    return;
  }
  showStatus('Saving milestones...');
  try {
    const r1 = await saveSetting('milestones.lifetime_steps', l.join(','));
    const r2 = await saveSetting('milestones.attendance_weeks', a.join(','));
    if ((r1 && r1.ok) && (r2 && r2.ok)) {
      showStatus('✓ Milestones saved', 'ok');
      updateMsPreview();
      // optional: reload to reflect changes in list
      setTimeout(() => location.reload(), 1200);
    } else {
      showStatus('✗ Error saving milestones', 'err');
    }
  } catch (e) {
    showStatus('✗ Error saving milestones', 'err');
  }
});

document.getElementById('resetMsBtn').addEventListener('click', async () => {
  if (!confirm('Reset milestones to recommended defaults?')) return;
  const defaultsLifetime = '100000,250000,500000,750000,1000000';
  const defaultsAttendance = '25,50,100';
  document.getElementById('msLifetime').value = defaultsLifetime;
  document.getElementById('msAttendance').value = defaultsAttendance;
  updateMsPreview();
  // Save defaults
  try {
    showStatus('Saving defaults...');
    const r1 = await saveSetting('milestones.lifetime_steps', defaultsLifetime);
    const r2 = await saveSetting('milestones.attendance_weeks', defaultsAttendance);
    if ((r1 && r1.ok) && (r2 && r2.ok)) {
      showStatus('✓ Defaults saved', 'ok');
      setTimeout(() => location.reload(), 1000);
    } else {
      showStatus('✗ Error saving defaults', 'err');
    }
  } catch (e) {
    showStatus('✗ Error saving defaults', 'err');
  }
});

// Initialize
loadMilestones();

</script>
</body>
</html>

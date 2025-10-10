// -------- Config Loader --------
let APP_CONFIG = null;
let DISABLE_LOGIN = false;
let DISABLE_CSV_UPLOAD = false;

async function loadConfig() {
  try {
    const res = await fetch("config.json", { cache: "no-store" });
    if (!res.ok) throw new Error("config fetch failed");
    const cfg = await res.json();
    APP_CONFIG = cfg;
    DISABLE_LOGIN = !!(cfg.ui && cfg.ui.disableLogin);
    DISABLE_CSV_UPLOAD = !!(cfg.ui && cfg.ui.disableCsvUpload);
    return cfg;
  } catch (e) {
    // Safe defaults if missing
    APP_CONFIG = { ui: { disableLogin: false, disableCsvUpload: false } };
    DISABLE_LOGIN = false;
    DISABLE_CSV_UPLOAD = false;
    return APP_CONFIG;
  }
}

// ------- Config-independent constants (original) -------
const DAY_ORDER = ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"]; // displayed order
const DAILY_GOAL_10K = 10000;
const DAILY_GOAL_15K = 15000;
const DAILY_GOAL_5K = 5000; // kept for reference, still counted
const DAILY_GOAL_2_5K = 2500;
const DAILY_GOAL_1K = 1000;
const CHERYL_THRESHOLD = 20000; // Cheryl Award
const THIRTY_K_THRESHOLD = 30000; // 30k Award
// Award fairness controls
const UNIQUE_WINNERS = true;     // prevent one person from sweeping
const MAX_AWARDS_PER_PERSON = 2; // cap per person when UNIQUE_WINNERS is true
const DISPLAY_NAME_OVERRIDES = { "Tutu": "Tutu" }; // keep as Tutu

// Friendly nudge lines when missing data
const NUDGES = [
  "Your shoes miss you.",
  "Take a lap and report back.",
  "Screenshot the counter tonight.",
  "Walk-n-talk with the fam, then log it.",
  "30 minutes. No debate."
];

// ------- CSV Loading -------
let globalData = [];
let charts = [];
let weeksManifest = [];
let currentWeekFile = null;
let currentWeekLabel = null;

function setStatus(msg, type = "info") {
  const el = document.getElementById("loadStatus");
  if (!el) return;
  el.textContent = msg;
  el.className = "badge";
  if (type === "ok") el.classList.add("bg-green-500/10","text-green-300");
  if (type === "warn") el.classList.add("bg-yellow-500/10","text-yellow-300");
  if (type === "err") el.classList.add("bg-rose-500/10","text-rose-300");
}

async function tryFetchDefault() {
  setStatus("Loading kings_walk_week.csv…");
  const candidates = ["/kings_walk_week.csv", "../kings_walk_week.csv"];
  for (let i = 0; i < candidates.length; i++) {
    try {
      const url = candidates[i];
      const res = await fetch(url, { cache: "no-store" });
      if (!res.ok) throw new Error("Fetch failed");
      const text = await res.text();
      parseCSV(text);
      setStatus(`Loaded ${url}`, "ok");
      return;
    } catch (e) {
      // try next
    }
  }
  setStatus("Default CSV not found. Use Upload.", "warn");
}

/*
  Weeks manifest / selector support
  - loadWeeksManifest() loads /data/weeks/manifest.json and populates weeksManifest
  - buildWeekSelector(weeks) inserts a selector above the leaderboard
  - loadCsvForFile(file, label) fetches the CSV and delegates to parseCSV
*/
async function loadWeeksManifest() {
  try {
    const res = await fetch("/data/weeks/manifest.json", { cache: "no-store" });
    if (!res.ok) throw new Error("manifest fetch failed");
    const json = await res.json();
    weeksManifest = Array.isArray(json.weeks) ? json.weeks : [];
    weeksManifest.forEach((w, i) => {
      if (!w || !w.label || !w.file) console.warn(`weeks manifest entry #${i} missing label or file`, w);
    });
    return weeksManifest;
  } catch (e) {
    weeksManifest = [];
    return weeksManifest;
  }
}

function buildWeekSelector(weeks) {
  const leaderboard = document.getElementById('leaderboard');
  if (!leaderboard) {
    console.warn('No #leaderboard element found; skipping week selector.');
    return;
  }
  if (!leaderboard.parentNode) {
    console.warn('#leaderboard has no parentNode; cannot insert week selector.');
    return;
  }
  // Avoid creating the selector twice
  if (document.getElementById('weekSelectorContainer')) return;

  const container = document.createElement('div');
  container.id = 'weekSelectorContainer';
  container.className = 'mb-4 flex items-center gap-3';

  const label = document.createElement('label');
  label.className = 'text-sm text-white/70';
  label.htmlFor = 'weekSelector';
  label.textContent = 'Week:';

  const select = document.createElement('select');
  select.id = 'weekSelector';
  select.className = 'px-2 py-1 rounded bg-white/5 text-sm';

  weeks.forEach((w, idx) => {
    const opt = document.createElement('option');
    opt.value = w.file || '';
    opt.textContent = w.label || w.file || `Week ${idx+1}`;
    // Mark archived visually in option text (optional)
    if ((w.file || '').indexOf('kings_walk_week.csv') === -1 && (w.file || '') !== '') {
      opt.textContent += ' — archived';
    }
    select.appendChild(opt);
  });

  const hint = document.createElement('span');
  hint.id = 'weekArchivedHint';
  hint.className = 'text-xs text-white/60';
  hint.style.minWidth = '160px';

  container.appendChild(label);
  container.appendChild(select);
  container.appendChild(hint);

  try {
    leaderboard.parentNode.insertBefore(container, leaderboard);
  } catch (err) {
    console.error('Failed to insert weekSelectorContainer:', err);
    return;
  }

  select.addEventListener('change', () => {
    const sel = weeks.find(w => w.file === select.value) || { file: select.value, label: select.options[select.selectedIndex]?.text || select.value };
    loadCsvForFile(sel.file, sel.label);
  });
}

async function loadCsvForFile(file, label) {
  if (!file) {
    setStatus('No file specified for selected week', 'warn');
    return;
  }
  setStatus(`Loading ${label || file}…`);
  try {
    const res = await fetch(file, { cache: 'no-store' });
    if (!res.ok) throw new Error('CSV fetch failed');
    const text = await res.text();
    currentWeekFile = file;
    currentWeekLabel = label || file;
    const statusEl = document.getElementById('loadStatus');
    if (statusEl) statusEl.title = currentWeekFile || '';
    parseCSV(text);
    setStatus(`Loaded ${currentWeekLabel} (${currentWeekFile})`, 'ok');
    const hint = document.getElementById('weekArchivedHint');
    if (hint) {
      if (currentWeekFile.indexOf('kings_walk_week.csv') === -1) {
        hint.textContent = 'Archived (read-only)';
      } else {
        hint.textContent = '';
      }
    }
  } catch (e) {
    console.error(e);
    setStatus(`Failed to load ${file}`, 'err');
    // If manifest is empty, fallback to original default fetch behavior
    if (!weeksManifest || !weeksManifest.length) {
      await tryFetchDefault();
    }
  }
}

function parseCSV(text) {
  const raw = text || '';
  const parsed = Papa.parse(raw.trim(), { header: true, skipEmptyLines: true, relaxColumnCount: true });
  if (parsed.errors?.length) {
    setStatus("CSV parse warnings", "warn");
    console.warn('PapaParse errors:', parsed.errors);
    // Attempt to log the raw CSV lines for context when available
    try {
      const rawLines = raw.split(/\r?\n/);
      parsed.errors.forEach(err => {
        const rowIndex = (typeof err.row === 'number') ? err.row : null;
        const maybeLine = rowIndex != null ? (rawLines[rowIndex] ?? rawLines[rowIndex - 1]) : undefined;
        console.warn('Problematic CSV row:', { row: err.row, type: err.type, message: err.message, line: maybeLine });
      });
    } catch (e) {
      console.warn('Failed to extract raw CSV lines for error context', e);
    }
  }
  const rows = parsed.data;
  globalData = sanitizeRows(rows);
  // Gate rendering by login
  ensureUserSelected(() => renderAll());
}

function sanitizeRows(rows) {
  // Normalize headers and retain privacy fields
  const norm = rows.map(r => {
    const out = {};
    for (const k in r) {
      const key = normalizeHeader(k);
      out[key] = r[k];
    }
    // Standardize person name
    const nameKey = Object.keys(out).find(k => /^name$/i.test(k)) || "Name";
    const name = (out[nameKey] || "").toString().trim();
    out.Name = DISPLAY_NAME_OVERRIDES[name] || name;

    // Ensure numeric values
    DAY_ORDER.forEach(d => { out[d] = toNum(out[d]); });
    out["Total Steps"] = toNum(out["Total Steps"]);

    // Privacy fields (string trimmed)
    out.PIN = (out.PIN ?? "").toString().trim();
    out.ShareMode = (out.ShareMode ?? "").toString().trim();
    out.ShareWith = (out.ShareWith ?? "").toString().trim();

    return pick(out, ["Name", ...DAY_ORDER, "Total Steps", "PIN", "ShareMode", "ShareWith"]);
  });
  return norm;
}

function normalizeHeader(h) {
  const s = (h || "").toString().trim();
  const m = s.toLowerCase();
  if (m === "wednessday" || m === "wednesday") return "Wednesday";
  if (m === "monday") return "Monday";
  if (m === "tuesday") return "Tuesday";
  if (m === "thursday") return "Thursday";
  if (m === "friday") return "Friday";
  if (m === "saturday") return "Saturday";
  if (m === "total" || m === "total steps") return "Total Steps";
  if (m === "name" || m === "person" || m === "names") return "Name"; // tolerate "Names"
  if (m === "pin") return "PIN";
  if (m === "sharemode" || m === "share mode") return "ShareMode";
  if (m === "sharewith" || m === "share with") return "ShareWith";
  return s; // keep unknowns
}

function toNum(v) {
  if (v == null) return null;
  const s = String(v).trim();
  if (s === "") return null; // treat empty as missing, not 0
  const n = Number(s.replace(/[^0-9.-]/g, ""));
  return Number.isFinite(n) ? n : null;
}

function pick(obj, keys) { const o = {}; keys.forEach(k => o[k] = obj[k]); return o; }

// ------- Stats and Awards -------
function computeStats(data) {
  const people = data.map(row => {
    const days = DAY_ORDER.map(d => row[d] ?? null);
    const presentDays = days.filter(v => Number.isFinite(v));
    const reportedDaysCount = presentDays.length;
    const missingCount = DAY_ORDER.length - reportedDaysCount;

    // Longest streak of days >= 1k
    let longestStreak1k = 0, curStreak = 0;
    for (let i = 0; i < DAY_ORDER.length; i++) {
      const v = row[DAY_ORDER[i]];
      if (Number.isFinite(v) && v >= DAILY_GOAL_1K) { curStreak++; longestStreak1k = Math.max(longestStreak1k, curStreak); }
      else { curStreak = 0; }
    }

    // Median with 20k cap to blunt outliers
    const capped = presentDays.map(v => Math.min(v, 20000)).sort((a,b)=>a-b);
    const medianCapped = capped.length
      ? (capped.length % 2 ? capped[(capped.length-1)/2] : Math.round((capped[capped.length/2-1] + capped[capped.length/2]) / 2))
      : 0;
    const total = presentDays.reduce((a,b) => a + b, 0);
    const avg = presentDays.length ? Math.round(total / presentDays.length) : 0;
    const best = Math.max(0, ...presentDays);
    const thirtyK = presentDays.filter(v => v >= THIRTY_K_THRESHOLD).length;
    const cherylCount = presentDays.filter(v => v >= CHERYL_THRESHOLD).length;
    const fifteenK = presentDays.filter(v => v >= DAILY_GOAL_15K).length;
    const tenK = presentDays.filter(v => v >= DAILY_GOAL_10K).length;
    const fiveK = presentDays.filter(v => v >= DAILY_GOAL_5K).length;
    const two5K = presentDays.filter(v => v >= DAILY_GOAL_2_5K).length;
    const oneK = presentDays.filter(v => v >= DAILY_GOAL_1K).length;

    // Day over day jumps
    let biggestJump = { amount: 0, from: null, to: null };
    for (let i = 1; i < DAY_ORDER.length; i++) {
      const a = row[DAY_ORDER[i-1]];
      const b = row[DAY_ORDER[i]];
      if (Number.isFinite(a) && Number.isFinite(b)) {
        const diff = b - a;
        if (diff > biggestJump.amount) biggestJump = { amount: diff, from: DAY_ORDER[i-1], to: DAY_ORDER[i] };
      }
    }

    // Consistency: standard deviation of present days
    const variance = presentDays.length > 1 ? presentDays.reduce((acc, v) => acc + Math.pow(v - avg, 2), 0) / presentDays.length : 0;
    const stddev = Math.round(Math.sqrt(variance));

    // Momentum awards: first 3 vs last 3 days
    const firstHalf = presentDaysByIndex(days, [0,1,2]);
    const secondHalf = presentDaysByIndex(days, [3,4,5]);
    const firstHalfSum = firstHalf.reduce((a,b)=>a+b,0);
    const secondHalfSum = secondHalf.reduce((a,b)=>a+b,0);
    const pctImprovement = firstHalfSum > 0 ? (secondHalfSum - firstHalfSum) / firstHalfSum
                     : (secondHalfSum > 0 ? 1 : 0);

    return {
    name: row.Name, days, total, avg, best,
    thirtyK, cherylCount, fifteenK, tenK, fiveK, two5K, oneK,
    biggestJump, stddev,
    firstHalfSum, secondHalfSum, pctImprovement,
    reportedDaysCount, missingCount, longestStreak1k, medianCapped
  };
  });

  // Awards summarization
  const byTotal = [...people].sort((a,b) => b.total - a.total);
  const leader = byTotal[0];

  let highestSingle = null;
  people.forEach(p => {
    p.days.forEach((v, idx) => {
      if (Number.isFinite(v)) {
        if (!highestSingle || v > highestSingle.value) {
          highestSingle = { person: p.name, value: v, day: DAY_ORDER[idx] };
        }
      }
    })
  });

  const biggestJump = people.reduce((best, p) => {
    if (p.biggestJump.amount > (best?.amount || 0)) return { ...p.biggestJump, person: p.name };
    return best;
  }, null);

  const mostConsistent = [...people].filter(p => p.days.filter(x => Number.isFinite(x)).length >= 2).sort((a,b) => a.stddev - b.stddev)[0] || null;

    // Prepare sorted lists (runner-ups aware)
  function sortedBy(field, dir='desc') {
    return [...people].sort((a,b)=> dir==='desc' ? (b[field]-a[field]) : (a[field]-b[field]));
  }
  const most30kList = sortedBy('thirtyK');
  const most20kList = sortedBy('cherylCount');
  const most15kList = sortedBy('fifteenK');
  const most10kList = sortedBy('tenK');
  const most2_5kList = sortedBy('two5K');
  const most1kList = sortedBy('oneK');

  const most30k = most30kList[0];
  const most20k = most20kList[0];
  const most15k = most15kList[0];
  const most10k = most10kList[0];
  const most2_5k = most2_5kList[0];
  const most1k = most1kList[0];

  // New award candidate lists
  const mostImprovedList   = [...people].sort((a,b)=> b.pctImprovement - a.pctImprovement);
  const medianMasterList   = sortedBy('medianCapped');
  const reportingChampList = [...people].sort((a,b)=> (a.missingCount - b.missingCount) || (b.total - a.total));
  const streakBossList     = sortedBy('longestStreak1k');

  // Momentum split awards
  const earlyMomentum = [...people].sort((a,b)=> b.firstHalfSum - a.firstHalfSum)[0] || null;
  const closer = [...people].sort((a,b)=> b.secondHalfSum - a.secondHalfSum)[0] || null;

  // Missing days
  const missing = data.map(r => {
    const blanks = DAY_ORDER.filter(d => !Number.isFinite(r[d]));
    return { name: r.Name, blanks };
  });

  return {
    people, leader, highestSingle, biggestJump, mostConsistent, missing,
    most30k, most20k, most15k, most10k, most2_5k, most1k,
    earlyMomentum, closer,
    // candidate lists for fair allocation
    most30kList, most20kList, most15kList, most10kList, most2_5kList, most1kList,
    mostImprovedList, medianMasterList, reportingChampList, streakBossList
  };
}

function presentDaysByIndex(days, idxs) {
  return idxs.map(i => days[i]).filter(v => Number.isFinite(v));
}

// ------- Renderers -------
function renderAll() {
  // Clean
  charts.forEach(c => c.destroy());
  charts = [];

  const stats = computeStats(globalData);
  renderLeaderboard(stats);
  renderAwards(stats);
  renderCharts(stats);
  renderCards(stats);
  renderMissing(stats);
}

function renderLeaderboard({ people }) {
  const tbody = document.querySelector('#leaderboard tbody');
  const sorted = [...people].sort((a,b) => b.total - a.total);
  const viewer = getCurrentUser();
  const viewerRow = globalData.find(r => r.Name === viewer);
  // Position chip
  const posEl = document.getElementById('leaderboardPosition');
  if (posEl) {
    const rankIdx = sorted.findIndex(p => p.name === viewer);
    if (viewer && rankIdx >= 0) {
      posEl.innerHTML = `<span class="chip bg-white/10">${safe(viewer)}: You are in position #${rankIdx+1} of ${sorted.length}</span>`;
    } else {
      posEl.textContent = "";
    }
  }
  tbody.innerHTML = sorted.map((p, idx) => {
    const subjectRow = globalData.find(r => r.Name === p.name) || {};
    const canSee = maySeeNumbers(viewer, subjectRow, globalData);
    const dash = '<span class="text-white/40">—</span>';
    const total = canSee ? fmt(p.total) : dash;
    const avg = canSee ? fmt(p.avg) : dash;
    const best = canSee ? fmt(p.best) : dash;
    const thirtyK = canSee ? p.thirtyK : dash;
    const cherylCount = canSee ? p.cherylCount : dash;
    const fifteenK = canSee ? p.fifteenK : dash;
    const tenK = canSee ? p.tenK : dash;
    const two5K = canSee ? p.two5K : dash;
    const oneK = canSee ? p.oneK : dash;
    return `
    <tr class="border-t border-white/5">
      <td class="py-2 pr-2">${idx+1}</td>
      <td class="py-2 pr-2">${safe(p.name)}</td>
      <td class="py-2 text-right stat">${total}</td>
      <td class="py-2 text-right stat">${avg}</td>
      <td class="py-2 text-right stat">${best}</td>
      <td class="py-2 text-right stat">${thirtyK}</td>
      <td class="py-2 text-right stat">${cherylCount}</td>
      <td class="py-2 text-right stat">${fifteenK}</td>
      <td class="py-2 text-right stat">${tenK}</td>
      <td class="py-2 text-right stat">${two5K}</td>
      <td class="py-2 text-right stat">${oneK}</td>
    </tr>`;
  }).join("");

  // Sorting click handlers respecting privacy
  document.querySelectorAll('#leaderboard thead th[data-sort]').forEach(th => {
    th.onclick = () => {
      const key = th.dataset.sort;
      const by = key === 'name' ? (a,b) => a.name.localeCompare(b.name) : (a,b)=> b.total - a.total;
      const arr = [...people].sort(by);
      tbody.innerHTML = arr.map((p, idx) => {
        const subjectRow = globalData.find(r => r.Name === p.name) || {};
        const canSee = maySeeNumbers(viewer, subjectRow, globalData);
        const dash = '<span class="text-white/40">—</span>';
        const total = canSee ? fmt(p.total) : dash;
        const avg = canSee ? fmt(p.avg) : dash;
        const best = canSee ? fmt(p.best) : dash;
        const thirtyK = canSee ? p.thirtyK : dash;
        const cherylCount = canSee ? p.cherylCount : dash;
        const fifteenK = canSee ? p.fifteenK : dash;
        const tenK = canSee ? p.tenK : dash;
        const two5K = canSee ? p.two5K : dash;
        const oneK = canSee ? p.oneK : dash;
        return `<tr class="border-t border-white/5">
          <td class="py-2 pr-2">${idx+1}</td>
          <td class="py-2 pr-2">${safe(p.name)}</td>
          <td class="py-2 text-right stat">${total}</td>
          <td class="py-2 text-right stat">${avg}</td>
          <td class="py-2 text-right stat">${best}</td>
          <td class="py-2 text-right stat">${thirtyK}</td>
          <td class="py-2 text-right stat">${cherylCount}</td>
          <td class="py-2 text-right stat">${fifteenK}</td>
          <td class="py-2 text-right stat">${tenK}</td>
          <td class="py-2 text-right stat">${two5K}</td>
          <td class="py-2 text-right stat">${oneK}</td>
        </tr>`;
      }).join('');
    };
  });
}

function allocateAwards() {
  const taken = new Map();
  function pick(list) {
    for (const p of list) {
      const id = p.name || p.person;
      const count = taken.get(id) || 0;
      if (!UNIQUE_WINNERS || count < MAX_AWARDS_PER_PERSON) {
        taken.set(id, count + 1);
        return p;
      }
    }
    return null;
  }
  return { pick, taken };
}

function renderAwards(payload) {
  const { leader, highestSingle, biggestJump, mostConsistent, earlyMomentum, closer } = payload;
  const el = document.getElementById('awardsList');
  const items = [];
  const A = allocateAwards();
  const viewer = getCurrentUser();

  function numOrHidden(subjectName, value, fmtFn = v => fmt(v)) {
    const row = globalData.find(r => r.Name === subjectName) || {};
    return maySeeNumbers(viewer, row, globalData) ? `<span class="stat">${fmtFn(value)}</span>` : 'hidden';
  }

  if (leader) items.push(`<li><span class="accent font-semibold">Overall Leader:</span> ${safe(leader.name)} with ${numOrHidden(leader.name, leader.total)} steps so far.</li>`);
  if (highestSingle) items.push(`<li><span class="accent-2 font-semibold">Highest Single Day:</span> ${safe(highestSingle.person)} with ${numOrHidden(highestSingle.person, highestSingle.value)} on ${highestSingle.day}.</li>`);

  const t30 = A.pick(payload.most30kList.filter(p=>p.thirtyK>0));
  if (t30) items.push(`<li><span class="text-emerald-300 font-semibold">Ultra Day Hunter:</span> ${safe(t30.name)} with ${numOrHidden(t30.name, t30.thirtyK, v=>v)} day(s) at ≥ 30k.</li>`);

  const t20 = A.pick(payload.most20kList.filter(p=>p.cherylCount>0));
  if (t20) items.push(`<li><span class="text-yellow-300 font-semibold">Cheryl Champ:</span> ${safe(t20.name)} with ${numOrHidden(t20.name, t20.cherylCount, v=>v)} day(s) at ≥ 20k.</li>`);

  const t15 = A.pick(payload.most15kList.filter(p=>p.fifteenK>0));
  if (t15) items.push(`<li><span class="text-lime-300 font-semibold">15k Achiever:</span> ${safe(t15.name)} with ${numOrHidden(t15.name, t15.fifteenK, v=>v)} day(s) at ≥ 15k.</li>`);

  const t10 = A.pick(payload.most10kList.filter(p=>p.tenK>0));
  if (t10) items.push(`<li><span class="text-green-300 font-semibold">Ten-K Streaker:</span> ${safe(t10.name)} with ${numOrHidden(t10.name, t10.tenK, v=>v)} day(s) at ≥ 10k.</li>`);

  const t25 = A.pick(payload.most2_5kList.filter(p=>p.two5K>0));
  if (t25) items.push(`<li><span class="text-cyan-300 font-semibold">Showing Up Award:</span> ${safe(t25.name)} with ${numOrHidden(t25.name, t25.two5K, v=>v)} day(s) at ≥ 2.5k.</li>`);

  const t1k = A.pick(payload.most1kList.filter(p=>p.oneK>0));
  if (t1k) items.push(`<li><span class="text-blue-300 font-semibold">Participation Ribbon:</span> ${safe(t1k.name)} with ${numOrHidden(t1k.name, t1k.oneK, v=>v)} day(s) at ≥ 1k.</li>`);

  if (biggestJump && biggestJump.amount > 0 && A.pick([biggestJump])) {
    items.push(`<li><span class="text-rose-300 font-semibold">Biggest Jump:</span> ${safe(biggestJump.person)} jumped ${numOrHidden(biggestJump.person, biggestJump.amount)} from ${biggestJump.from} to ${biggestJump.to}.</li>`);
  }
  if (mostConsistent && A.pick([mostConsistent])) {
    items.push(`<li><span class="text-purple-300 font-semibold">Consistency Star:</span> ${safe(mostConsistent.name)} with the lowest day-to-day variation (${numOrHidden(mostConsistent.name, mostConsistent.stddev)}).</li>`);
  }
  if (earlyMomentum && A.pick([earlyMomentum])) {
    items.push(`<li><span class="text-orange-300 font-semibold">Early Momentum:</span> ${safe(earlyMomentum.name)} with the strongest Mon–Wed total (${numOrHidden(earlyMomentum.name, earlyMomentum.firstHalfSum)}).</li>`);
  }
  if (closer && A.pick([closer])) {
    items.push(`<li><span class="text-pink-300 font-semibold">Closer Award:</span> ${safe(closer.name)} with the strongest Thu–Sat total (${numOrHidden(closer.name, closer.secondHalfSum)}).</li>`);
  }

  const imp = A.pick(payload.mostImprovedList.filter(p=>p.firstHalfSum>0 || p.secondHalfSum>0));
  if (imp) items.push(`<li><span class="text-orange-300 font-semibold">Most Improved:</span> ${safe(imp.name)} with a ${numOrHidden(imp.name, Math.round(imp.pctImprovement*100), v=>v + '%')} Thu–Sat vs Mon–Wed improvement.</li>`);

  const med = A.pick(payload.medianMasterList.filter(p=>p.medianCapped>0));
  if (med) items.push(`<li><span class="text-fuchsia-300 font-semibold">Median Master:</span> ${safe(med.name)} with the highest capped median (${numOrHidden(med.name, med.medianCapped)}).</li>`);

  const rep = A.pick(payload.reportingChampList);
  if (rep) items.push(`<li><span class="text-teal-300 font-semibold">Reporting Champ:</span> ${safe(rep.name)} with the fewest missing check-ins (${numOrHidden(rep.name, rep.missingCount, v=>v)} missing).</li>`);

  const stk = A.pick(payload.streakBossList.filter(p=>p.longestStreak1k>0));
  if (stk) items.push(`<li><span class="text-stone-300 font-semibold">Streak Boss:</span> ${safe(stk.name)} with the longest days-in-a-row at ≥ 1k (${numOrHidden(stk.name, stk.longestStreak1k, v=>v)}).</li>`);

  el.innerHTML = items.length ? items.join('') : '<li>No awards yet. Add some steps.</li>';
}

function renderCharts({ people }) {
  const labels = DAY_ORDER;
  const perDayCtx = document.getElementById('perDayChart').getContext('2d');
  const stackedCtx = document.getElementById('stackedTotalChart').getContext('2d');
  const linesCtx = document.getElementById('linesChart').getContext('2d');

  // Per-day bars per person (stacked by day for clarity on mobile)
  const datasets = people.map((p, i) => ({
    label: p.name,
    data: p.days.map(v => Number.isFinite(v) ? v : 0),
    borderWidth: 1
  }));

  charts.push(new Chart(perDayCtx, {
    type: 'bar',
    data: { labels, datasets },
    options: {
      responsive: true,
      scales: { y: { beginAtZero: true } },
      plugins: { legend: { display: false } }
    }
  }));

  // Stacked family total per day
  const familyTotals = labels.map((_, idx) => people.reduce((sum, p) => sum + (Number.isFinite(p.days[idx]) ? p.days[idx] : 0), 0));
  charts.push(new Chart(stackedCtx, {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Family Total', data: familyTotals, borderWidth: 1 }]},
    options: { responsive: true, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
  }));

  // Lines per person
  charts.push(new Chart(linesCtx, {
    type: 'line',
    data: {
      labels,
      datasets: people.map(p => ({ label: p.name, data: p.days.map(v => Number.isFinite(v) ? v : null), spanGaps: true }))
    },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  }));
}

function renderCards({ people }) {
  const container = document.getElementById('cards');
  const viewer = getCurrentUser();
  container.innerHTML = people.map(p => {
    const badges = [];
    p.days.forEach((v, idx) => {
      if (!Number.isFinite(v)) return;
      const day = DAY_ORDER[idx];
      if (v >= THIRTY_K_THRESHOLD) badges.push(`<span class="chip bg-emerald-500/15 text-emerald-300" title="${day}">30k</span>`);
      if (v >= CHERYL_THRESHOLD) badges.push(`<span class="chip bg-yellow-500/15 text-yellow-300" title="${day}">Cheryl</span>`);
      if (v >= DAILY_GOAL_15K) badges.push(`<span class="chip bg-lime-500/15 text-lime-300" title="${day}">15k</span>`);
      if (v >= DAILY_GOAL_10K) badges.push(`<span class="chip bg-green-500/15 text-green-300" title="${day}">10k</span>`);
      if (v >= DAILY_GOAL_2_5K) badges.push(`<span class="chip bg-cyan-500/15 text-cyan-300" title="${day}">2.5k</span>`);
      if (v >= DAILY_GOAL_1K) badges.push(`<span class="chip bg-blue-500/15 text-blue-300" title="${day}">1k</span>`);
    });
    const subjectRow = globalData.find(r => r.Name === p.name) || {};
    const canSee = maySeeNumbers(viewer, subjectRow, globalData);
    const dash = '<span class="text-white/40">—</span>';
    const total = canSee ? fmt(p.total) : dash;
    const avg = canSee ? fmt(p.avg) : dash;
    const best = canSee ? fmt(p.best) : dash;

    return `
    <article class="card p-4 space-y-2">
      <div class="flex items-center justify-between">
        <h4 class="text-lg font-bold">${safe(p.name)}</h4>
        <div class="flex flex-wrap gap-1">${badges.join('')}</div>
      </div>
      <div class="grid grid-cols-3 gap-2 text-center">
        <div class="bg-white/5 rounded-lg p-2">
          <div class="text-xs text-white/60">Total</div>
          <div class="text-xl font-extrabold stat">${total}</div>
        </div>
        <div class="bg-white/5 rounded-lg p-2">
          <div class="text-xs text-white/60">Average</div>
          <div class="text-xl font-extrabold stat">${avg}</div>
        </div>
        <div class="bg-white/5 rounded-lg p-2">
          <div class="text-xs text-white/60">Best Day</div>
          <div class="text-xl font-extrabold stat">${best}</div>
        </div>
      </div>
      <div class="text-xs text-white/70">
        ${DAY_ORDER.map((d, i) => {
          const v = p.days[i];
          const val = (canSee && Number.isFinite(v)) ? fmt(v) : '<span class="text-white/40">—</span>';
          return `<span class="mr-2">${d.slice(0,3)}: <span class="stat">${val}</span></span>`;
        }).join(' ')}
      </div>
    </article>`;
  }).join('');
}

function renderMissing({ missing }) {
  const el = document.getElementById('missingList');
  const items = [];
  missing.forEach(m => {
    if (m.blanks.length) {
      const nudge = pickNudge();
      items.push(
        `<li class="flex items-start justify-between gap-2">
          <div>
            <span class="font-semibold">${safe(m.name)}</span> missing: ${m.blanks.join(', ')}.
            <span class="text-white/60 italic ml-1">Nudge: ${safe(nudge)}</span>
          </div>
          <button class="px-2 py-1 text-xs rounded bg-white/10 hover:bg-white/20 copy-nudge"
                  data-name="${safe(m.name)}"
                  data-days="${safe(m.blanks.join(', '))}"
                  data-nudge="${safe(nudge)}">Copy nudge</button>
        </li>`
      );
    }
  });
  el.innerHTML = items.length ? items.join('') : '<li>Everyone has reported for all days so far.</li>';

  el.onclick = async (e) => {
    const btn = e.target.closest('.copy-nudge');
    if (!btn) return;
    const name = btn.dataset.name;
    const days = btn.dataset.days;
    const nudge = btn.dataset.nudge;
    const msg = `Hey ${name} — looks like no report for ${days}. ${nudge}`;

    const prev = btn.textContent;
    const setTempLabel = (text) => {
      btn.textContent = text;
      setTimeout(() => (btn.textContent = prev), 1200);
    };

    const copyWithExecCommand = (text) => {
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        ta.setSelectionRange(0, ta.value.length);
        const ok = document.execCommand && document.execCommand('copy');
        document.body.removeChild(ta);
        return !!ok;
      } catch (err) {
        return false;
      }
    };

    // Try modern clipboard API first (may fail on insecure origins)
    if (navigator.clipboard && window.isSecureContext) {
      try {
        await navigator.clipboard.writeText(msg);
        setTempLabel('Copied!');
        return;
      } catch (err) {
        // fall through to legacy copy
      }
    }

    // Fallback to execCommand
    if (copyWithExecCommand(msg)) {
      setTempLabel('Copied!');
      return;
    }

    // Final fallback: show alert with manual copy instructions
    alert(`Copy this message manually:\n\n${msg}\n\nSelect the text above, then press Cmd/Ctrl+C to copy and paste it where needed.`);
    setTempLabel('Copy failed');
  };
}

function pickNudge() {
  return NUDGES[Math.floor(Math.random()*NUDGES.length)];
}

// ------- Utils -------
function fmt(n) { return Number.isFinite(n) ? n.toLocaleString() : ""; }
function safe(s) { return String(s ?? '').replace(/[&<>]/g, c => ({'&':'&','<':'<','>':'>'}[c])); }

/* ---------------- Privacy / Login Helpers ---------------- */
// Returns current user name or null
function getCurrentUser() {
  return localStorage.getItem('kw.currentUser') || null;
}
function setCurrentUser(name) {
  if (name) localStorage.setItem('kw.currentUser', name);
  const link = document.getElementById('switchUserLink');
  if (link) link.classList.remove('hidden');
}
function clearCurrentUser() {
  localStorage.removeItem('kw.currentUser');
  const link = document.getElementById('switchUserLink');
  if (link) link.classList.add('hidden');
}

// Privacy rule as specified
function maySeeNumbers(viewerName, subjectRow, allRows) {
  if (!subjectRow) return false;
  if (subjectRow.Name === viewerName) return true;
  const mode = (subjectRow.ShareMode || "private").toLowerCase();
  if (mode === "public") return true;
  if (mode === "friends") {
    const subjectList = (subjectRow.ShareWith || "").split(",").map(s=>s.trim()).filter(Boolean);
    const viewerRow = allRows.find(r => r.Name === viewerName);
    const viewerList = ((viewerRow?.ShareWith) || "").split(",").map(s=>s.trim()).filter(Boolean);
    return subjectList.includes(viewerName) && viewerList.includes(subjectRow.Name);
  }
  return false; // private
}

// Ensure user selection before rendering sensitive sections
function ensureUserSelected(cb) {
  if (DISABLE_LOGIN) {
    // No login flow; just render
    cb();
    return;
  }
  const current = getCurrentUser();
  if (current && globalData.some(r => r.Name === current)) {
    setCurrentUser(current); // show link
    cb();
    return;
  }
  showLoginModal(cb);
}

// Build and show login modal
function showLoginModal(onSuccess) {
  if (DISABLE_LOGIN) {
    onSuccess();
    return;
  }
  const existing = document.getElementById('loginModal');
  if (existing) existing.remove();
  const names = globalData.map(r => r.Name);
  const modal = document.createElement('div');
  modal.id = 'loginModal';
  modal.className = 'fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4';
  modal.innerHTML = `
    <div class="card w-full max-w-sm p-5 space-y-4">
      <h2 class="text-lg font-bold">Select User</h2>
      <p class="text-xs text-white/70">Enter a PIN (if you have one) or select your name. If your row has a PIN you must enter it correctly.</p>
      <div class="space-y-2">
        <label class="block text-xs uppercase tracking-wide text-white/60">PIN</label>
        <input id="loginPin" type="password" inputmode="numeric" class="w-full px-3 py-2 rounded bg-white/10 outline-none focus:ring ring-white/20" placeholder="PIN (optional)" />
      </div>
      <div class="space-y-2">
        <label class="block text-xs uppercase tracking-wide text-white/60">Select Name</label>
        <select id="loginName" class="w-full px-3 py-2 rounded bg-white/10 outline-none focus:ring ring-white/20">
          <option value="">— Select name —</option>
          ${names.map(n => `<option value="${safe(n)}">${safe(n)}</option>`).join('')}
        </select>
      </div>
      <div id="loginError" class="text-rose-300 text-xs h-4"></div>
      <div class="flex justify-end gap-2 pt-2">
        <button id="loginEnter" class="px-3 py-2 rounded-lg bg-white/10 hover:bg-white/20 text-sm font-semibold">Enter</button>
      </div>
    </div>
  `;
  document.body.appendChild(modal);

  function resolve() {
    const pinVal = document.getElementById('loginPin').value.trim();
    const selName = document.getElementById('loginName').value.trim();
    const errEl = document.getElementById('loginError');

    // PIN direct match path
    if (pinVal) {
      const matches = globalData.filter(r => r.PIN && r.PIN === pinVal);
      if (matches.length === 1) {
        setCurrentUser(matches[0].Name);
        modal.remove();
        onSuccess();
        return;
      }
      if (matches.length > 1) {
        errEl.textContent = "PIN matches multiple users. Select your name.";
        return;
      }
      // else fall through to name logic
    }

    if (selName) {
      const row = globalData.find(r => r.Name === selName);
      if (!row) {
        errEl.textContent = "Unknown name.";
        return;
      }
      if (row.PIN) {
        if (!pinVal) {
          errEl.textContent = "PIN required for that user.";
          return;
        }
        if (row.PIN !== pinVal) {
          errEl.textContent = "Incorrect PIN.";
          return;
        }
      }
      setCurrentUser(row.Name);
      modal.remove();
      onSuccess();
      return;
    }

    errEl.textContent = "Enter a valid PIN or select a name.";
  }

  document.getElementById('loginEnter').addEventListener('click', resolve);
  modal.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      resolve();
    }
  });
}

// Override parse/render gating by wrapping renderAll call
function gatedRenderAll() {
  ensureUserSelected(() => renderAll());
}

/* ---------------- End Privacy / Login ---------------- */

// ------- Events -------
window.addEventListener('DOMContentLoaded', async () => {
  await loadConfig();

  // Hide/disable UI based on config
  if (DISABLE_CSV_UPLOAD) {
    document.getElementById('fileInput')?.classList.add('hidden');
    document.getElementById('reloadBtn')?.classList.add('hidden');
  }

  if (DISABLE_LOGIN) {
    const switchLink = document.getElementById('switchUserLink');
    if (switchLink) switchLink.classList.add('hidden');
  }

  // Load weeks manifest and initial CSV selection (if present)
  await loadWeeksManifest();
  if (weeksManifest && weeksManifest.length) {
    buildWeekSelector(weeksManifest);
    // Default selection preference: label === "Current Week" OR file containing kings_walk_week.csv
    let defaultIdx = weeksManifest.findIndex(w => (w.label && w.label === 'Current Week') || (w.file && w.file.indexOf('kings_walk_week.csv') !== -1));
    if (defaultIdx === -1) defaultIdx = 0;
    const sel = weeksManifest[defaultIdx];
    // set select value if present
    if (document.getElementById('weekSelector')) {
      document.getElementById('weekSelector').value = sel.file || '';
    }
    await loadCsvForFile(sel.file, sel.label);
  } else {
    // fallback to original behavior when no manifest entries
    await tryFetchDefault();
  }

  if (!DISABLE_CSV_UPLOAD) {
    document.getElementById('reloadBtn')?.addEventListener('click', () => {
      if (currentWeekFile) {
        loadCsvForFile(currentWeekFile, currentWeekLabel);
      } else {
        tryFetchDefault();
      }
    });

    document.getElementById('fileInput')?.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = (ev) => parseCSV(ev.target.result);
      reader.readAsText(file);
      setStatus(`Loaded ${file.name}`, 'ok');
    });
  }

  if (!DISABLE_LOGIN) {
    const switchLink = document.getElementById('switchUserLink');
    if (switchLink) {
      switchLink.addEventListener('click', () => {
        showLoginModal(() => renderAll());
      });
    }
  }
});

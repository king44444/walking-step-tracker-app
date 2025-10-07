import { loadConfig } from './config.js';
import { fetchWeeks, fetchWeekData, fetchLifetime, createWeek, deleteWeek } from './api.js';
import { ingestRows } from './normalize.js';
import { computeStats } from './stats.js';
import { renderAll } from './render.js';
import { setStatus, safe } from './utils.js';

let charts = [];
let globalData = [];
let currentWeek = null;
let weeksList = [];
let lifetimeMap = new Map();

export async function buildWeekSelector() {
  weeksList = await fetchWeeks();
  const sel = document.getElementById('weekSelector');

  function valOf(w){ return w.starts_on || w.week || ''; }
  function labelOf(w){ return w.label || w.starts_on || w.week || ''; }

  sel.innerHTML = weeksList.map(w => {
    const label = labelOf(w);
    const value = valOf(w);
    return `<option value="${safe(value)}">${safe(label)} ${w.finalized ? '— finalized' : ''}</option>`;
  }).join('');
  sel.onchange = async () => { try { await loadWeek(sel.value); } catch (e) { console.error(e); setStatus('Failed to load week', 'err'); } };

  // Management UI: create + delete
  injectWeekManageUI(sel);

  if (weeksList.length) {
    // Prefer the most recent week that actually has data
    let picked = null;
    for (const w of weeksList) {
      const v = valOf(w);
      try {
        const d = await fetchWeekData(v);
        if (d && d.ok !== false && Array.isArray(d.rows) && d.rows.length > 0) { picked = v; break; }
      } catch (e) {
        // ignore and try next
      }
    }
    currentWeek = picked || valOf(weeksList[0]);
    sel.value = currentWeek;
    try { await loadWeek(currentWeek); } catch (e) { console.error(e); setStatus('Failed to load week', 'err'); }
  } else {
    setStatus('No weeks yet. Create one.', 'warn');
  }
}

export async function loadWeek(week) {
  setStatus(`Loading ${week}…`);
  const data = await fetchWeekData(week);
  if (!data || data.ok === false) {
    setStatus('Failed to load week data', 'err');
    return;
  }
  currentWeek = data.week;
  globalData = ingestRows(data.rows || []);
  const stats = computeStats(globalData, lifetimeMap, data.todayIdx, data);
  renderAll(stats, globalData, charts);
  setStatus(`Loaded ${data.label || data.week || week} (${data.source || 'live'})`, 'ok');
}

window.addEventListener('DOMContentLoaded', async () => {
  try {
    await loadConfig();
    try { lifetimeMap = await fetchLifetime(); } catch (e) { lifetimeMap = new Map(); }
    await buildWeekSelector();
  } catch (e) {
    console.error(e);
    setStatus('Failed to load weeks', 'err');
  }
});

function normalizeInputDate(s) {
  const m = String(s||'').trim().match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
  if (!m) return null;
  const y=+m[1], mo=+m[2], d=+m[3];
  const dt = new Date(Date.UTC(y, mo-1, d));
  if (dt.getUTCFullYear()!==y || (dt.getUTCMonth()+1)!==mo || dt.getUTCDate()!==d) return null;
  return `${String(y).padStart(4,'0')}-${String(mo).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
}

function injectWeekManageUI(anchorEl) {
  const parent = anchorEl.parentElement || anchorEl;
  // container
  let box = document.getElementById('weekManageBox');
  if (!box) {
    box = document.createElement('div');
    box.id = 'weekManageBox';
    box.className = 'flex items-center gap-2 mt-2';
    parent.appendChild(box);
  } else {
    box.innerHTML = '';
  }
  // input
  const inp = document.createElement('input');
  inp.type = 'text';
  inp.placeholder = 'YYYY-MM-DD';
  inp.className = 'px-2 py-1 rounded bg-white/5 text-sm';
  box.appendChild(inp);
  // create button
  const btn = document.createElement('button');
  btn.textContent = 'Create Week';
  btn.className = 'px-2 py-1 rounded bg-white/10 hover:bg-white/20 text-sm';
  box.appendChild(btn);
  btn.onclick = async () => {
    const norm = normalizeInputDate(inp.value);
    if (!norm) { setStatus('Invalid date. Use YYYY-MM-DD.', 'warn'); return; }
    try {
      await createWeek(norm);
      weeksList = await fetchWeeks();
      const opts = weeksList.map(w => `<option value="${safe(w.starts_on||w.week)}">${safe(w.label||w.starts_on||w.week)}</option>`).join('');
      anchorEl.innerHTML = opts;
      anchorEl.value = norm;
      await loadWeek(norm);
      setStatus(`Created ${norm}`, 'ok');
    } catch (e) {
      setStatus('Failed to create week', 'err');
    }
  };
  // delete button
  const del = document.createElement('button');
  del.textContent = 'Delete Selected';
  del.className = 'px-2 py-1 rounded bg-rose-600/20 hover:bg-rose-600/30 text-sm';
  box.appendChild(del);
  del.onclick = async () => {
    const val = anchorEl.value || '';
    if (!val) return;
    let resp = await deleteWeek(val, false);
    if (!resp.ok && resp.error === 'week_has_entries') {
      if (confirm(`Week has ${resp.count||'some'} entries. Delete week and its entries?`)) {
        resp = await deleteWeek(val, true);
      } else {
        return;
      }
    }
    if (resp.ok) {
      weeksList = await fetchWeeks();
      const opts = weeksList.map(w => `<option value="${safe(w.starts_on||w.week)}">${safe(w.label||w.starts_on||w.week)}</option>`).join('');
      anchorEl.innerHTML = opts;
      if (weeksList.length) {
        const v = weeksList[0].starts_on || weeksList[0].week;
        anchorEl.value = v;
        await loadWeek(v);
      } else {
        setStatus('No weeks yet. Create one.', 'warn');
      }
    } else {
      setStatus(resp.error || 'Failed to delete', 'err');
    }
  };
}

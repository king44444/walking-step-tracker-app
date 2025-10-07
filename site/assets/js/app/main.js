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
  const count = Array.isArray(globalData) ? globalData.length : 0;
  setStatus(`Loaded ${data.label || data.week || week} (${data.source || 'live'}) — ${count} rows`, 'ok');
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

// Admin-only week management UI has been removed from public site.

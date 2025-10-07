import { loadConfig } from './config.js';
import { fetchWeeks, fetchWeekData, fetchLifetime } from './api.js';
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
  sel.innerHTML = weeksList.map(w => {
    const label = w.label || w.week;
    return `<option value="${safe(w.week)}">${safe(label)} ${w.finalized ? '— finalized' : ''}</option>`;
  }).join('');
  sel.onchange = () => loadWeek(sel.value);
  if (weeksList.length) {
    currentWeek = weeksList[0].week;
    sel.value = currentWeek;
    await loadWeek(currentWeek);
  } else {
    setStatus('No weeks yet. Ask admin to create one.', 'warn');
  }
}

export async function loadWeek(week) {
  setStatus(`Loading ${week}…`);
  const data = await fetchWeekData(week);
  currentWeek = data.week;
  globalData = ingestRows(data.rows || []);
  const stats = computeStats(globalData, lifetimeMap, data.todayIdx, data);
  renderAll(stats, globalData, charts);
  setStatus(`Loaded ${data.label || data.week} (${data.source})`, 'ok');
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


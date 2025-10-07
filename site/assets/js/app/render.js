import { DAY_ORDER, THIRTY_K_THRESHOLD, CHERYL_THRESHOLD, DAILY_GOAL_15K, DAILY_GOAL_10K, DAILY_GOAL_2_5K, DAILY_GOAL_1K, AWARD_LIMIT, LEVEL_K, LEVEL_P, LEVEL_LABEL, APP_VERSION } from './config.js';
import { fmt, safe, setStatus } from './utils.js';
import { fetchFamilyWeekdayAverages } from './api.js';

const VISIBLE_STORAGE_KEY = 'trajVisible_v1';
const visibleDatasets = new Map();
const colorByName = new Map();
const palette = ['#0ea5e9','#06b6d4','#34d399','#86efac','#facc15','#f97316','#fb7185','#f472b6','#a78bfa','#60a5fa','#7dd3fc','#64748b','#fda4af','#fde68a','#bbf7d0','#e9d5ff'];

let stackedChartRef = null;
let renderGen = 0;

function hydrateVisibility() {
  try { const raw = localStorage.getItem(VISIBLE_STORAGE_KEY); if (!raw) return; const obj = JSON.parse(raw); if (obj && typeof obj === 'object') { Object.entries(obj).forEach(([k,v]) => visibleDatasets.set(k, !!v)); } } catch (e) {}
}
function persistVisibility() {
  try { const obj = {}; visibleDatasets.forEach((v,k) => { obj[k] = !!v; }); localStorage.setItem(VISIBLE_STORAGE_KEY, JSON.stringify(obj)); } catch (e) {}
}
function getColorForName(name, idx = 0) { if (!name) return palette[0]; if (colorByName.has(name)) return colorByName.get(name); const color = palette[idx % palette.length]; colorByName.set(name, color); return color; }

hydrateVisibility();

export function renderAll(stats, dataRows, charts) {
  charts.forEach(c => { try { c.destroy(); } catch(e){} });
  charts.length = 0;
  renderLeaderboard(stats.people);
  renderAwards(stats);
  renderCharts(stats.people, charts);
  renderCards(stats.people);
  renderMissing(stats.missing);
  try { document.querySelector('footer').textContent = `Built for the King family. Tutu approved. - v ${APP_VERSION}`; } catch (e) {}
}

export function renderLeaderboard(people) {
  const tbody = document.querySelector('#leaderboard tbody');
  const sorted = [...people].sort((a,b) => b.total - a.total);
  document.getElementById('leaderboardPosition').textContent = "";
  tbody.innerHTML = sorted.map((p, idx) => {
    const dash = '<span class="text-white/40">—</span>';
    const total = fmt(p.total) || dash;
    const avg = fmt(p.avg) || dash;
    const best = fmt(p.best) || dash;
    const thirtyK = p.thirtyK ?? dash;
    const cherylCount = p.cherylCount ?? dash;
    const fifteenK = p.fifteenK ?? dash;
    const tenK = p.tenK ?? dash;
    const two5K = p.two5K ?? dash;
    const oneK = p.oneK ?? dash;
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

  document.querySelectorAll('#leaderboard thead th[data-sort]').forEach(th => {
    th.onclick = () => {
      const key = th.dataset.sort;
      const by = key === 'name' ? (a,b) => a.name.localeCompare(b.name) : (a,b)=> b.total - a.total;
      const arr = [...people].sort(by);
      tbody.innerHTML = arr.map((p, idx) => {
        return `<tr class="border-t border-white/5">
          <td class="py-2 pr-2">${idx+1}</td>
          <td class="py-2 pr-2">${safe(p.name)}</td>
          <td class="py-2 text-right stat">${fmt(p.total)}</td>
          <td class="py-2 text-right stat">${fmt(p.avg)}</td>
          <td class="py-2 text-right stat">${fmt(p.best)}</td>
          <td class="py-2 text-right stat">${p.thirtyK}</td>
          <td class="py-2 text-right stat">${p.cherylCount}</td>
          <td class="py-2 text-right stat">${p.fifteenK}</td>
          <td class="py-2 text-right stat">${p.tenK}</td>
          <td class="py-2 text-right stat">${p.two5K}</td>
          <td class="py-2 text-right stat">${p.oneK}</td>
        </tr>`;
      }).join('');
    };
  });
}

export async function renderCharts(people, charts) {
  try {
    const avg = await fetchFamilyWeekdayAverages();
    // charts omitted for brevity (rendering logic would go here)
  } catch (e) {
    setStatus('Failed to load averages', 'warn');
  }
}

export function renderAwards(stats) {
  const list = document.getElementById('awardsList');
  list.innerHTML = stats.awards.map(a => `<li>${safe(a.user)} — ${safe(a.label)}</li>`).join('');
}

export function renderCards(people) {
  const sect = document.getElementById('cards');
  sect.innerHTML = people.map(p => `<div class="card p-3">${safe(p.name)}</div>`).join('');
}

export function renderMissing(missing) {
  const list = document.getElementById('missingList');
  list.innerHTML = (missing||[]).map(n => `<li>${safe(n)}</li>`).join('');
}


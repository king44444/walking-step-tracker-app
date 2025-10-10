import { DAY_ORDER, THIRTY_K_THRESHOLD, CHERYL_THRESHOLD, DAILY_GOAL_15K, DAILY_GOAL_10K, DAILY_GOAL_2_5K, DAILY_GOAL_1K, AWARD_LIMIT, LEVEL_K, LEVEL_P, LEVEL_LABEL, APP_VERSION } from './config.js';
import { fmt, safe, pickNudge, setStatus } from './utils.js';
import { fetchFamilyWeekdayAverages } from './api.js';

// Module scope state: visibility, colors, persistence version
const VISIBLE_STORAGE_KEY = 'trajVisible_v1';
const visibleDatasets = new Map();
const colorByName = new Map();
const palette = [
  '#0ea5e9','#06b6d4','#34d399','#86efac','#facc15','#f97316','#fb7185','#f472b6',
  '#a78bfa','#60a5fa','#7dd3fc','#64748b','#fda4af','#fde68a','#bbf7d0','#e9d5ff'
];

let stackedChartRef = null;
let renderGen = 0;

function hydrateVisibility() {
  try {
    const raw = localStorage.getItem(VISIBLE_STORAGE_KEY);
    if (!raw) return;
    const obj = JSON.parse(raw);
    if (obj && typeof obj === 'object') {
      Object.entries(obj).forEach(([k,v]) => visibleDatasets.set(k, !!v));
    }
  } catch (e) { /* ignore */ }
}
function persistVisibility() {
  try {
    const obj = {};
    visibleDatasets.forEach((v,k) => { obj[k] = !!v; });
    localStorage.setItem(VISIBLE_STORAGE_KEY, JSON.stringify(obj));
  } catch (e) { /* ignore */ }
}
function getColorForName(name, idx = 0) {
  if (!name) return palette[0];
  if (colorByName.has(name)) return colorByName.get(name);
  const color = palette[idx % palette.length];
  colorByName.set(name, color);
  return color;
}

// hydrate on module load
hydrateVisibility();

// Render helpers expect callers to pass the computed stats and a mutable charts array
export function renderAll(stats, dataRows, charts) {
  try { console.debug('[renderAll] people:', Array.isArray(stats?.people) ? stats.people.length : -1, 'todayIdx:', stats?.todayIdx, 'rows:', Array.isArray(dataRows) ? dataRows.length : -1); } catch(e){}
  // destroy existing charts
  charts.forEach(c => { try { c.destroy(); } catch(e){} });
  charts.length = 0;

  renderLeaderboard(stats.people);
  renderAwards(stats);
  renderCharts(stats.people, charts);
  renderCards(stats.people);
  renderMissing(stats.missing);

  // show app version in the footer
  try {
    document.querySelector('footer').textContent = `Built for the King family. Tutu approved. - v ${APP_VERSION}`;
  } catch (e) { /* ignore if footer missing */ }
}

export function renderLeaderboard(people) {
  try { console.debug('[renderLeaderboard] people:', Array.isArray(people) ? people.length : -1, people?.slice?.(0,3)); } catch(e){}
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
      <td class="py-2 pr-2">${p.id ? `<a href=\"./user.php?id=${encodeURIComponent(p.id)}\" class=\"text-blue-300 hover:underline\">${safe(p.name)}</a>` : safe(p.name)}</td>
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
          <td class="py-2 pr-2">${p.id ? `<a href=\"./user.php?id=${encodeURIComponent(p.id)}\" class=\"text-blue-300 hover:underline\">${safe(p.name)}</a>` : safe(p.name)}</td>
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

function allocateAwards() {
  const taken = new Map();
  function pick(list) {
    for (const p of list) {
      const id = p.name || p.person;
      const count = taken.get(id) || 0;
      if (count < AWARD_LIMIT) { // keep fair; AWARD_LIMIT configurable
        taken.set(id, count + 1);
        return p;
      }
    }
    return null;
  }
  return { pick, taken };
}

export function renderAwards(payload) {
  const { leader, highestSingle, biggestJump, mostConsistent, earlyMomentum, closer } = payload;
  const el = document.getElementById('awardsList');
  const items = [];
  const A = allocateAwards();

  function stat(value, fmtFn = v => fmt(v)) {
    return `<span class="stat">${fmtFn(value)}</span>`;
  }

  function label(code, fallback) {
    try {
      const txt = (typeof CUSTOM_AWARD_LABELS !== 'undefined' && CUSTOM_AWARD_LABELS[code]) ? CUSTOM_AWARD_LABELS[code] : null;
      return txt || fallback || code;
    } catch (e) {
      return fallback || code;
    }
  }

  if (leader) items.push(`<li><span class="accent font-semibold">Overall Leader:</span> ${safe(leader.name)} with ${stat(leader.total)} steps.</li>`);
  if (highestSingle) items.push(`<li><span class="accent-2 font-semibold">Highest Single Day:</span> ${safe(highestSingle.person)} with ${stat(highestSingle.value)} on ${highestSingle.day}.</li>`);

  const t30 = A.pick(payload.most30kList.filter(p=>p.thirtyK>0));
  if (t30) items.push(`<li><span class="text-emerald-300 font-semibold">Ultra Day Hunter:</span> ${safe(t30.name)} with ${stat(t30.thirtyK, v=>v)} day(s) ≥ 30k.</li>`);

  const t20 = A.pick(payload.most20kList.filter(p=>p.cherylCount>0));
  if (t20) items.push(`<li><span class="text-yellow-300 font-semibold">Cheryl Champ:</span> ${safe(t20.name)} with ${stat(t20.cherylCount, v=>v)} day(s) ≥ 20k.</li>`);

  const t15 = A.pick(payload.most15kList.filter(p=>p.fifteenK>0));
  if (t15) items.push(`<li><span class="text-lime-300 font-semibold">15k Achiever:</span> ${safe(t15.name)} with ${stat(t15.fifteenK, v=>v)} day(s) ≥ 15k.</li>`);

  const t10 = A.pick(payload.most10kList.filter(p=>p.tenK>0));
  if (t10) items.push(`<li><span class="text-green-300 font-semibold">Ten-K Streaker:</span> ${safe(t10.name)} with ${stat(t10.tenK, v=>v)} day(s) ≥ 10k.</li>`);

  const t25 = A.pick(payload.most2_5kList.filter(p=>p.two5K>0));
  if (t25) items.push(`<li><span class="text-cyan-300 font-semibold">Showing Up Award:</span> ${safe(t25.name)} with ${stat(t25.two5K, v=>v)} day(s) ≥ 2.5k.</li>`);

  const t1k = A.pick(payload.most1kList.filter(p=>p.oneK>0));
  if (t1k) items.push(`<li><span class="text-blue-300 font-semibold">Participation Ribbon:</span> ${safe(t1k.name)} with ${stat(t1k.oneK, v=>v)} day(s) ≥ 1k.</li>`);

  if (biggestJump && biggestJump.amount > 0 && A.pick([biggestJump])) {
    items.push(`<li><span class="text-rose-300 font-semibold">Biggest Jump:</span> ${safe(biggestJump.person)} jumped ${stat(biggestJump.amount)} from ${biggestJump.from} to ${biggestJump.to}.</li>`);
  }
  if (mostConsistent && A.pick([mostConsistent])) {
    items.push(`<li><span class="text-purple-300 font-semibold">Consistency Star:</span> ${safe(mostConsistent.name)} lowest day-to-day variation (${stat(mostConsistent.stddev)}).</li>`);
  }
  if (earlyMomentum && A.pick([earlyMomentum])) {
    items.push(`<li><span class="text-orange-300 font-semibold">Early Momentum:</span> ${safe(earlyMomentum.name)} strongest Mon–Wed (${stat(earlyMomentum.firstHalfSum)}).</li>`);
  }
  if (closer && A.pick([closer])) {
    items.push(`<li><span class="text-pink-300 font-semibold">Closer Award:</span> ${safe(closer.name)} strongest Thu–Sat (${stat(closer.secondHalfSum)}).</li>`);
  }

  const imp = A.pick(payload.mostImprovedList.filter(p=>p.firstHalfSum>0 || p.secondHalfSum>0));
  if (imp) items.push(`<li><span class="text-orange-300 font-semibold">Most Improved:</span> ${safe(imp.name)} ${stat(Math.round(imp.pctImprovement*100), v=>v + '%')} improvement Thu–Sat vs Mon–Wed.</li>`);

  const med = A.pick(payload.medianMasterList.filter(p=>p.medianCapped>0));
  if (med) items.push(`<li><span class="text-fuchsia-300 font-semibold">Median Master:</span> ${safe(med.name)} capped median ${stat(med.medianCapped)}.</li>`);

  const rep = A.pick(payload.reportingChampList);
  if (rep) items.push(`<li><span class="text-teal-300 font-semibold">Reporting Champ:</span> ${safe(rep.name)} fewest missing check-ins (${stat(rep.missingCount, v=>v)}).</li>`);

  const stk = A.pick(payload.streakBossList.filter(p=>p.longestStreak1k>0));
  if (stk) items.push(`<li><span class="text-stone-300 font-semibold">Streak Boss:</span> ${safe(stk.name)} longest ≥1k streak (${stat(stk.longestStreak1k, v=>v)}).</li>`);

  // New awards: First to Report per day
  if (Array.isArray(payload.firstToReportPerDay)) {
    payload.firstToReportPerDay.forEach(fr => {
      if (!fr) return;
      // allocate fairly
      const picked = A.pick([{ name: fr.name }]);
      if (picked) {
        const day = DAY_ORDER[fr.dayIdx] || `Day ${fr.dayIdx}`;
        items.push(`<li><span class="accent font-semibold">First to Report:</span> ${safe(fr.name)} reported ${stat(fr.value)} first on ${day}.</li>`);
      }
    });
  }

  // Day level-ups: x2+ then x1
  if (Array.isArray(payload.dayLevelUps2List) && payload.dayLevelUps2List.length) {
    const d2 = A.pick(payload.dayLevelUps2List.map(e=>({ name: e.name, gained: e.gained, dayIdx: e.dayIdx })));
    if (d2) {
      items.push(`<li><span class="text-amber-300 font-semibold">Day Level-Up x2+:</span> ${safe(d2.name)} gained ${d2.gained} level(s) on ${DAY_ORDER[d2.dayIdx]}.</li>`);
    }
  }
  if (Array.isArray(payload.dayLevelUps1List) && payload.dayLevelUps1List.length) {
    const d1 = A.pick(payload.dayLevelUps1List.map(e=>({ name: e.name, gained: e.gained, dayIdx: e.dayIdx })));
    if (d1) {
      items.push(`<li><span class="text-amber-200 font-semibold">Day Level-Up x1:</span> ${safe(d1.name)} gained ${d1.gained} level(s) on ${DAY_ORDER[d1.dayIdx]}.</li>`);
    }
  }

  // Week level-up
  if (Array.isArray(payload.weekLevelUpsList) && payload.weekLevelUpsList.length) {
    const w = A.pick(payload.weekLevelUpsList.map(e=>({ name: e.name, gained: e.gained })));
    if (w) {
      const tier = w.gained >= 3 ? 'x3' : (w.gained >= 2 ? 'x2' : 'x1');
      items.push(`<li><span class="text-sky-300 font-semibold">Week Level-Up ${tier}:</span> ${safe(w.name)} gained ${w.gained} level(s) this week.</li>`);
    }
  }

  // Lifetime step clubs
  if (Array.isArray(payload.lifetimeStepClubs) && payload.lifetimeStepClubs.length) {
    payload.lifetimeStepClubs.forEach(club => {
      const picked = A.pick([{ name: club.name }]);
      if (picked) {
        items.push(`<li><span class="text-emerald-200 font-semibold">Lifetime ${fmt(club.mark)} Club:</span> ${safe(club.name)} crossed ${fmt(club.mark)} total steps.</li>`);
      }
    });
  }

  // Lifetime level milestones
  if (Array.isArray(payload.lifetimeLevelMilestones) && payload.lifetimeLevelMilestones.length) {
    payload.lifetimeLevelMilestones.forEach(lm => {
      const picked = A.pick([{ name: lm.name }]);
      if (picked) {
        items.push(`<li><span class="text-rose-200 font-semibold">Lifetime Level ${lm.level}:</span> ${safe(lm.name)} reached Level ${lm.level}.</li>`);
      }
    });
  }

  // First threshold awards (custom labels)
  if (Array.isArray(payload.firstThresholds) && payload.firstThresholds.length) {
    payload.firstThresholds.forEach(t => {
      const picked = A.pick([{ name: t.name }]);
      if (picked) {
        const lbl = label(t.code, `First ${t.value} Day`);
        const day = DAY_ORDER[t.dayIdx] || `Day ${t.dayIdx}`;
        items.push(`<li><span class="text-yellow-200 font-semibold">${safe(lbl)}:</span> ${safe(t.name)} first reached ${fmt(t.value)} on ${day}.</li>`);
      }
    });
  }

  el.innerHTML = items.length ? items.join('') : '<li>No awards yet. Add some steps.</li>';
}

export function renderCharts(people, charts) {
  const labels = DAY_ORDER;
  const gen = ++renderGen;
  const perDayCanvas = document.getElementById('perDayChart');
  const stackedCanvas = document.getElementById('stackedTotalChart');
  const linesCanvas = document.getElementById('linesChart');
  const perDayCtx = perDayCanvas.getContext('2d');
  const stackedCtx = stackedCanvas.getContext('2d');
  const linesCtx = linesCanvas.getContext('2d');

  // Helper: attach fullscreen button to the chart's card and ensure chart resizes on fullscreen changes.
  function attachFullscreenButton(canvasEl, chartInstance) {
    if (!canvasEl) return;
    const card = canvasEl.closest('.card') || canvasEl.parentElement;
    if (!card) return;

    // ensure card positioned for absolute placement
    card.classList.add('relative');

    // avoid adding duplicate buttons
    if (card.querySelector('.chart-fullscreen-btn')) return;

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.title = 'Toggle fullscreen';
    btn.setAttribute('aria-label', 'Toggle fullscreen');
    btn.innerHTML = '⤢';
    btn.className = 'chart-fullscreen-btn absolute top-2 right-2 text-xs px-2 py-1 bg-white/5 rounded hover:bg-white/10';

    btn.onclick = async (ev) => {
      ev.stopPropagation();
      try {
        if (document.fullscreenElement) {
          await document.exitFullscreen();
        } else {
          await (card.requestFullscreen ? card.requestFullscreen() : card.webkitRequestFullscreen && card.webkitRequestFullscreen());
        }
        // let layout settle and then force chart resize
        requestAnimationFrame(() => {
          try { chartInstance.resize(); } catch (e) { /* ignore */ }
        });
      } catch (e) {
        console.error('Fullscreen toggle failed', e);
      }
    };

    card.appendChild(btn);
  }

  // Ensure all charts are resized when fullscreen changes (enter or exit)
  function onGlobalFullscreenChange() {
    charts.forEach(c => { try { c.resize(); } catch (e) {} });
  }
  // add listener once
  if (!document._chartsFullscreenHandlerAdded) {
    document.addEventListener('fullscreenchange', onGlobalFullscreenChange);
    document._chartsFullscreenHandlerAdded = true;
  }

  // Build deterministic colors, merge visibility state, and prepare datasets
  // Ensure visibility map contains all names (preserve prior values)
  people.forEach((p) => {
    if (!visibleDatasets.has(p.name)) visibleDatasets.set(p.name, true);
  });

  // helper: small rgba for bar backgrounds
  const toRgba = (hex, alpha = 0.12) => {
    // simple hex -> rgba converter for #rrggbb
    try {
      const h = hex.replace('#','');
      const r = parseInt(h.substring(0,2),16);
      const g = parseInt(h.substring(2,4),16);
      const b = parseInt(h.substring(4,6),16);
      return `rgba(${r},${g},${b},${alpha})`;
    } catch(e) { return hex; }
  };

  const datasets = people.map((p, i) => {
    const name = p.name;
    const color = getColorForName(name, i);
    return {
      label: name,
      data: p.days.map(v => Number.isFinite(v) ? v : 0),
      borderWidth: 1,
      borderColor: color,
      backgroundColor: toRgba(color, 0.12),
      // bar stacking will still work; visibility controlled by dataset.hidden
      hidden: visibleDatasets.has(name) ? !visibleDatasets.get(name) : false
    };
  });

  // create per-day stacked bar chart
  const perDayChart = new Chart(perDayCtx, {
    type: 'bar',
    data: { labels, datasets },
    options: { responsive: true, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
  });
  charts.push(perDayChart);
  attachFullscreenButton(perDayCanvas, perDayChart);

  // family totals chart – build once with bars, then overlay line when data arrives
  const familyTotals = labels.map((_, idx) =>
    people.reduce((sum, p) => sum + (Number.isFinite(p.days[idx]) ? p.days[idx] : 0), 0)
  );

  // destroy any prior instance deterministically
  if (stackedChartRef) { try { stackedChartRef.destroy(); } catch (e){} }
  const familyColor = getColorForName('Family Total', 0);

  stackedChartRef = new Chart(stackedCtx, {
    data: {
      labels,
      datasets: [{
        type: 'bar',
        label: 'Family Total',
        data: familyTotals,
        borderWidth: 1,
        borderColor: familyColor,
        backgroundColor: toRgba(familyColor, 0.12),
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: true,
      scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } },
      plugins: {
        legend: { display: true },
        tooltip: { callbacks: { label: ctx => {
          const v = (ctx.parsed && ctx.parsed.y != null) ? ctx.parsed.y : ctx.raw;
          return `${ctx.dataset.label}: ${Math.round(v).toLocaleString()}`;
        } } }
      },
      spanGaps: true,
      animation: { duration: 600 }
    }
  });
  charts.push(stackedChartRef);
  attachFullscreenButton(stackedCanvas, stackedChartRef);

  // fetch and overlay averages; ignore stale responses
  fetchFamilyWeekdayAverages()
    .then(({ averages }) => {
      if (gen !== renderGen || !Array.isArray(averages) || averages.length !== labels.length || !stackedChartRef) return;

      const lineColor = getColorForName('Historical Avg', 1);
      const lineDs = {
        type: 'line',
        label: 'Historical Avg',
        data: averages,
        borderWidth: 2,
        borderDash: [6,3],
        pointRadius: 0,
        fill: false,
        borderColor: lineColor,
      };

      const i = stackedChartRef.data.datasets.findIndex(d => d.label === 'Historical Avg');
      if (i >= 0) stackedChartRef.data.datasets[i] = lineDs;
      else stackedChartRef.data.datasets.push(lineDs);

      stackedChartRef.update();
    })
    .catch(() => { /* no-op */ });

  // line trajectories - use deterministic colors, visibility map, spanGaps, devicePixelRatio and animation guard
  const lineDatasets = people.map((p, i) => {
    const name = p.name;
    const color = getColorForName(name, i);
    return {
      label: name,
      data: p.days.map(v => Number.isFinite(v) ? v : null),
      spanGaps: true,
      borderWidth: 2,
      borderColor: color,
      backgroundColor: toRgba(color, 0.08),
      hidden: visibleDatasets.has(name) ? !visibleDatasets.get(name) : false,
      tension: 0.15,
    };
  });

  const animationsDisabled = lineDatasets.length > 18;
  const linesChart = new Chart(linesCtx, {
    type: 'line',
    data: { labels, datasets: lineDatasets },
    options: {
      responsive: true,
      devicePixelRatio: Math.min(window.devicePixelRatio || 1, 2),
      animation: animationsDisabled ? false : { duration: 600 },
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true } }
    }
  });
  charts.push(linesChart);
  attachFullscreenButton(linesCanvas, linesChart);

  // ensure crisp rendering on high-DPI and force a resize once mounted
  try { linesChart.resize(); } catch(e) {}
  
  // Build or inject wrapper + legend UI adjacent to the lines canvas
  (function buildTrajLegend() {
    if (!linesCanvas) return;

    // Find or create wrapper
    let wrapper = document.getElementById('trajWrapper');
    if (!wrapper) {
      wrapper = document.createElement('div');
      wrapper.id = 'trajWrapper';
      wrapper.className = 'flex flex-col md:flex-row gap-4';
      linesCanvas.parentElement.insertBefore(wrapper, linesCanvas);
      wrapper.appendChild(linesCanvas);
    } else {
      // ensure canvas is a direct child (avoid duplicates)
      if (linesCanvas.parentElement !== wrapper) wrapper.appendChild(linesCanvas);
    }

    // legend container
    let legendContainer = document.getElementById('linesLegendContainer');
    if (!legendContainer) {
      legendContainer = document.createElement('div');
      legendContainer.id = 'linesLegendContainer';
      legendContainer.className = 'w-full md:w-56';
      wrapper.appendChild(legendContainer);
    }

    // toggle-all button area
    let toggleWrap = legendContainer.querySelector('.lines-toggle-wrap');
    if (!toggleWrap) {
      toggleWrap = document.createElement('div');
      toggleWrap.className = 'px-2 py-1 lines-toggle-wrap';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.id = 'linesToggleAll';
      btn.className = 'w-full md:w-auto rounded-lg px-3 py-2 text-sm bg-white/10 focus:outline-none focus:ring-2';
      btn.setAttribute('aria-pressed', 'false');
      btn.textContent = 'Hide All';
      toggleWrap.appendChild(btn);

      // sr-only status
      const status = document.createElement('span');
      status.id = 'trajStatus';
      status.className = 'sr-only';
      legendContainer.appendChild(status);

      legendContainer.appendChild(toggleWrap);
    }

    // legend strip
    let legendEl = document.getElementById('linesLegend');
    if (!legendEl) {
      legendEl = document.createElement('div');
      legendEl.id = 'linesLegend';
      legendEl.className = 'flex md:flex-col overflow-x-auto gap-2 snap-x snap-mandatory p-2 rounded-lg bg-[#071133] text-white';
      legendEl.style.touchAction = 'pan-x';
      legendContainer.appendChild(legendEl);
    } else {
      // preserve container but clear children for rebuild
      legendEl.replaceChildren();
    }

    // helper to update Toggle All label and status
    const toggleBtn = document.getElementById('linesToggleAll');
    const statusEl = document.getElementById('trajStatus');
    function updateToggleAllLabel() {
      const anyVisible = Array.from(visibleDatasets.values()).some(Boolean);
      toggleBtn.textContent = anyVisible ? 'Hide All' : 'Show All';
      toggleBtn.setAttribute('aria-pressed', anyVisible ? 'true' : 'false');
      if (statusEl) statusEl.textContent = anyVisible ? 'Some lines are visible' : 'All lines hidden';
    }

    // create legend buttons for each dataset (use chart data to stay in sync)
    function createLegendButtons() {
      const datasetsRef = linesChart.data && linesChart.data.datasets ? linesChart.data.datasets : [];
      datasetsRef.forEach((ds, idx) => {
        const name = ds.label || `Series ${idx+1}`;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cursor-pointer rounded-lg px-2 py-1 h-11 flex items-center gap-2 shrink-0 snap-center focus:outline-none focus:ring-2';
        btn.setAttribute('data-name', name);
        btn.setAttribute('title', `Toggle ${name}`);
        btn.setAttribute('aria-label', `Toggle ${name} in Trajectories`);
        const pressed = !(ds.hidden === true);
        btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        if (!pressed) btn.classList.add('opacity-50');

        const dot = document.createElement('span');
        dot.className = 'w-3 h-3 rounded-full';
        dot.style.background = ds.borderColor || getColorForName(name, idx);
        btn.appendChild(dot);

        const label = document.createElement('span');
        label.className = 'truncate';
        label.textContent = name;
        btn.appendChild(label);

        // Interaction state for touch vs scroll and long-press
        let touchStartX = 0;
        let touchMoveX = 0;
        let pressTimer = null;
        const LONG_PRESS_MS = 500;

        function clearPressTimer() {
          if (pressTimer) { clearTimeout(pressTimer); pressTimer = null; }
        }

        function setPressedState(isPressed) {
          btn.setAttribute('aria-pressed', isPressed ? 'true' : 'false');
          if (isPressed) btn.classList.remove('opacity-50'); else btn.classList.add('opacity-50');
        }

        // toggle single
        function toggleSingle() {
          const name = btn.getAttribute('data-name');
          const current = !!visibleDatasets.get(name);
          visibleDatasets.set(name, !current);
          persistVisibility();

          // find dataset index in chart by label
          const di = linesChart.data.datasets.findIndex(d => d.label === name);
          if (di >= 0) {
            linesChart.data.datasets[di].hidden = !!(visibleDatasets.get(name) === false);
          }
          // update button state
          setPressedState(!current);
          updateToggleAllLabel();
          try { linesChart.update(animationsDisabled ? undefined : { duration: 600 }); } catch(e){ try{ linesChart.update(); }catch(e){} }
        }

        // solo: hide all others, show this one
        function solo() {
          const name = btn.getAttribute('data-name');
          linesChart.data.datasets.forEach(d => {
            const n = d.label;
            const show = (n === name);
            visibleDatasets.set(n, !!show);
            d.hidden = !show;
          });
          persistVisibility();
          // refresh buttons states after change
          Array.from(legendEl.children).forEach(c => {
            const n = c.getAttribute('data-name');
            setButtonState(c, !!visibleDatasets.get(n));
          });
          updateToggleAllLabel();
          try { linesChart.update(animationsDisabled ? undefined : { duration: 600 }); } catch(e){ try{ linesChart.update(); }catch(e){} }
        }

        function setButtonState(buttonEl, isPressed) {
          buttonEl.setAttribute('aria-pressed', isPressed ? 'true' : 'false');
          if (isPressed) buttonEl.classList.remove('opacity-50'); else buttonEl.classList.add('opacity-50');
        }

        // Mouse handlers (desktop)
        btn.addEventListener('mousedown', (ev) => {
          ev.preventDefault();
          pressTimer = setTimeout(() => {
            // long press via mouse -> solo
            solo();
          }, LONG_PRESS_MS);
        });
        btn.addEventListener('mouseup', (ev) => {
          clearPressTimer();
        });
        btn.addEventListener('mouseleave', (ev) => {
          clearPressTimer();
        });

        // Click handler (short tap/click)
        btn.addEventListener('click', (ev) => {
          ev.preventDefault();
          // if we detected movement, ignore
          if (Math.abs(touchMoveX) > 8) { touchMoveX = 0; return; }
          toggleSingle();
        });

        // Touch handlers for mobile taps vs scroll and long-press
        btn.addEventListener('touchstart', (ev) => {
          const t = ev.touches && ev.touches[0];
          touchStartX = t ? t.clientX : 0;
          touchMoveX = 0;
          pressTimer = setTimeout(() => { solo(); }, LONG_PRESS_MS);
        }, { passive: true });
        btn.addEventListener('touchmove', (ev) => {
          const t = ev.touches && ev.touches[0];
          const cx = t ? t.clientX : 0;
          touchMoveX = cx - touchStartX;
          if (Math.abs(touchMoveX) > 8) clearPressTimer();
        }, { passive: true });
        btn.addEventListener('touchend', (ev) => {
          clearPressTimer();
          // if significant move then it was a scroll, ignore
          if (Math.abs(touchMoveX) > 8) { touchMoveX = 0; return; }
          // otherwise treat as tap (click already handled on click event)
        });

        legendEl.appendChild(btn);
      });
    }

    // helper to update button states given visibleDatasets
    function refreshLegendButtons() {
      Array.from(legendEl.children).forEach(btn => {
        const name = btn.getAttribute('data-name');
        const pressed = !!visibleDatasets.get(name);
        btn.setAttribute('aria-pressed', pressed ? 'true' : 'false');
        if (pressed) btn.classList.remove('opacity-50'); else btn.classList.add('opacity-50');
      });
    }

    // Toggle All implementation
    toggleBtn.onclick = (ev) => {
      ev.preventDefault();
      const anyVisible = Array.from(visibleDatasets.values()).some(Boolean);
      const setTo = !anyVisible ? true : false;
      linesChart.data.datasets.forEach(d => {
        visibleDatasets.set(d.label, setTo);
        d.hidden = !setTo;
      });
      persistVisibility();
      refreshLegendButtons();
      updateToggleAllLabel();
      try { linesChart.update(animationsDisabled ? undefined : { duration: 600 }); } catch(e){ try{ linesChart.update(); }catch(e){} }
    };

    createLegendButtons();
    updateToggleAllLabel();
  })();
}

export function renderCards(people) {
  const container = document.getElementById('cards');
  container.innerHTML = people.map(p => {
    const badges = [];
    p.days.forEach((v, idx) => {
      if (!Number.isFinite(v)) return;
      const day = DAY_ORDER[idx];
      if (v >= THIRTY_K_THRESHOLD) badges.push(`<span class="chip bg-emerald-500/15 text-emerald-300" title="${day}">30k</span>`);
      if (v >= CHERYL_THRESHOLD)   badges.push(`<span class="chip bg-yellow-500/15 text-yellow-300" title="${day}">Cheryl</span>`);
      if (v >= DAILY_GOAL_15K)     badges.push(`<span class="chip bg-lime-500/15 text-lime-300" title="${day}">15k</span>`);
      if (v >= DAILY_GOAL_10K)     badges.push(`<span class="chip bg-green-500/15 text-green-300" title="${day}">10k</span>`);
      if (v >= DAILY_GOAL_2_5K)    badges.push(`<span class="chip bg-cyan-500/15 text-cyan-300" title="${day}">2.5k</span>`);
      if (v >= DAILY_GOAL_1K)      badges.push(`<span class="chip bg-blue-500/15 text-blue-300" title="${day}">1k</span>`);
    });
    const tag = p.tag ? `<span class="ml-2 text-xs text-white/60">(${safe(p.tag)})</span>` : '';
    const levelRow = (Number.isFinite(p.lifetimeTotal) && p.lifetimeTotal > 0)
      ? (() => {
          // compute progress percent for current level using same formula as levels.js
          const curr = Math.round(LEVEL_K * Math.pow(p.level, LEVEL_P));
          const next = Math.round(LEVEL_K * Math.pow(p.level + 1, LEVEL_P));
          const size = Math.max(1, next - curr);
          const stepsLeft = Math.max(0, Number(p.toNext) || 0);
          const progress = Math.max(0, Math.min(100, Math.round((size - stepsLeft) / size * 100)));

          // derive fill color from first badge text color (e.g. "text-emerald-300" -> "bg-emerald-300")
          const badgeTextMatch = badges.join(' ').match(/text-([a-z0-9-]+)/);
          const fillClass = badgeTextMatch ? 'bg-' + badgeTextMatch[1] : 'bg-sky-400';

          return `<div class="pt-2 border-t border-white/10 text-xs">
            <div class="flex items-center justify-between">
              <div><span class="text-white/60">${safe(LEVEL_LABEL)}:</span> <span class="font-semibold ml-1">${p.level}</span></div>
              <div class="text-white/60">${fmt(stepsLeft)} / ${fmt(size)}</div>
            </div>
            <div class="mt-1 h-[2px] w-full bg-white/5 rounded overflow-hidden">
              <div class="${fillClass} h-[2px]" style="width: ${progress}%;"></div>
            </div>
          </div>`;
        })()
      : '';

    return `
    <article class="card p-4 space-y-2">
      <div class="flex items-center justify-between">
        <h4 class="text-lg font-bold">${p.id ? `<a href=\"./user.php?id=${encodeURIComponent(p.id)}\" class=\"hover:underline\">${safe(p.name)}</a>` : safe(p.name)} ${tag}</h4>
        <div class="flex flex-wrap gap-1">${badges.join('')}</div>
      </div>
      <div class="grid grid-cols-3 gap-2 text-center">
        <div class="bg-white/5 rounded-lg p-2">
          <div class="text-xs text-white/60">Total</div>
          <div class="text-xl font-extrabold stat">${fmt(p.total)}</div>
        </div>
        <div class="bg-white/5 rounded-lg p-2">
          <div class="text-xs text-white/60">Average</div>
          <div class="text-xl font-extrabold stat">${fmt(p.avg)}</div>
        </div>
        <div class="bg-white/5 rounded-lg p-2">
          <div class="text-xs text-white/60">Best Day</div>
          <div class="text-xl font-extrabold stat">${fmt(p.best)}</div>
        </div>
      </div>
      <div class="text-xs text-white/70">
        ${DAY_ORDER.map((d, i) => {
          const v = p.days[i];
          const val = Number.isFinite(v) ? fmt(v) : '<span class="text-white/40">—</span>';
          return `<span class="mr-2">${d.slice(0,3)}: <span class="stat">${val}</span></span>`;
        }).join(' ')}
      </div>
      <div class="text-[11px] text-white/50">
        lifetime: <span class="stat">${fmt(p.lifetimeTotal)}</span>
        &nbsp; avg: <span class="stat">${fmt(p.lifetimeAvg)}</span>
        &nbsp; best: <span class="stat">${p.lifetimeBest ? fmt(p.lifetimeBest) : '<span class="text-white/40">—</span>'}</span>
      </div>
      ${levelRow}
    </article>`;
  }).join('');
}

export function renderMissing(missing) {
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
        </li>`
      );
    }
  });
  el.innerHTML = items.length ? items.join('') : '<li>Everyone has reported for all days so far.</li>';
}

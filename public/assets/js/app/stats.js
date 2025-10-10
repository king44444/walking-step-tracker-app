import { DAY_ORDER, DAILY_GOAL_10K, DAILY_GOAL_15K, DAILY_GOAL_2_5K, DAILY_GOAL_1K, CHERYL_THRESHOLD, THIRTY_K_THRESHOLD, LIFETIME_STEP_MILESTONES } from './config.js';
import { computeLevel } from './levels.js';

// Small helper used by computeStats
function presentDaysByIndex(days, idxs) {
  return idxs.map(i => days[i]).filter(v => Number.isFinite(v));
}
function dayName(idx){ return DAY_ORDER[idx] || `Day${idx}`; }

export function computeStats(data, lifetimeMap = new Map(), serverTodayIdx = undefined, rawData = {}) {
  const todayIdx = (typeof serverTodayIdx === 'number') ? serverTodayIdx : -1;
  // rawData.rows contains original DB rows (with reported_at columns) when present
  const rawRowsByName = new Map();
  (rawData.rows || []).forEach(r => {
    const n = (r.name || r.Name || '').trim();
    if (n) rawRowsByName.set(n, r);
  });

  const lifetimeStartMap = rawData.lifetimeStart || {};

  const people = data.map(row => {
    const days = DAY_ORDER.map(d => row[d] ?? null);
    const presentDays = days.filter(v => Number.isFinite(v));
    const reportedDaysCount = presentDays.length;
    const blanksBefore = DAY_ORDER.filter((d, idx) => idx < todayIdx && !Number.isFinite(row[d]));
    const missingCount = blanksBefore.length;

    // Longest streak of days >= 1k
    let longestStreak1k = 0, curStreak = 0;
    for (let i = 0; i < DAY_ORDER.length; i++) {
      const v = row[DAY_ORDER[i]];
      if (Number.isFinite(v) && v >= DAILY_GOAL_1K) { curStreak++; longestStreak1k = Math.max(longestStreak1k, curStreak); }
      else { curStreak = 0; }
    }

    // Median with 20k cap
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

    // Consistency
    const variance = presentDays.length > 1 ? presentDays.reduce((acc, v) => acc + Math.pow(v - avg, 2), 0) / presentDays.length : 0;
    const stddev = Math.round(Math.sqrt(variance));

    // Momentum
    const firstHalf = presentDaysByIndex(days, [0,1,2]);
    const secondHalf = presentDaysByIndex(days, [3,4,5]);
    const firstHalfSum = firstHalf.reduce((a,b)=>a+b,0);
    const secondHalfSum = secondHalf.reduce((a,b)=>a+b,0);
    const pctImprovement = firstHalfSum > 0 ? (secondHalfSum - firstHalfSum) / firstHalfSum
                     : (secondHalfSum > 0 ? 1 : 0);

    const life = lifetimeMap.get(row.Name) || { total: 0, weeks: 0, days: 0, avg: 0, best: 0 };
    const lifetimeTotal = Number(life.total) || 0;
    const lifetimeDays  = Number(life.days)  || 0;
    const lifetimeAvg   = Number(life.avg)   || (lifetimeDays > 0 ? Math.round(lifetimeTotal / lifetimeDays) : 0);
    const lifetimeBest  = Number(life.best)  || 0;
    const { level, toNext } = computeLevel(lifetimeTotal);

    return {
      name: row.Name,
      id: row.Id,
      tag: row.Tag,
      days, total, avg, best,
      thirtyK, cherylCount, fifteenK, tenK, two5K, oneK,
      biggestJump, stddev,
      firstHalfSum, secondHalfSum, pctImprovement,
      reportedDaysCount, missingCount, longestStreak1k, medianCapped,
      level, toNext, lifetimeTotal, lifetimeDays, lifetimeAvg, lifetimeBest,
      // placeholders for computed week-level info
      startTotal: 0,
      cumTotals: [],
      levelAt: [],
      dayLevelGains: []
    };
  });

  // Compute start totals, cumulative totals and level changes per person
  const dayLevelUps1List = []; // entries { name, dayIdx, gained }
  const dayLevelUps2List = [];
  const weekLevelUpsList = []; // { name, gained }
  const lifetimeStepClubs = []; // { name, mark }
  const lifetimeLevelMilestones = []; // { name, level }
  const firstToReportPerDay = []; // length 6, may contain nulls
  const firstThresholds = []; // e.g. FIRST_30K etc

  // Prepare firstReports from rawData if available
  if (Array.isArray(rawData.firstReports)) {
    for (let i = 0; i < rawData.firstReports.length; i++) {
      const fr = rawData.firstReports[i];
      if (fr) firstToReportPerDay[i] = { dayIdx: fr.dayIdx, name: fr.name, value: fr.value, reported_at: fr.reported_at };
      else firstToReportPerDay[i] = null;
    }
  } else {
    for (let i=0;i<6;i++) firstToReportPerDay[i] = null;
  }

  // thresholds for FIRST_* awards (config driven labels exist client-side)
  const firstThresholdDefs = [
    { code: 'FIRST_30K', value: THIRTY_K_THRESHOLD },
    { code: 'FIRST_20K', value: CHERYL_THRESHOLD },
    { code: 'FIRST_15K', value: DAILY_GOAL_15K }
  ];

  // Helper: lookup raw reported_at and value for a person/day
  function rawReportedFor(name, dayIdx) {
    const r = rawRowsByName.get(name);
    if (!r) return { value: null, reported_at: null };
    const dayKey = DAY_ORDER[dayIdx].toLowerCase();
    const repKey = ['mon_reported_at','tue_reported_at','wed_reported_at','thu_reported_at','fri_reported_at','sat_reported_at'][dayIdx];
    return { value: r[dayKey] != null ? Number(r[dayKey]) : null, reported_at: r[repKey] ? Number(r[repKey]) : null };
  }

  people.forEach(p => {
    const name = p.name;
    const startTotal = (lifetimeStartMap[name] !== undefined) ? Number(lifetimeStartMap[name]) : (p.lifetimeTotal - p.total);
    if (lifetimeStartMap[name] === undefined) {
      // best-effort fallback
      console.warn(`lifetimeStart missing for ${name}; using fallback lifetimeTotal - weekTotal`);
    }
    p.startTotal = Number(startTotal) || 0;

    // cumulative totals and level per day
    let cum = p.startTotal;
    const levelAt = [];
    const cumTotals = [];
    const startLevel = computeLevel(p.startTotal).level;
    levelAt.push(startLevel); // level before any new day
    for (let i = 0; i < DAY_ORDER.length; i++) {
      const v = Number.isFinite(p.days[i]) ? p.days[i] : 0;
      cum += v;
      cumTotals[i] = cum;
      const lv = computeLevel(cum).level;
      levelAt[i] = lv;
    }
    // rewrite levelAt to be per-day (level after that day's steps)
    p.cumTotals = cumTotals;
    p.levelAt = levelAt;

    // day-level gains: compare level after day i vs level before that day
    for (let i = 0; i < DAY_ORDER.length; i++) {
      const before = (i === 0) ? startLevel : p.levelAt[i-1];
      const after = p.levelAt[i];
      const gained = Math.max(0, after - before);
      if (gained >= 1) {
        const entry = { name, dayIdx: i, gained };
        dayLevelUps1List.push(entry);
        if (gained >= 2) dayLevelUps2List.push(entry);
      }
    }

    // week level-ups
    const endLevel = p.levelAt[DAY_ORDER.length-1] || startLevel;
    const weekGained = Math.max(0, endLevel - startLevel);
    if (weekGained > 0) weekLevelUpsList.push({ name, gained: weekGained, startLevel, endLevel });

    // lifetime step clubs: for configured milestones, check crossing in this week
    (LIFETIME_STEP_MILESTONES || []).forEach(mark => {
      const endTotal = p.startTotal + p.total;
      if (p.startTotal < mark && endTotal >= mark) {
        lifetimeStepClubs.push({ name, mark });
      }
    });

    // lifetime level milestones: common milestones
    const levelMilestones = [5,10,25,50,75,100];
    levelMilestones.forEach(lm => {
      if (startLevel < lm && endLevel >= lm) {
        lifetimeLevelMilestones.push({ name, level: lm });
      }
    });
  });

  // sort day-level lists to prefer higher gain and earlier day
  dayLevelUps2List.sort((a,b) => (b.gained - a.gained) || (a.dayIdx - b.dayIdx));
  dayLevelUps1List.sort((a,b) => (b.gained - a.gained) || (a.dayIdx - b.dayIdx));
  weekLevelUpsList.sort((a,b) => b.gained - a.gained);

  // compute first-threshold winners using raw reported_at timestamps if available
  firstThresholdDefs.forEach(def => {
    let best = null;
    rawRowsByName.forEach((r, name) => {
      // r may include day values and reported_at columns
      for (let i = 0; i < DAY_ORDER.length; i++) {
        const dayKey = DAY_ORDER[i].toLowerCase();
        const repKey = ['mon_reported_at','tue_reported_at','wed_reported_at','thu_reported_at','fri_reported_at','sat_reported_at'][i];
        const val = r[dayKey] != null ? Number(r[dayKey]) : null;
        const rep = r[repKey] ? Number(r[repKey]) : null;
        if (val !== null && rep !== null && val >= def.value) {
          if (!best || rep < best.reported_at) {
            best = { code: def.code, name, value: val, reported_at: rep, dayIdx: i };
          }
        }
      }
    });
    if (best) firstThresholds.push(best);
  });

  // dedupe winners lists and prepare returned payload fields
  // Keep fairness/allocator on render side; here just provide candidate lists.
  const payload = {
    people,
    leader: people.slice().sort((a,b)=>b.total-a.total)[0] || null,
    highestSingle: null,
    biggestJump: null,
    mostConsistent: null,
    missing: data.map(r => {
      const blanks = DAY_ORDER.filter((d, idx) =>
        idx < todayIdx && !Number.isFinite(r[d])
      );
      return { name: r.Name, blanks };
    }),
    most30k: null, most20k: null, most15k: null, most10k: null, most2_5k: null, most1k: null,
    earlyMomentum: null, closer: null,
    most30kList: [], most20kList: [], most15kList: [], most10kList: [], most2_5kList: [], most1kList: [],
    mostImprovedList: [], medianMasterList: [], reportingChampList: [], streakBossList: [],
    // new awards data
    firstToReportPerDay,
    dayLevelUps1List,
    dayLevelUps2List,
    weekLevelUpsList,
    lifetimeStepClubs,
    lifetimeLevelMilestones,
    firstThresholds
  };

  // preserve some previously computed quick stats for compatibility
  // highestSingle
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
  payload.highestSingle = highestSingle;

  const biggestJump = people.reduce((best, p) => {
    if (p.biggestJump.amount > (best?.amount || 0)) return { ...p.biggestJump, person: p.name };
    return best;
  }, null);
  payload.biggestJump = biggestJump;

  payload.mostConsistent = [...people].filter(p => p.days.filter(x => Number.isFinite(x)).length >= 2).sort((a,b) => a.stddev - b.stddev)[0] || null;

  // lists (simple reuse of existing sorts)
  function sortedBy(field, dir='desc') {
    return [...people].sort((a,b)=> dir==='desc' ? (b[field]-a[field]) : (a[field]-b[field]));
  }
  payload.most30kList = sortedBy('thirtyK');
  payload.most20kList = sortedBy('cherylCount');
  payload.most15kList = sortedBy('fifteenK');
  payload.most10kList = sortedBy('tenK');
  payload.most2_5kList = sortedBy('two5K');
  payload.most1kList = sortedBy('oneK');

  payload.most30k = payload.most30kList[0] || null;
  payload.most20k = payload.most20kList[0] || null;
  payload.most15k = payload.most15kList[0] || null;
  payload.most10k = payload.most10kList[0] || null;
  payload.most2_5k = payload.most2_5kList[0] || null;
  payload.most1k = payload.most1kList[0] || null;

  payload.mostImprovedList = [...people].sort((a,b)=> b.pctImprovement - a.pctImprovement);
  payload.medianMasterList = sortedBy('medianCapped');
  payload.reportingChampList = [...people].sort((a,b)=> (a.missingCount - b.missingCount) || (b.total - a.total));
  payload.streakBossList = sortedBy('longestStreak1k');
  payload.earlyMomentum = [...people].sort((a,b)=> b.firstHalfSum - a.firstHalfSum)[0] || null;
  payload.closer = [...people].sort((a,b)=> b.secondHalfSum - a.secondHalfSum)[0] || null;

  return payload;
}

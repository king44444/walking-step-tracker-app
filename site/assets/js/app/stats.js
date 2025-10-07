import { DAY_ORDER, THIRTY_K_THRESHOLD, CHERYL_THRESHOLD, DAILY_GOAL_15K, DAILY_GOAL_10K, DAILY_GOAL_2_5K, DAILY_GOAL_1K, AWARD_LIMIT } from './config.js';

export function computeStats(rows, lifetimeMap, todayIdx = null, meta = {}) {
  const people = rows.map(r => ({...r}));
  const missing = [];
  people.forEach(p => {
    const days = [p.monday,p.tuesday,p.wednesday,p.thursday,p.friday,p.saturday];
    p.avg = Math.round((p.total || 0) / Math.max(1, days.filter(n => n>0).length));
    p.best = Math.max(...days);
    p.thirtyK = days.filter(n => n >= THIRTY_K_THRESHOLD).length;
    p.cherylCount = days.filter(n => n >= CHERYL_THRESHOLD).length;
    p.fifteenK = days.filter(n => n >= DAILY_GOAL_15K).length;
    p.tenK = days.filter(n => n >= DAILY_GOAL_10K).length;
    p.two5K = days.filter(n => n >= DAILY_GOAL_2_5K).length;
    p.oneK = days.filter(n => n >= DAILY_GOAL_1K).length;
    if (todayIdx !== null) {
      for (let i=0;i<=todayIdx;i++) {
        if (!days[i]) { missing.push(p.name); break; }
      }
    }
  });

  // top N awards (simple placeholder logic)
  const awards = [];
  function takeTop(list, key, label) {
    const sorted = [...list].sort((a,b)=> (b[key]||0) - (a[key]||0));
    const taken = new Set();
    for (const p of sorted) {
      if ((p[key]||0) <= 0) break;
      if (taken.size >= AWARD_LIMIT) break;
      awards.push({ user: p.name, label });
      taken.add(p.name);
    }
  }
  takeTop(people, 'thirtyK', '30k Day');
  takeTop(people, 'cherylCount', 'Cheryl Award');

  return { people, awards, missing, todayIdx, meta };
}


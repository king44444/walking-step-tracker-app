import { BASE } from './config.js';

export async function fetchWeeks() {
  const res = await fetch(`${BASE}api/weeks.php`, { cache: "no-store" });
  if (!res.ok) throw new Error("weeks fetch failed");
  const json = await res.json();
  return Array.isArray(json.weeks) ? json.weeks : [];
}

export async function fetchWeekData(week) {
  const url = new URL(`${BASE}api/data.php`, location.origin);
  if (week) url.searchParams.set("week", week);
  const res = await fetch(url.toString(), { cache: "no-store" });
  if (!res.ok) throw new Error("data fetch failed");
  return await res.json();
}

export async function fetchLifetime() {
  const res = await fetch(`${BASE}api/lifetime.php`, { cache: 'no-store' });
  if (!res.ok) throw new Error('lifetime fetch failed');
  const json = await res.json();
  const rows = Array.isArray(json.lifetime) ? json.lifetime : [];
  const map = new Map();
  rows.forEach(r => {
    const name = (r.name || '').trim();
    if (!name) return;
    map.set(name, {
      total: Number(r.total_steps) || 0,
      weeks: Number(r.weeks_with_data) || 0,
      days: Number(r.total_days) || 0,
      avg: Number(r.lifetime_avg) || 0,
      best: Number(r.lifetime_best) || 0
    });
  });
  return map;
}

export async function fetchFamilyWeekdayAverages() {
  const res = await fetch(`${BASE}api/family_weekday_avg.php`, { cache: "no-store" });
  if (!res.ok) throw new Error("failed to load family weekday averages");
  return await res.json();
}


export function ingestRows(rows) {
  const arr = Array.isArray(rows) ? rows : [];
  return arr.map(r => ({
    name: (r.name || '').trim(),
    monday: num(r.monday),
    tuesday: num(r.tuesday),
    wednesday: num(r.wednesday),
    thursday: num(r.thursday),
    friday: num(r.friday),
    saturday: num(r.saturday),
    total: Number(r.total) || 0,
    best: Number(r.best) || 0,
    avg: Number(r.avg) || 0,
    thirtyK: r.thirtyK ?? 0,
    cherylCount: r.cherylCount ?? 0,
    fifteenK: r.fifteenK ?? 0,
    tenK: r.tenK ?? 0,
    two5K: r.two5K ?? 0,
    oneK: r.oneK ?? 0,
  }));
}

function num(v) {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
}


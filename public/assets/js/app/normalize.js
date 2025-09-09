import { DAY_ORDER, DISPLAY_NAME_OVERRIDES } from './config.js';
import { toNum } from './utils.js';

// Normalize incoming rows into canonical objects.
// Returns an array of rows like: { Name, Tag, Sex, Age, Monday..Saturday, "Total Steps" }
export function ingestRows(rows) {
  return (rows || []).map(r => {
    const out = {
      Name: DISPLAY_NAME_OVERRIDES[(r.name || "").trim()] || (r.name || "").trim(),
      Tag: (r.tag || "").toString().trim(),
      Sex: (r.sex || "").toString().trim(),
      Age: r.age != null ? Number(r.age) : null,
    };
    DAY_ORDER.forEach(d => out[d] = toNum(r[d.toLowerCase()]));
    out["Total Steps"] = DAY_ORDER.reduce((a,d)=> a + (Number.isFinite(out[d]) ? out[d] : 0), 0);
    return out;
  });
}

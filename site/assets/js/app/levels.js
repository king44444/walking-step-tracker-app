import { LEVEL_K, LEVEL_P } from './config.js';

function threshold(n, K = LEVEL_K, P = LEVEL_P) {
  if (n <= 0) return 0;
  return Math.round(K * Math.pow(n, P));
}

// Fast approximate inverse then correct by local search.
// Works for all totals, grows to infinity.
export function computeLevel(total, K = LEVEL_K, P = LEVEL_P) {
  const t = Math.max(0, Number(total) || 0);
  if (t <= 0) return { level: 0, toNext: threshold(1, K, P) };

  // initial guess
  let n = Math.max(1, Math.floor(Math.pow(t / K, 1 / P)));

  // adjust down or up to find correct n with threshold(n) <= t < threshold(n+1)
  while (n > 0 && threshold(n, K, P) > t) n--;
  while (threshold(n + 1, K, P) <= t) n++;

  const next = threshold(n + 1, K, P);
  return { level: n, toNext: Math.max(0, next - t) };
}

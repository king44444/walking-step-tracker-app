export function fmt(n) {
  const x = Number(n);
  return Number.isFinite(x) ? x.toLocaleString() : '';
}
export function safe(s) {
  return String(s || '').replace(/[&<>]/g, c => ({'&':'&','<':'<','>':'>'}[c]));
}
export function setStatus(msg, type = 'info') {
  const el = document.getElementById('loadStatus');
  if (!el) return;
  el.textContent = msg;
  el.className = 'badge';
  if (type === 'ok') el.classList.add('bg-green-500/10','text-green-300');
  if (type === 'warn') el.classList.add('bg-yellow-500/10','text-yellow-300');
  if (type === 'err') el.classList.add('bg-rose-500/10','text-rose-300');
}


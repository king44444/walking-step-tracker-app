import { NUDGES } from './config.js';

export function setStatus(msg, type = "info") {
  const el = document.getElementById("loadStatus");
  if (!el) return;
  el.textContent = msg;
  el.className = "badge";
  // remove any prior state classes
  el.classList.remove("bg-green-500/10","text-green-300","bg-yellow-500/10","text-yellow-300","bg-rose-500/10","text-rose-300");
  if (type === "ok") el.classList.add("bg-green-500/10","text-green-300");
  if (type === "warn") el.classList.add("bg-yellow-500/10","text-yellow-300");
  if (type === "err") el.classList.add("bg-rose-500/10","text-rose-300");
}

export function fmt(n) { return Number.isFinite(n) ? n.toLocaleString() : ""; }

export function safe(s) {
  return String(s ?? '').replace(/[&<>]/g, c => ({'&':'&','<':'<','>':'>'}[c]));
}

export function toNum(v) {
  if (v == null) return null;
  const s = String(v).trim();
  if (s === "") return null;
  const n = Number(s.replace(/[^0-9.-]/g, ""));
  return Number.isFinite(n) ? n : null;
}

export function pickNudge() {
  return NUDGES[Math.floor(Math.random() * NUDGES.length)];
}

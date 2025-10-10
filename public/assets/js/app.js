// Lightweight shim — real app moved to ES modules under site/app/
// index.html now loads "app/main.js" as a module. This file remains for legacy references.
if (typeof document !== 'undefined') {
  try {
    const s = document.createElement('script');
    s.type = 'module';
    s.src = '/assets/js/app/main.js';
    s.defer = true;
    document.head.appendChild(s);
  } catch (e) {
    /* no-op */
  }
}

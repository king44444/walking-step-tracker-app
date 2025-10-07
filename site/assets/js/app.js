// Lightweight shim for backup page: load ES module under site/assets/js/app/
if (typeof document !== 'undefined') {
  try {
    const s = document.createElement('script');
    s.type = 'module';
    s.src = './app/main.js';
    s.defer = true;
    document.head.appendChild(s);
  } catch (e) {
    /* no-op */
  }
}


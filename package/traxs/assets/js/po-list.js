/*
 * File: assets/js/pos-list.js
 * Description: Lightweight PO list decorator with observer cleanup.
 * Last Updated: 2025-11-06
 */
(function () {
  'use strict';

  function getThisScript() {
    const s = document.getElementsByTagName('script');
    for (let i = s.length - 1; i >= 0; i--) {
      const src = s[i].getAttribute('src') || '';
      if (src.indexOf('/assets/js/pos-list.js') !== -1) return s[i];
    }
    return document.currentScript || null;
  }

  function ensurePosListCSS() {
    const id = 'traxs-pos-list-css';
    if (document.getElementById(id)) return;
    const self = getThisScript();
    if (!self) return;
    const href = (self.getAttribute('src') || '')
      .replace(/\/js\/pos-list\.js(\?.*)?$/i, '/css/pos-list.css');
    if (!href) return;
    const link = document.createElement('link');
    link.id = id; link.rel = 'stylesheet'; link.href = href;
    document.head.appendChild(link);
  }

  function decoratePOList() {
    const hash = (location.hash || '#/').replace(/^#*/, '');
    if (!hash.startsWith('/pos')) return;

    const root = document.getElementById('traxs-root') || document.body;
    if (!root) return;

    // Only touch rows once per render
    const card = root.querySelector('.traxs-card');
    if (!card) return;
    const rows = Array.from(card.querySelectorAll('.traxs-row'))
      .filter(r => r.querySelector('.traxs-btn.primary'))
      .filter(r => /^PO\s/i.test((r.querySelector('.traxs-btn.primary').textContent || '').trim()));

    rows.forEach((row, i) => {
      if (!row.classList.contains('pos-row')) {
        row.classList.add('pos-row', (i % 2 ? 'odd' : 'even'));
      }
    });
  }

  let observer = null;

  function watchRoot() {
    const root = document.getElementById('traxs-root') || document.body;
    if (!root) return;
    if (observer) observer.disconnect();
    observer = new MutationObserver(() => {
      // Throttle: run once after DOM settles
      if (watchRoot._raf) cancelAnimationFrame(watchRoot._raf);
      watchRoot._raf = requestAnimationFrame(decoratePOList);
    });
    observer.observe(root, { childList: true, subtree: true });
  }

  function teardown() {
    if (observer) { observer.disconnect(); observer = null; }
    if (watchRoot._raf) { cancelAnimationFrame(watchRoot._raf); watchRoot._raf = null; }
  }

  function init() {
    ensurePosListCSS();
    decoratePOList();
    watchRoot();
  }

  // Re-run on hash change; clean up when leaving /pos
  window.addEventListener('hashchange', function () {
    const hash = (location.hash || '#/').replace(/^#*/, '');
    if (!hash.startsWith('/pos')) {
      teardown();           // stop observing off the PO screen
      return;
    }
    // back on /pos — safe to re-init
    init();
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

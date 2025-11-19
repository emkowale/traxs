/*
 * File: assets/js/ajax-spinner.js
 * Description: Global fetch() hook to show a Traxs Ajax spinner overlay.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-17 EDT
 */

const w = window;
const origFetch = w.fetch;
let active = 0;

function ensureOverlay() {
  let overlay = document.querySelector('.traxs-ajax-overlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.className = 'traxs-ajax-overlay';
    const spinner = document.createElement('div');
    spinner.className = 'traxs-ajax-spinner';
    overlay.appendChild(spinner);
    document.body.appendChild(overlay);
  }
  return overlay;
}

function setLoading(on) {
  const overlay = ensureOverlay();
  if (on) {
    overlay.classList.add('is-active');
  } else {
    overlay.classList.remove('is-active');
  }
}

if (typeof origFetch === 'function') {
  w.fetch = function (...args) {
    active += 1;
    setLoading(true);
    return origFetch.apply(this, args).finally(() => {
      active = Math.max(0, active - 1);
      if (!active) setLoading(false);
    });
  };
}

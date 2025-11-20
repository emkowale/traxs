/*
 * File: assets/js/spinner.js
 * Description: Full-screen loading overlay controller for Traxs SPA
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-17 EDT
 */

const markup = `
  <div class="traxs-ajax-overlay" data-traxs-spinner>
    <div class="traxs-ajax-spinner"></div>
  </div>
`;

export function createSpinner() {
  let overlay = document.querySelector('[data-traxs-spinner]');

  if (!overlay) {
    const wrap = document.createElement('div');
    wrap.innerHTML = markup.trim();
    overlay = wrap.firstElementChild;
    document.body.appendChild(overlay);
  }

  let count = 0;

  function show() {
    count += 1;
    overlay.classList.add('is-active');
  }

  function hide() {
    count = Math.max(0, count - 1);
    if (count === 0) {
      overlay.classList.remove('is-active');
    }
  }

  function wrapPromise(promise) {
    show();
    return promise.finally(hide);
  }

  return { show, hide, wrapPromise };
}

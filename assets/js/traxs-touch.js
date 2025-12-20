/*
 * File: assets/js/traxs-touch.js
 * Description: Mobile tap fallback for Traxs admin actions.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-17 EDT
 */

(function () {
  'use strict';

  if (!('matchMedia' in window)) return;
  if (!window.matchMedia('(pointer: coarse)').matches) return;

  let lastTouch = 0;

  document.addEventListener(
    'touchend',
    (event) => {
      const target = event.target.closest(
        'button, a, [role="button"], input[type="button"], input[type="submit"]'
      );

      if (!target || target.disabled) return;

      const now = Date.now();
      if (now - lastTouch < 350) return;
      lastTouch = now;

      event.preventDefault();
      target.click();
    },
    { passive: false }
  );
})();

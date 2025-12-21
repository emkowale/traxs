/*
 * File: assets/js/traxs-main.js
 * Description: Central orchestrator for all Traxs SPA logic.
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
 */

(function (window, document) {
  'use strict';

  window.traxs = window.traxs || {};
  window.traxs.modules = window.traxs.modules || {};

  function whenReady() {
    const mods = window.traxs.modules;
    const ready =
      mods.autosave &&
      mods.complete &&
      mods.uiReceive &&
      typeof mods.autosave.startWire === 'function' &&
      typeof mods.complete.init === 'function' &&
      typeof mods.uiReceive.loadReceive === 'function';

    if (!ready) return setTimeout(whenReady, 250);

    console.log('[Traxs] Modules detected:', Object.keys(mods));

    mods.autosave.startWire();
    mods.complete.init();
    console.log('[Traxs] UI Receive ready.');

    console.log('[Traxs] Main orchestrator initialized');
  }

  document.addEventListener('DOMContentLoaded', whenReady);
})(window, document);
console.log('[Traxs] main orchestrator loaded');
console.log('Root element found?', document.querySelector('#traxs-app'));

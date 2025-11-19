/*
 * File: assets/js/app.js
 * Description: Traxs SPA bootstrap + router (modular, single entry)
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-12 EDT
 */

import { buildHeader }   from './header.js';
import { createAPI }     from './api.js';
import { createState }   from './state.js';
import { createScreens, injectInlineStyles } from './ui.js';

(function () {
  'use strict';

  const root    = document.getElementById('traxs-root') || document.body;
  const apiRoot = (window.wpApiSettings && window.wpApiSettings.root) ||
                  (location.origin + '/wp-json/');
  const nonce   = (window.wpApiSettings && window.wpApiSettings.nonce) || null;

  // Logo URL stays centralized here
  const LOGO = 'https://thebeartraxs.com/wp-content/uploads/2025/05/The-Bear-Traxs-Logo.png';

  // Compose dependencies and wire them into ui.js
  const helpers = { buildHeader, LOGO };
  const api     = createAPI(apiRoot, nonce);
  const state   = createState();
  const ui      = createScreens(root, helpers, api, state);

  function navigate(token) {
    if (token === 'pos') {
      location.hash = '#/pos';
      return;
    }
    if (token === 'print') {
      api.printWorkOrders();
      return;
    }
    if (token === 'scan') {
      location.hash = '#/scan';
      return;
    }

    location.hash = '#/';
  }

  function route() {
    const r = (location.hash || '#/').replace(/^#*/, '');

    if (r.startsWith('/pos')) {
      ui.loadPOList((poId) => {
        if (!poId) return;
        location.hash = '#/receive?po=' + encodeURIComponent(String(poId));
      });
      return;
    }

    if (r.startsWith('/receive')) {
      const m    = /po=([^&]+)/.exec(r);
      const poId = m ? decodeURIComponent(m[1]) : '';
      if (!poId) {
        ui.renderError('Missing PO id');
        return;
      }
      ui.loadReceive(poId, ({ isPartial }) => {
        if (isPartial) {
          // Partial: back to POs list
          location.hash = '#/pos';
        } else {
          // Full receive: main menu
          location.hash = '#/';
        }
      });
      return;
    }

    // Default: Home
    ui.renderHome(navigate);
  }

  window.addEventListener('hashchange', route);
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof injectInlineStyles === 'function') {
      injectInlineStyles();
    }
    route();
  });
})();

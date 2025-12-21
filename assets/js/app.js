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
import { createSpinner } from './spinner.js';
import './traxs-spinner.js';

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
  const spinner = createSpinner();
  const api     = createAPI(apiRoot, nonce, spinner);
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
        const poLabel = ui.getPoLabel ? ui.getPoLabel(poId) : '';
        const parts = [];
        if (poLabel) {
          parts.push('po=' + encodeURIComponent(poLabel));
        }
        parts.push('po_id=' + encodeURIComponent(String(poId)));
        location.hash = '#/receive?' + parts.join('&');
      });
      return;
    }

    if (r.startsWith('/receive')) {
      const query = r.split('?')[1] || '';
      const params = new URLSearchParams(query);
      const poId = params.get('po_id') || '';
      if (!poId) {
        ui.renderError('Missing PO id');
        return;
      }
      const storedLabel = ui.getPoLabel ? ui.getPoLabel(poId) : '';
      const needsRewrite =
        storedLabel &&
        (!params.has('po') || params.get('po') === '' || params.get('po') === poId);
      if (needsRewrite) {
        const labelPart = 'po=' + encodeURIComponent(storedLabel);
        const idPart = 'po_id=' + encodeURIComponent(poId);
        location.hash = '#/receive?' + labelPart + '&' + idPart;
        return;
      }
      ui.loadReceive(poId, ({ isComplete }) => {
        if (isComplete) {
          location.hash = '#/pos';
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

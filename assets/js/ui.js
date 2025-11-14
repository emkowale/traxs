/*
 * File: assets/js/ui.js
 * Description: Orchestrator for Traxs SPA UI modules.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
 */

import { injectInlineStyles as baseInject } from './ui-style.js';
import { createHome } from './ui-home.js';
import { createPOList } from './ui-pos.js';
import { createReceive } from './ui-receive.js'; // main receive UI

function makeH() {
  return (tag, attrs = {}, kids = []) => {
    const el = document.createElement(tag);
    for (const k in attrs) {
      if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
      const v = attrs[k];
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else el.setAttribute(k, v);
    }
    kids.forEach((c) => el.appendChild(c));
    return el;
  };
}

export function createScreens(root, helpers, api, state) {
  const { buildHeader, LOGO } = helpers || {};
  const {
    getPoLabel,
    setPoLabel,
    getReceiveDraft,
    getReceiveInputs,
    clearPoState,
  } = state || {};
  const { getPOs, getPOLines, postReceive } = api || {};

  const h = makeH();
  const headerBlock = (title, subtitle) =>
    buildHeader ? buildHeader(h, LOGO, title, subtitle) : h('div', { text: title });

  const home = createHome(root, h, headerBlock);
  const po   = createPOList(root, h, headerBlock, getPOs, setPoLabel);
  const recv = createReceive(
    root,
    h,
    headerBlock,
    getPOLines,
    postReceive,
    getPoLabel,
    setPoLabel,
    getReceiveDraft,
    getReceiveInputs,
    clearPoState
  );

  return {
    renderHome: home.renderHome,
    renderLoading: po.renderLoading,
    renderError: po.renderError,
    loadPOList: po.loadPOList,
    loadReceive: recv.loadReceive,
  };
}

export function injectInlineStyles() {
  baseInject();
}

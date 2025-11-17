/*
 * File: assets/js/api.js
 * Description: REST API layer for Traxs SPA
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
 */

export function createAPI(apiRoot, nonce) {
  const headers = nonce ? { 'X-WP-Nonce': nonce } : {};

  async function getPOs() {
    const res = await fetch(apiRoot + 'traxs/v1/pos', { credentials: 'same-origin', headers });
    return res.ok ? res.json() : [];
  }

  async function getPOLines(poId) {
    const res = await fetch(apiRoot + 'traxs/v1/pos/' + encodeURIComponent(poId), { credentials: 'same-origin', headers });
    return res.ok ? res.json() : {};
  }

  async function postReceive(payload) {
    const res = await fetch(apiRoot + 'traxs/v1/receive', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', ...headers },
      body: JSON.stringify(payload),
    });
    return res.ok ? res.json() : { ok: false };
  }

  // ✅ New: Generate and open printable PDF of all completed work orders
  function printWorkOrders() {
    const url = apiRoot + 'traxs/v1/work-orders/pdf';
    window.open(url, '_blank');
  }

  return {
    getPOs,
    getPOLines,
    postReceive,
    printWorkOrders, // <- add here
  };
}

/*
 * File: assets/js/api.js
 * Description: REST API layer for Traxs SPA
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-16 09:30 EDT
 */

export function createAPI(apiRoot, nonce) {
  const headers = nonce ? { 'X-WP-Nonce': nonce } : {};

  async function getPOs() {
    const res = await fetch(apiRoot + 'traxs/v1/pos', {
      credentials: 'same-origin',
      headers,
    });
    return res.ok ? res.json() : [];
  }

  async function getPOLines(poId) {
    const res = await fetch(
      apiRoot + 'traxs/v1/pos/' + encodeURIComponent(poId),
      { credentials: 'same-origin', headers }
    );
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

  // Generate and open printable PDF of all ready work orders (based on received POs)
  async function printWorkOrders() {
    const url = apiRoot + 'traxs/v1/workorders';

    try {
      const res = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers,
      });

      if (!res.ok) {
        const text = await res.text();
        alert('Error generating work orders PDF: ' + text);
        return;
      }

      const blob = await res.blob();
      const blobUrl = URL.createObjectURL(blob);
      window.open(blobUrl, '_blank');
    } catch (err) {
      alert('Error generating work orders PDF: ' + err);
    }
  }


  return {
    getPOs,
    getPOLines,
    postReceive,
    printWorkOrders,
  };
}

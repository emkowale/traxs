/*
 * File: assets/js/api.js
 * Description: REST API layer for Traxs SPA
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-16 09:30 EDT
 */

export function createAPI(apiRoot, nonce, spinner) {
  const headers = nonce ? { 'X-WP-Nonce': nonce } : {};

  async function getPOs() {
    return spinner.wrapPromise(
      fetch(apiRoot + 'traxs/v1/pos', {
        credentials: 'same-origin',
        headers,
      }).then((res) => (res.ok ? res.json() : []))
    );
  }

  async function getPOLines(poId) {
    return spinner.wrapPromise(
      fetch(apiRoot + 'traxs/v1/pos/' + encodeURIComponent(poId), {
        credentials: 'same-origin',
        headers,
      }).then((res) => (res.ok ? res.json() : {}))
    );
  }

  async function postReceive(payload) {
    return spinner.wrapPromise(
      fetch(apiRoot + 'traxs/v1/receive', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', ...headers },
        body: JSON.stringify(payload),
      }).then((res) => (res.ok ? res.json() : { ok: false }))
    );
  }

  // Generate and open printable PDF of all ready work orders (based on received POs)
  async function printWorkOrders() {
    const url = apiRoot + 'traxs/v1/workorders';

    spinner.show();
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers,
    })
      .then(async (res) => {
        if (!res.ok) {
          const text = await res.text();
          throw new Error(text || 'Failed to generate PDF');
        }
        const blob = await res.blob();
        const blobUrl = URL.createObjectURL(blob);
        window.open(blobUrl, '_blank');
      })
      .catch((err) => {
        alert('Error generating work orders PDF: ' + err);
      })
      .finally(() => spinner.hide());
  }


  return {
    getPOs,
    getPOLines,
    postReceive,
    printWorkOrders,
  };
}

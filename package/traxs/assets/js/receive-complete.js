/*
 * File: assets/js/receive-complete.js
 * Description: Handles "Receive PO" button + toast feedback.
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
 */

(function (window, document) {
  'use strict';

  function showToast(msg, type = 'success') {
    const t = document.createElement('div');
    Object.assign(t.style, {
      position: 'fixed', bottom: '20px', right: '20px', zIndex: '9999',
      padding: '12px 20px', borderRadius: '8px', color: '#fff', fontWeight: '500',
      background: type === 'error' ? '#b73b3b' : '#2b8a3e', boxShadow: '0 2px 8px rgba(0,0,0,0.25)',
      opacity: '0', transition: 'opacity 0.3s, transform 0.3s', transform: 'translateY(10px)'
    });
    t.textContent = msg; document.body.appendChild(t);
    setTimeout(() => (t.style.opacity = '1', t.style.transform = 'translateY(0)'), 10);
    setTimeout(() => (t.style.opacity = '0', t.style.transform = 'translateY(10px)', setTimeout(() => t.remove(), 300)), 3000);
  }

  function init() {
    const btn = document.querySelector('.receive-po-btn');
    if (!btn) return;
    const poIdMatch = location.href.match(/po=(\d+)/);
    if (!poIdMatch) return;
    const poId = poIdMatch[1];

    btn.addEventListener('click', async () => {
      if (btn.disabled) return;
      btn.disabled = true; btn.textContent = 'Receiving...';
      try {
        const res = await fetch('/wp-json/traxs/v1/receive-complete', {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ po_id: poId })
        });
        const data = await res.json();
        if (data.success) {
          showToast('PO received successfully!');
          setTimeout(() => (location.hash = '#/receive'), 1000);
        } else throw new Error(data.message);
      } catch (e) {
        showToast('Error completing PO', 'error');
        btn.disabled = false; btn.textContent = 'Receive PO';
      }
    });
  }

  window.traxs = window.traxs || {};
  window.traxs.modules = window.traxs.modules || {};
  window.traxs.modules.complete = { init };
})(window, document);

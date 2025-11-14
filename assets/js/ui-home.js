/*
 * File: assets/js/ui-home.js
 * Description: Home screen renderer for Traxs SPA.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-13 EDT
 */

export function createHome(root, h, headerBlock) {
  function renderHome(onNav) {
    root.innerHTML = '';

    const card = h('div', { class: 'traxs-card' });
    card.appendChild(headerBlock('Traxs'));

    const row = h('div', { class: 'traxs-row' });

    const btnReceive = h('button', {
      class: 'traxs-btn primary',
      text: 'Receive Goods',
    });

    const btnPrint = h('button', {
      class: 'traxs-btn primary',
      text: 'Print Work Orders',
    });

    const btnScan = h('button', {
      class: 'traxs-btn primary',
      text: 'Scan Work Order',
    });

    btnReceive.onclick = () => onNav('pos');
    btnPrint.onclick = () => onNav('print');
    btnScan.onclick = () => onNav('scan');

    row.append(btnReceive, btnPrint, btnScan);
    card.appendChild(row);
    root.appendChild(card);
  }

  return { renderHome };
}

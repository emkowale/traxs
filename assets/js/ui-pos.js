/*
 * File: assets/js/ui-pos.js
 * Description: PO list renderer for Traxs SPA.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-13 EDT
 */

export function createPOList(root, h, headerBlock, getPOs, setPoLabel) {

  // Loader
  function renderLoading(msg) {
    root.innerHTML = '';
    const card = h('div', { class: 'traxs-card' });
    card.appendChild(
      h('p', { class: 'traxs-row-text', text: msg || 'Loading…' })
    );
    root.appendChild(card);
  }

  // Error
  function renderError(msg) {
    root.innerHTML = '';
    const card = h('div', { class: 'traxs-card' });
    card.appendChild(headerBlock('Error'));
    card.appendChild(
      h('p', {
        class: 'traxs-row-text',
        text: String(msg || 'Unknown error'),
      })
    );
    root.appendChild(card);
  }

  // Render PO List
  function renderPOList(rows, onOpenPO) {
    root.innerHTML = '';
    const card = h('div', { class: 'traxs-card' });
    card.appendChild(headerBlock('POs'));

    if (!Array.isArray(rows) || rows.length === 0) {
      card.appendChild(
        h('p', {
          class: 'traxs-row-text',
          text: 'There are no POs on order.',
        })
      );
      root.appendChild(card);
      return;
    }

    const wrap = h('div', { class: 'po-list' });

    rows.forEach((p, idx) => {
      const poNumber =
        p.po_number || p.po || p.bt_number || p.number || '—';

      const label = poNumber;

      const btn = h('button', {
        class: 'traxs-btn primary button button-primary',
        text: label,
      });

      const row = h(
        'div',
        {
          class:
            'traxs-row po-row ' + (idx % 2 ? 'po-row-odd' : 'po-row-even'),
        },
        [btn]
      );

      const poId =
        p.po_post_id || p.po_id || p.id || p.post_id || '';

      if (poId && poNumber) {
        setPoLabel(String(poId), poNumber);
      }

      btn.onclick = () => {
        if (!poId) return;
        onOpenPO(String(poId));
      };

      wrap.appendChild(row);
    });

    card.appendChild(wrap);
    root.appendChild(card);
  }

  // Loader → API → Renderer
  function loadPOList(onOpenPO) {
    renderLoading('Loading…');
    getPOs()
      .then((rows) => (Array.isArray(rows) ? rows : []))
      .then((rows) => renderPOList(rows, onOpenPO))
      .catch(renderError);
  }

  return {
    loadPOList,
    renderLoading,
    renderError,
  };
}

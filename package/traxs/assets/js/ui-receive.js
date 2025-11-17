/*
 * File: assets/js/ui-receive.js
 * Description: Receive Goods screen for Traxs SPA.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
 */

export function createReceive(
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
) {
  function renderReceiveScreen(poId, payload, onAfterReceive) {
    const stored   = getPoLabel(poId);
    const poLabel  = (payload && payload.po_number) || stored || String(poId);
    if (poLabel && !stored) setPoLabel(poId, poLabel);

    root.innerHTML = '';
    const card = h('div', { class: 'traxs-card' });
    card.appendChild(headerBlock('Goods to Receive for PO#', poLabel));

    const lines = (payload && Array.isArray(payload.lines)) ? payload.lines : [];
    if (!lines.length) {
      card.appendChild(
        h('p', { class: 'traxs-row-text', text: 'No lines found.' })
      );
      root.appendChild(card);
      return;
    }

    const draft  = getReceiveDraft(poId)  || {};
    const inputs = getReceiveInputs(poId) || {};
    const list   = h('div', { class: 'po-recv-list' });
    const usable = [];

    lines.forEach((ln) => {
      const rid = String(ln.po_line_id || '');
      if (!rid) return;

      const ordered = Number(ln.ordered_qty || 0);
      usable.push({ rid, ordered });

      const row  = h('div', { class: 'recv-row' });
      const meta = h('div', { class: 'recv-meta' });

      meta.appendChild(h('div', { class: 'recv-item', text: ln.item || '' }));
      meta.appendChild(h('div', {
        class: 'recv-attr',
        text: [ln.color, ln.size].filter(Boolean).join(' • ') || '—',
      }));
      meta.appendChild(h('div', { class: 'recv-qty', text: 'Ordered: ' + ordered }));

      const inputWrap = h('div', { class: 'recv-input' });
      const initial = Object.prototype.hasOwnProperty.call(draft, rid)
        ? String(draft[rid] || '')
        : '';

      const inp = h('input', {
        type: 'number',
        min: '0',
        step: '1',
        value: initial,
        'data-po-line': rid,
      });

      const save = () => {
        let v = parseInt(inp.value, 10);
        if (Number.isNaN(v) || v < 0) v = 0;
        inp.value = v ? String(v) : '';
        draft[rid] = v;
      };

      inp.addEventListener('input', save);
      inp.addEventListener('blur', save);

      inputs[rid] = inp;
      inputWrap.appendChild(inp);
      row.appendChild(meta);
      row.appendChild(inputWrap);
      list.appendChild(row);
    });

    const btn = h('button', { class: 'traxs-btn primary', text: 'Receive PO' });

    btn.onclick = () => {
      const out = [];
      let partial = false;

      usable.forEach(({ rid, ordered }) => {
        const el = inputs[rid];
        if (!el) return;
        let qty = parseInt(el.value, 10);
        if (Number.isNaN(qty) || qty <= 0) return;
        out.push({ po_line_id: rid, add_qty: qty, ordered_qty: ordered });
        if (qty < ordered) partial = true;
      });

      if (!out.length) {
        alert('Enter at least one received quantity.');
        return;
      }

      btn.disabled = true;

      postReceive({ po_id: poId, po_number: poLabel, lines: out })
        .then((res) => {
          btn.disabled = false;
          if (!res || res.ok === false) {
            alert('Error saving receive: ' + (res.msg || 'Unknown'));
            return;
          }
          clearPoState(poId);
          onAfterReceive({ poId, poLabel, isPartial: partial, result: res });
        })
        .catch((err) => {
          btn.disabled = false;
          alert('Error saving receive: ' + err);
        });
    };

    card.appendChild(list);
    card.appendChild(h('div', { class: 'traxs-row' }, [btn]));
    root.appendChild(card);
  }

  function loadReceive(poId, onAfterReceive) {
    root.innerHTML = '';
    const c = h('div', { class: 'traxs-card' });
    c.appendChild(h('p', { class: 'traxs-row-text', text: 'Loading…' }));
    root.appendChild(c);

    getPOLines(poId)
      .then((p) => renderReceiveScreen(poId, p, onAfterReceive))
      .catch((err) => {
        root.innerHTML = '';
        const e = h('div', { class: 'traxs-card' });
        e.appendChild(h('p', { class: 'traxs-row-text', text: 'Error: ' + err }));
        root.appendChild(e);
      });
  }

  return { loadReceive };
}

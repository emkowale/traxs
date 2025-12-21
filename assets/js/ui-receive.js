/*
 * File: assets/js/ui-receive.js
 * Description: Receive Goods screen for Traxs SPA.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-14 EDT
 */

const printWorkorderUrl = typeof wpApiSettings !== 'undefined'
  ? wpApiSettings.printWorkorderUrl || ''
  : '';
const printWorkorderNonce = typeof wpApiSettings !== 'undefined'
  ? wpApiSettings.printWorkorderNonce || ''
  : '';

const buildWorkorderUrl = (orderId) => {
  if (!orderId || !printWorkorderUrl) {
    return '';
  }
  try {
    const url = new URL(printWorkorderUrl);
    url.searchParams.set('order_id', orderId);
    if (printWorkorderNonce) {
      url.searchParams.set('_wpnonce', printWorkorderNonce);
    }
    return url.toString();
  } catch (error) {
    let base = printWorkorderUrl;
    if (!base.includes('?')) {
      base += '?';
    } else if (!base.endsWith('?') && !base.endsWith('&')) {
      base += '&';
    }
    let suffix = 'order_id=' + encodeURIComponent(orderId);
    if (printWorkorderNonce) {
      suffix += '&_wpnonce=' + encodeURIComponent(printWorkorderNonce);
    }
    return base + suffix;
  }
};

const openWorkorderPdf = (orderId) => {
  const target = buildWorkorderUrl(orderId);
  if (!target) {
    return;
  }
  window.open(target, '_blank');
};

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
  setReceiveDraftValue,
  clearReceiveDraft,
  clearPoState
) {
  let activeReceiveState = null;

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

    activeReceiveState = null;

    const draft  = getReceiveDraft(poId)  || {};
    const inputs = getReceiveInputs(poId) || {};
    const rowRefs = {};
    const receivedCells = {};
    const persistedTotals = {};
    const persistors = {};
    let lastReceiveResult = null;
    const table  = h('table', { class: 'recv-table' });
    const header = h('thead');
    const headerRow = h('tr');
    ['Item', 'Ordered', 'Received'].forEach((label) => {
      headerRow.appendChild(h('th', { text: label }));
    });
    header.appendChild(headerRow);
    const body = h('tbody');
    const usable = [];
    table.appendChild(header);
    table.appendChild(body);

    let lineIndex = 0;
    lines.forEach((ln) => {
      const rid = String(ln.po_line_id || '');
      if (!rid) return;

      const ordered = Number(ln.ordered_qty || 0);
      usable.push({ rid, ordered });

      const themeClass = (lineIndex % 2 === 0)
        ? 'recv-line-row--dark'
        : 'recv-line-row--maroon';
      const row  = h('tr', { class: `recv-line-row ${themeClass}` });
      const itemText = [ln.item, ln.color, ln.size].filter(Boolean).join(' • ');
      const itemCell = h('td', { text: itemText || 'Line item' });

      const orderedCell = h('td', { text: String(ordered) });

      const receivedCell = h('td');
      const serverValue = Number(ln.received_qty || 0);
      persistedTotals[rid] = serverValue;

      const hasDraft = Object.prototype.hasOwnProperty.call(draft, rid);
      const storedValue = hasDraft ? Number(draft[rid] ?? 0) : serverValue;
      let initial = storedValue > 0 ? String(storedValue) : '';
      if (initial === '0') initial = '';
      draft[rid] = storedValue;
      if (typeof setReceiveDraftValue === 'function') {
        setReceiveDraftValue(poId, rid, storedValue);
      }

      const inp = h('input', {
        type: 'number',
        min: '0',
        step: '1',
        value: initial,
        max: '999',
        'data-po-line': rid,
      });
      inputs[rid] = inp;
      receivedCell.appendChild(inp);

      const sanitizeValue = (value) => {
        let normalized = parseInt(value, 10);
        if (Number.isNaN(normalized) || normalized < 0) {
          normalized = 0;
        }
        return normalized;
      };

      const commitValue = () => {
      const qty = sanitizeValue(inp.value);
      draft[rid] = qty;
      inp.value = qty ? String(qty) : '';
      if (typeof setReceiveDraftValue === 'function') {
        setReceiveDraftValue(poId, rid, qty);
      }
        return qty;
      };

      const persistLine = (qty) => {
        const target = typeof qty === 'number' ? qty : Number(draft[rid] ?? 0);
        const previous = Number(persistedTotals[rid] ?? 0);
        if (target <= previous) {
          return Promise.resolve();
        }

        const payload = {
          po_id: poId,
          po_number: poLabel,
          lines: [{
            po_line_id: rid,
            actual_qty: target,
            ordered_qty: ordered,
          }],
        };
        const savingClass = 'traxs-input-saving';
        inp.classList.add(savingClass);
        return postReceive(payload)
          .then((res) => {
            lastReceiveResult = res;
            if (!res || res.ok === false) {
              console.warn('[Traxs] Receive autosave failed', res);
              return;
            }
            persistedTotals[rid] = target;
          })
          .catch((err) => {
            console.error('[Traxs] Receive autosave failed', err);
          })
          .finally(() => {
            inp.classList.remove(savingClass);
          });
      };

      persistors[rid] = persistLine;

      inp.addEventListener('input', commitValue);
      inp.addEventListener('blur', commitValue);
      inp.addEventListener('change', () => {
        const qty = commitValue();
        persistLine(qty);
      });
      rowRefs[rid] = row;
      receivedCells[rid] = receivedCell;
      row.appendChild(itemCell);
      row.appendChild(orderedCell);
      row.appendChild(receivedCell);
      body.appendChild(row);

      const orderRow = h('tr', { class: `recv-line-orders-row ${themeClass}` });
      const orderCell = h('td', { colspan: '3' });
      const ordersWrap = h('div', { class: 'recv-line-orders' });
      const fallbackOrders = Array.isArray(ln.order_ids)
        ? ln.order_ids.map((oid) => ({
            order_id: oid,
            order_number: String(oid),
          }))
        : [];
      const orderRefs = Array.isArray(ln.orders) && ln.orders.length
        ? ln.orders
        : fallbackOrders;
      orderRefs.forEach((orderRef) => {
        const normalized = (orderRef && typeof orderRef === 'object')
          ? orderRef
          : { order_id: orderRef, order_number: String(orderRef) };
        const orderIdVal = Number(normalized.order_id ?? normalized.id ?? 0);
        if (!orderIdVal) {
          return;
        }
        const label = String(normalized.order_number ?? normalized.number ?? orderIdVal).trim();
        if (!label) {
          return;
        }
        const btn = h('button', {
          type: 'button',
          class: 'recv-line-order-btn button',
          text: label,
          'data-order-id': orderIdVal,
        });
        btn.addEventListener('click', () => openWorkorderPdf(orderIdVal));
        ordersWrap.appendChild(btn);
      });
      orderCell.appendChild(ordersWrap);
      orderRow.appendChild(orderCell);
      body.appendChild(orderRow);

      lineIndex += 1;
    });

    activeReceiveState = {
      poId,
      poLabel,
      usable,
      draft,
      inputs,
      rowRefs,
      receivedCells,
    };

    const persistAll = () => {
      const promises = usable.map(({ rid, ordered }) => {
        const fn = persistors[rid];
        const qty = Number(draft[rid] ?? 0);
        if (typeof fn === 'function') {
          return fn(qty);
        }
        return Promise.resolve();
      });
      return Promise.all(promises);
    };

    const btn = h('button', {
      class: 'traxs-btn primary button button-primary',
      text: 'Receive PO',
    });

    btn.onclick = () => {
      const hasValues = usable.some(({ rid }) => Number(draft[rid] ?? 0) > 0);
      if (!hasValues) {
        alert('Enter at least one received quantity.');
        return;
      }

      btn.disabled = true;

      persistAll()
        .then(() => {
          btn.disabled = false;
          const isComplete = usable.every(({ rid, ordered }) => {
            if (ordered <= 0) return true;
            const stored = Number(draft[rid] ?? 0);
            return stored > 0 && stored >= ordered;
          });
          updateRowsAfterReceive();
          if (isComplete) {
            if (typeof clearReceiveDraft === 'function') {
              clearReceiveDraft(poId);
            }
            clearPoState(poId);
            activeReceiveState = null;
          }
          onAfterReceive({
            poId,
            poLabel,
            isPartial: !isComplete,
            isComplete,
            result: lastReceiveResult,
          });
        })
        .catch((err) => {
          btn.disabled = false;
          alert('Error saving receive: ' + (err?.message || err || 'Unknown'));
        });
    };

    card.appendChild(table);
    card.appendChild(h('div', { class: 'traxs-row' }, [btn]));
    root.appendChild(card);
  }

  function updateRowsAfterReceive() {
    const state = activeReceiveState;
    if (!state) return;
    const { usable, draft, inputs, rowRefs, receivedCells } = state;

    usable.forEach(({ rid, ordered }) => {
      const row = rowRefs[rid];
      const cell = receivedCells[rid];
      if (!row || !cell) return;

      const stored = Number(draft[rid] ?? 0);
      const meetsThreshold = ordered <= 0
        ? stored >= ordered
        : stored > 0 && stored >= ordered;

      if (meetsThreshold) {
        cell.innerHTML = '';
        cell.textContent = String(stored);
        const inputEl = inputs[rid];
        if (inputEl) {
          inputEl.remove();
          delete inputs[rid];
        }
        row.classList.add('traxs-row-received');
      } else {
        row.classList.remove('traxs-row-received');
        const inputEl = inputs[rid];
        if (inputEl) {
          inputEl.value = stored ? String(stored) : '';
          if (!cell.contains(inputEl)) {
            cell.innerHTML = '';
            cell.appendChild(inputEl);
          }
        }
      }
    });
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

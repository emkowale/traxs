/*
 * File: assets/js/state.js
 * Description: In-memory + sessionStorage state for Traxs SPA.
 * Plugin: Traxs
 * Author: Eric Kowalewski
 * Last Updated: 2025-11-12 EDT
 */

export function createState() {
  const poNumbers     = {}; // { [poId]: 'BT-2025...' }
  const receiveDraft  = {}; // { [poId]: { [lineId]: qty } }
  const receiveInputs = {}; // { [poId]: { [lineId]: HTMLInputElement } }

  const key = (poId) => String(poId);
  const draftKey = (poId) => 'traxs_receive_draft_' + key(poId);

  const storageAvailable = (() => {
    try {
      const testKey = '__traxs_storage_test';
      if (typeof localStorage === 'undefined') {
        return null;
      }
      localStorage.setItem(testKey, testKey);
      localStorage.removeItem(testKey);
      return localStorage;
    } catch (e) {
      return null;
    }
  })();

  function persistDraft(poId) {
    if (!storageAvailable) return;
    const k = key(poId);
    const data = receiveDraft[k] || {};
    try {
      storageAvailable.setItem(draftKey(poId), JSON.stringify(data));
    } catch (e) {}
  }

  function clearDraftStorage(poId) {
    if (!storageAvailable) return;
    try {
      storageAvailable.removeItem(draftKey(poId));
    } catch (e) {}
  }

  function getPoLabel(poId) {
    const k = key(poId);
    return (
      poNumbers[k] ||
      (typeof sessionStorage !== 'undefined'
        ? sessionStorage.getItem('traxs_po_' + k)
        : null)
    );
  }

  function setPoLabel(poId, label) {
    const k   = key(poId);
    const val = String(label);
    poNumbers[k] = val;
    try {
      if (typeof sessionStorage !== 'undefined') {
        sessionStorage.setItem('traxs_po_' + k, val);
      }
    } catch (e) {}
  }

  function getReceiveDraft(poId) {
    const k = key(poId);
    if (!receiveDraft[k]) {
      receiveDraft[k] = {};
      if (storageAvailable) {
        const stored = storageAvailable.getItem(draftKey(poId));
        if (stored) {
          try {
            const parsed = JSON.parse(stored);
            if (parsed && typeof parsed === 'object') {
              receiveDraft[k] = parsed;
            }
          } catch (e) {}
        }
      }
    }
    return receiveDraft[k];
  }

  function setReceiveDraftValue(poId, lineId, qty) {
    if (!lineId) return;
    const k = key(poId);
    if (!receiveDraft[k]) receiveDraft[k] = {};
    receiveDraft[k][lineId] = qty;
    persistDraft(poId);
  }

  function clearReceiveDraft(poId) {
    const k = key(poId);
    delete receiveDraft[k];
    clearDraftStorage(poId);
  }

  function getReceiveInputs(poId) {
    const k = key(poId);
    if (!receiveInputs[k]) receiveInputs[k] = {};
    return receiveInputs[k];
  }

  function clearPoState(poId) {
    const k = key(poId);
    delete poNumbers[k];
    delete receiveDraft[k];
    delete receiveInputs[k];
    try {
      if (typeof sessionStorage !== 'undefined') {
        sessionStorage.removeItem('traxs_po_' + k);
      }
    } catch (e) {}
  }

  return {
    getPoLabel,
    setPoLabel,
    getReceiveDraft,
    getReceiveInputs,
    setReceiveDraftValue,
    clearReceiveDraft,
    clearPoState,
  };
}

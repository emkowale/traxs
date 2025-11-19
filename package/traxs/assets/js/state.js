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
    if (!receiveDraft[k]) receiveDraft[k] = {};
    return receiveDraft[k];
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
    clearPoState,
  };
}

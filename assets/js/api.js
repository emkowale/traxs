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
      fetch(apiRoot + 'traxs/v1/po-lines?po_id=' + encodeURIComponent(poId), {
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
  const CHUNK_SIZE = 8;
  const PDF_LIB_MODULE_URLS = [
    'https://unpkg.com/pdf-lib@1.28.0/dist/pdf-lib.esm.js',
    'https://cdn.jsdelivr.net/npm/pdf-lib@1.28.0/dist/pdf-lib.esm.js',
    'https://cdn.jsdelivr.net/npm/pdf-lib/dist/pdf-lib.esm.js',
  ];
  let pdfLibModulePromise = null;

  async function importPdfLibModule() {
    if (pdfLibModulePromise) {
      return pdfLibModulePromise;
    }
    pdfLibModulePromise = (async () => {
      let lastError = null;
      for (const url of PDF_LIB_MODULE_URLS) {
        try {
          // Prevent bundlers from trying to resolve the remote module at build-time.
          return await import(/* webpackIgnore: true */ url);
        } catch (err) {
          lastError = err;
          console.warn(`[Traxs] Failed to load pdf-lib from ${url}`, err);
        }
      }
      throw lastError || new Error('Unable to load pdf-lib module');
    })();
    return pdfLibModulePromise;
  }

  async function fetchChunk(index) {
    const url = new URL(apiRoot + 'traxs/v1/workorders');
    url.searchParams.set('chunk', index);
    url.searchParams.set('chunk_size', CHUNK_SIZE);
    const res = await fetch(url.toString(), {
      method: 'GET',
      credentials: 'same-origin',
      headers,
    });
    if (!res.ok) {
      const text = await res.text();
      throw new Error(text || `Failed to download chunk ${index}`);
    }
    const total = parseInt(res.headers.get('X-Traxs-Chunk-Total') || '1', 10) || 1;
    const buffer = await res.arrayBuffer();
    return { buffer, total };
  }

  async function mergePDFChunks(buffers) {
    if (buffers.length === 1) {
      return new Blob([buffers[0]], { type: 'application/pdf' });
    }
    const { PDFDocument } = await importPdfLibModule();
    const merged = await PDFDocument.create();
    for (const buffer of buffers) {
      const doc = await PDFDocument.load(buffer);
      const pages = await merged.copyPages(doc, doc.getPageIndices());
      pages.forEach((page) => merged.addPage(page));
    }
    const mergedBytes = await merged.save();
    return new Blob([mergedBytes], { type: 'application/pdf' });
  }

  async function loadAllChunks() {
    const { buffer: firstBuffer, total } = await fetchChunk(0);
    const buffers = [firstBuffer];
    for (let i = 1; i < total; i++) {
      const { buffer } = await fetchChunk(i);
      buffers.push(buffer);
    }
    return buffers;
  }

  async function printWorkOrders() {
    spinner.show();
    try {
      const buffers = await loadAllChunks();
      const blob = await mergePDFChunks(buffers);
      const blobUrl = URL.createObjectURL(blob);
      window.open(blobUrl, '_blank');
    } catch (err) {
      alert('Error generating work orders PDF: ' + err);
    } finally {
      spinner.hide();
    }
  }


  return {
    getPOs,
    getPOLines,
    postReceive,
    printWorkOrders,
  };
}

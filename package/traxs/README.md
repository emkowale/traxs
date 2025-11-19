# Traxs — Receive Goods SPA for The Bear Traxs

A lightweight WordPress plugin that provides a **mobile-first kiosk** interface to list **POs** and **receive goods**, bridging data from the **Eject** system.

**Design ground rules (non-negotiable):**
- **Files ≤ 100 lines** each (JS/PHP/CSS) unless truly unavoidable.
- **No inline CSS** in JS or PHP. All styling lives in `/assets/css/`.
- **Mobile portrait first**. Buttons stacked, centered. Kiosk use.
- **Color palette:** background `#0e243c` (navy), buttons `#ffd078` (yellow), accent `#863d3d` (brown).
- **Navigation** uses hash routes (e.g., `/#/`, `/#/pos`, `/#/receive?po=ID`).
- **Security:** any state-changing REST route requires `manage_woocommerce`.

---

## 1) What the user sees

### A. Home screen (after login → `/traxs/` → `/#/`)
- **Centered title:** “Traxs” in `#ffd078`.
- **Centered logo:** Bear Traxs mark, **120px**.
- **Three stacked buttons (full-width up to 320px):**
  1. **Receive Goods** → route to `/#/pos`
  2. **Print Work Orders** → route to `/#/print` (placeholder)
  3. **Scan Work Order** → route to `/#/scan` (placeholder)
- **Background everywhere:** `#0e243c`.
- **Buttons:** `#ffd078`, **no corner radius**, bold, centered, stacked.

### B. POs list (route `/#/pos`)
- Header area shows **centered logo**.
- Title text “POs”.
- For each **finalized** PO (i.e., has **BT PO number** `_po_number`):
  - Render one big stacked button: `PO ######`.
  - Tap → navigate to `/#/receive?po=<post_id>` (receive screen).
- Empty state: **“There are no POs on order.”**

### C. Receive screen (route `/#/receive?po=ID`)
- Loads PO **lines** from REST.
- Table (dark theme) columns: **Item / Color / Size / Ordered / Received / Action**.
- **Receive** action opens a **modal**:
  - Shows line summary + **Remaining** quantity.
  - Numeric input defaults to 1 (or 0 if none remaining).
  - **Confirm** posts to REST, updates **Received** count live.
- Persisted records stored in `wp_traxs_receipts`.

---

## 2) Data model & sources

### POs (read-only from Eject)
- Historically came from `eject_run` CPT.
- **Now:** the POs we list are **CPT `eject_po`** where meta `_po_number` exists (the BT number).
- Minimal fields needed to render list:
  - **post ID** (used as `po_id` for lookups)
  - **_po_number** (BT number, display it)
  - **_vendor_name** (optional, can be shown later)
  - **_items** JSON (for lines view) – array keyed by composite line id with `{ item, color, size, qty, order_item_ids[], order_ids[] }`.

### Receipts (write-only by Traxs)
- Custom table `wp_traxs_receipts`:
  - `id` BIGINT AI PK
  - `po_number` VARCHAR(32)
  - `po_line_id` VARCHAR(191)
  - `received_qty` INT
  - `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
- **NEVER** mutate `_items`; we **add** receipt rows and compute totals by summing.

---

## 3) REST API

> All routes under `/wp-json/traxs/v1/*`.  
> Use `X-WP-Nonce` for auth (WordPress nonce). Writes require `manage_woocommerce`.

- `GET /traxs/v1/pos`  
  Returns **array of POs** filtered to `eject_po` with `_po_number` set.  
  Shape:
  ```json
  [
    {"po_post_id":123,"po_number":"BT-40779","vendor":"SanMar","items_count":10},
    ...
  ]
  ```

- `GET /traxs/v1/po-lines?po_id=POST_ID`  
  Returns `{ "lines": [...] }`. Each line:
  ```json
  {
    "po_line_id":"dt6000|lime shock|2xl",
    "item":"DT6000",
    "color":"Lime Shock",
    "size":"2XL",
    "ordered_qty":12,
    "received_qty":7,
    "order_item_ids":[...],
    "order_ids":[...]
  }
  ```

- `POST /traxs/v1/receive`  
  Body: `{ "po_id": POST_ID, "po_line_id": "key", "qty": 3 }`  
  Writes to `wp_traxs_receipts`. Returns updated line snapshot:
  ```json
  { "po_id":123, "po_number":"BT-40779", "po_line":{...}, "insert_id":4567 }
  ```

---

## 4) File tree (canonical layout)

```
traxs/
├── traxs.php
├── assets/
│   ├── css/
│   │   ├── traxs.css
│   │   └── spa-modal.css
│   └── js/
│       └── app.js
└── includes/
    ├── class-traxs-assets.php
    ├── class-traxs-install.php
    ├── class-traxs-eject-bridge.php
    ├── class-traxs-rest-pos.php
    └── class-traxs-rest-receive.php
```

---

## 5) Build order
1. Bootstrap: traxs.php  
2. Install: dbDelta table  
3. Assets: enqueue CSS globally  
4. Bridge: query eject_po  
5. REST: pos + po-lines + receive  
6. SPA: routes /#/ /#/pos /#/receive  
7. CSS: dark layout, stacked buttons, no white  

---

## 6) Security & testing
- Nonce-gated REST.  
- Capability `manage_woocommerce` for writes.  
- Validate qty > 0.  
- Clamp to remaining on both client + server.  

---

## 7) Acceptance tests
1. Activate → table created.  
2. /traxs/ loads SPA.  
3. Home screen: 3 buttons.  
4. POs list: shows at least one PO.  
5. Receive screen works.  
6. Permissions enforced.

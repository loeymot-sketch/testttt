# Page 9 — /admin/pos-orders order list — Evidence

**Verdict** : GREEN
**State** : `09-pos-orders-list`

## Capture quartet

- PNG : `tests/e2e/__screenshots__/goal-pageby-pos-2026-05-18/09-pos-orders-list.png`
- DOM : `.dom.html`
- Console : `.console.json`
- Network : `.network.json`

## Visual analysis

Two-column shell : left vertical nav (Tableau De Bord / POS / Ingrédients / Commandes Caisse), right content card.

Page title : "Tableau De Bord / **Commandes Caisse**" (breadcrumb correct FR i18n).

Card header : "Commandes Caisse" + per-page selector (10) + Filtrer dropdown + Exporter dropdown (all orange brand).

Table headers (sortable look) : N° COMMANDE / N° FILE / TYPE DE COMMANDE / CLIENT / MONTANT / DATE / STATUT / ACTI(on).

8 visible rows : order numbers 1405261484-1405261491, file ticket badges N°A0011-A0018 (orange pill), type "À Emporter" (red link styling), client "Client Comptoir", amounts €6.90 to €15.20, dates "11:16 AM, 14-05-2026" to "11:26 AM, 14-05-2026", status badge "Accepter" (green). Action column shows eye-icon view button.

Alternating row stripes (default + light orange-tint for even rows). No overflow, no truncation, no overlap. All chips have consistent padding.

## Technical analysis

- Raw-label scan : no leak. "À Emporter" / "Client Comptoir" / "Accepter" / "Commandes Caisse" all proper French.
- Console : 1-2 info entries. No errors.
- Network : `[]` — no 4xx/5xx.
- Route resolved : `/admin/pos-orders` → `admin.pos-orders.list` → `PosOrderListComponent.vue` (verified in `resources/js/router/modules/posOrderRoutes.js:13-31`).

**Spec note** : Initial spec used `/admin/pos/orders` which is wrong — the real SPA route is `/admin/pos-orders` (hyphenated). Spec was updated to use correct route, captures are clean.

## Verdict

GREEN. No defect.

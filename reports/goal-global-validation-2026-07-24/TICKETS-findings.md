# TICKETS — Audit comportement impression REÇU CLIENT vs spec owner
READ-ONLY. Date 2026-07-24. Scope: reçu CLIENT (caisse + borne). NE PAS confondre avec ticket CUISINE (KDS auto = voulu).

## Spec owner
Le reçu client NE doit PAS s'imprimer auto. Caisse: bouton "imprimer si je veux". Borne: idem. Oubli: réimprimer depuis suivi/commandes en cours. Ticket cuisine = auto (inchangé).

---

## POINT 1 — CAISSE encaissement → ÉTAT: AUTO-PRINT (P1, ≠ spec)

Deux chemins d'encaissement caisse impriment le reçu client AUTOMATIQUEMENT.

**(a) Vente comptoir POS (PaymentComponent):**
- `PaymentComponent.vue:329` — `<ReceiptComponent ref="receiptRoot" :order="order" :clear-cart-on-close="true" />`
- `PaymentComponent.vue:942` — `showReceiptModalFromDom()` → `appService.modalShow('#receiptModal')` après paiement confirmé.
- `ReceiptComponent.vue:481` (mounted) + `:487` (watch order.id) → `maybeAutoPrintClient()`.
- `ReceiptComponent.vue:591-602` — comme `clearCartOnClose===true`, appelle `handlePrintClientClick()` → POST `print-receipt` (NF525 count++) + pont ESC/POS. **Impression auto.**
- Bouton manuel DÉJÀ présent: `ReceiptComponent.vue:35-44` (data-testid `receipt-print-client`).

**(b) Encaissement file "À encaisser" (web/borne collectés):**
- `EncaissementComponent.vue:191-207` — `onEncaisseConfirmed()` imprime le ticket client via `printEscPosViaCaisseBridge` (fire-and-forget) après confirm.
- Libellé bouton `button.confirm_and_print` (`PosCounterCollectModal.vue:212`) = "confirmer ET imprimer" = auto by-design.

**Contre-exemple sain:** encaissement depuis le TRACKER n'imprime PAS auto — `PosOrdersTrackerComponent.vue:1170-1173` (refresh seul); la modale a des boutons manuels `PosCounterCollectModal.vue:165-186` (`printTicket`).

**MANQUE pour spec:** supprimer le déclencheur auto; le bouton existe déjà.
**FIX scope-minimal (NON-frozen):**
1. `ReceiptComponent.maybeAutoPrintClient` (591-602): découpler l'auto-print de `clearCartOnClose` (garder le reset panier, retirer l'appel auto). Bouton "Ticket client" reste.
2. `EncaissementComponent.onEncaisseConfirmed` (198-206): retirer l'auto-print → s'appuyer sur boutons manuels de la modale (déjà là).
- ⚠️ `PaymentComponent.vue` = FROZEN mais le FIX ne le touche PAS (l'auto-print vit dans ReceiptComponent, non-frozen). **Pas de LOCK requis.** Option: nouveau flag config défaut off.

---

## POINT 2 — BORNE confirmation → ÉTAT: AUTO-PRINT (P1, ≠ spec)

- `KioskConfirmationComponent.vue:347-357` — sur mount /confirmation: si pont dispo + `markPrintedOnce` → `this.printReceipt(false)` = reçu client AUTO.
- Borne cash-au-comptoir: `KioskCashInstructionComponent.vue:194-211` — `autoPrintCounterTicket()` imprime auto le ticket client "à régler en caisse".
- Bouton manuel DÉJÀ présent: `KioskConfirmationComponent.vue:76` (`@click="printReceipt"`).

**MANQUE:** retirer/gater le bloc auto (347-359) + `autoPrintCounterTicket`; garder le bouton manuel.
**FIX (NON-frozen):** `KioskConfirmationComponent` + `KioskCashInstructionComponent` NE sont PAS dans les frozen §7 (frozen kiosk = Wizard/App/Upsell seulement). **Pas de LOCK.**
⚠️ Décision owner: le ticket borne "à régler en caisse" (cash) est fonctionnel (indique de payer au comptoir) — confirmer s'il doit aussi disparaître.

---

## POINT 3 — Réimpression depuis suivi → ÉTAT: PRÉSENT on-demand (OK, conforme)

- `PosOrdersTrackerComponent.vue:277-286` — bouton 🖨 (`fa-print`, `requestReprint`, testid `tracker-reprint-{id}`) sur CHAQUE carte, **sans gate de statut** (contrairement au bouton cancel gaté `col.id !== 'delivered'`).
- `requestReprint()` (`:1000-1017`) → charge l'order complet → monte `<ReceiptComponent :order="reprintOrder">` (`:430`, SANS `clearCartOnClose` → défaut false → **PAS d'auto-print**) → `modalShow('#receiptModal')` → le caissier clique "Ticket client" → POST `print-receipt` (NF525 count++ / duplicata marker).
- Aussi réimprimable depuis l'historique: `PosOrderShowComponent`/`PosOrderReceiptComponent`, et `PosCounterCollectModal.printTicket` (165-186).

**Vérif owner CONFIRMÉE: oui, on peut déjà réimprimer.** Sévérité: aucune.
Nuance mineure: le tracker ne liste que les commandes actives/du jour; les plus anciennes se réimpriment via `/admin/pos-orders` (détail).

---

## Note config
Aucun flag ne gate l'auto-print du reçu client. `config/printing.php:49` `pos_silent_only` ne gate QUE le fallback `window.print` (pas le déclencheur auto). Un flag `receipt.auto_print_client` (défaut false) serait le fix propre et centralisé.

## Verdict
- P1: reçu client auto à l'encaissement caisse (2 chemins) ET à la confirmation borne → ≠ spec. Fixes 100% non-frozen, sans LOCK.
- OK: réimpression depuis suivi déjà en place.
- Ticket CUISINE (KDS/ReceiptComponent kitchen) reste auto = conforme, NE PAS toucher.

# EPF — BORNE (Kiosk Plan B) — Audit e2e technique par fonctionnalité

HEAD `3c7145bf4` · DB `foodking_e2e` · LIVE `127.0.0.1:8766` · 2026-07-04
Token réel minté via `KioskMachine#1` (branch=1, status=ACTIVE) → `User#1.createToken('kiosk-token',['kiosk:order'])`.
Produit test = **Tacos L (item 97)** multi-viandes (Viande 1 / Viande 2 / Sauce), prix base 7,90 €.

## Verdict : ALL_OK — 9/9 fonctionnalités prouvées bout-en-bout, 0 défaut reproduit

| # | Fonctionnalité | Probe e2e réel | État |
|---|---|---|---|
| 1 | GET /menu (token) | `GET /api/frontend/menu` → 200, 187 KB, 35 items, keys=branch,categories,items,upsell_rules,promos,snapshot_version | OK |
| 2 | pricing/preview | complet (V1=363,V2=370,Sauce=375) → 200 total=7,90 tax=0,72 SSOT ; incomplet (Viande 2 manquante) → **422** `Sélectionnez au moins 1 Viande 2 (actuel : 0)` (signal jaune silencieux côté front) | OK |
| 3 | order/quote signé | `POST /order/quote` (form, items JSON) → 200 quote_token + signature 64hex + ttl 299s + total_ttc 7,90 | OK |
| 4 | POST /order Plan B | order_type=10(TAKEAWAY) pm=1(CASH) → **201 #5461** : payment_status=15(PENDING_COUNTER), status=4(ACCEPT auto), total=7,90 (backend SSOT), source_surface=kiosk, fiscal_seq=NULL. **composition_snapshot multi-viandes distribuées : Viande 1→Viande Hachée(363), Viande 2→Mexicanos(368), Sauce→Mayonnaise(375)** | OK |
| 5 | quote tampering → 401 | signature 1 hex flip → **401** `Order quote signature mismatch` ; payment_method drift (quote sans pm vs order pm=1) → **401** `Order quote intent mismatch` | OK |
| 6 | order_type=KIOSK sans machine → rejeté | token kiosk:order user sans KioskMachine, order_type=25 → **422** `Le service borne nécessite une machine enregistrée` ; preview machine-less → **503** `KIOSK_MACHINE_NOT_FOUND`. (order_type=25 dine-in aussi désactivé V1 même avec machine → `service sur place désactivé` : borne = à emporter only) | OK |
| 7 | upsell | front appelle `frontend/item/kiosk-upsell?item_ids=97&limit=6` → **200, 6 suggestions** (kioskCart.js:832). Nouveau `/upsell` → 200 data=0 (0 upsell_rules configurées = correct) | OK |
| 8 | idempotence X-Idempotency-Key | replay même clé → **201 renvoie #5461 identique, 0 doublon** ; clé manquante (user branch=0) → 422 `MISSING_IDEMPOTENCY_KEY` (fail-closed) | OK |
| 9 | dégradation offline cash-only | design vérifié (kioskCart.js:766-810) : erreur réseau/5xx + méthode électronique → `KIOSK_OFFLINE_ELECTRONIC_PAYMENT_REFUSED` ; cash → file locale avec idempotency-key préservée + re-quote frais au replay. Backend accepte le replay (idempotence prouvée #8). config live `kiosk.payment_route_all_to_counter=true` | OK |

## Preuves complémentaires (hors 9)
- **SSOT anti-manipulation** : order avec `total=0.01 subtotal=0.01 discount=99` + quote valide → **201 #5470 total=7,90 subtotal=7,90 discount=0** (champs financiers client ignorés/unset).
- **Cross-surface** : #5461 présent dans file caisse counter-collect (PENDING_COUNTER, non-canceled, surface=kiosk) ET KDS-eligible (status=4 ACCEPT, kds_station non filtré).
- **NF525** : fiscal_sequence_no=NULL à la création borne cash (alloué seulement à l'encaissement caisse) = correct.
- **escpos ticket borne** : `GET /order/show/5461/escpos?variant=client` → 200, 1487 octets (payload b64 ESC/POS).
- **loyalty/scan** invariant non-bloquant : QR invalide bien formé → **200** `ok:false error_code=qr_legacy_rejected` (jamais bloquant).
- **promo/validate** code bogus → 422 corps gracieux `Code invalide ou expiré`.

## Défauts confirmés : AUCUN
Toutes les portes échouent fermées (fail-closed). Multi-viandes distribuées correctement (bug historique « Viande 2 perdue » ABSENT). Prix 100 % backend. Idempotence at-most-once. Quote HMAC + intent hash inviolables.

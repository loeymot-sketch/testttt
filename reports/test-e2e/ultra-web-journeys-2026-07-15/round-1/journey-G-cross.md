# Journey G-cross — Intégrité numérique cross-surface (borne → caisse → KDS → OSS → DB → ticket)

Date : 2026-07-15 · Agent : e2e-Gcross · Serveur live http://127.0.0.1:8000 · Statut : **DEFECTS (parcours complet exécuté, 3 défauts, 0 P0/P1)**

## Parcours exécuté (preuves)

Produit test : **Coca-Cola 33cl** (item_id=52, prix DB 1,90 €, TVA 10%).

1. **Quote borne** — POST `/api/frontend/order/quote` (token kiosk:order, order_type=10 TAKEAWAY) →
   `subtotal 1.9, total_tax 0.17, total_ttc 1.9` + quote_token signé.
   Note : la 1ʳᵉ tentative avec order_type=25 (KIOSK) a été ACCEPTÉE au quote puis REJETÉE à la création (voir finding #2).
2. **Création commande borne** — POST `/api/frontend/order` (X-Idempotency-Key) →
   **order id=5689**, serial `1507265689`, **queue A0034**, `total 1.9, total_tax 0.17`, `payment_status 15 (PENDING_COUNTER)`, `payment_pending_counter=true`, `status 4 (Acceptée)`, `source_surface=kiosk`, `fiscal_sequence_no=null` (attendu — alloc à l'encaissement).
3. **(1) File caisse** — GET `/api/admin/pos/counter-collect/pending` → 5689 présent, `total 1.9` ✔ au centime.
4. **(2) KDS** — GET `/api/admin/kds-order` → 5689 présent, ligne `Coca-Cola 33cl ×1 = 1.9` ✔, status 4.
5. **(3) OSS** — vide à status 4 (design : PREPARING/PREPARED uniquement). Après KDS change-status
   `{status:7, expected_status:4}` (202) → GET `/api/frontend/oss-order` → `{id:5689, queue_number:A0034, status:7}` ✔.
6. **(4) DB** — `orders`: subtotal 1.900000, total 1.900000, total_tax 0.170000 ; `order_items`: price 1.900000, total_price 1.900000 ✔.
7. **(5) Ticket ESC/POS (avant encaissement)** — GET `/api/admin/pos/orders/5689/escpos` décodé :
   A0034, `1 Coca-Cola 33cl 1,90`, SOUS-TOTAL 1,90, MONTANT TOTAL 1,90, TVA 10% 0,17, `A REGLER TTC 1,90`, `** A REGLER EN CAISSE **` ✔.
8. **Encaissement** — POST `/api/admin/pos/counter-collect/5689/confirm` `{mode:1, received:2.00}` → 200 :
   `payment_status 5 (PAID)`, `pos_payment_method 1`, **fiscal_sequence_no 2649** (unique en DB, count=1), audit fingerprint `021f5b55a0eb`.
9. **Propagation post-encaissement** — file caisse : 5689 disparu ✔ ; KDS : status 7 + payment_status 5 ✔ ; OSS : toujours A0034/status 7 ✔ ; DB : payment_status 5, pos_received_amount 2.000000 ✔ ; `cash_movements` : 1 seul mouvement `in 1.90` (session tiroir de l'opérateur) ✔ ; ticket ré-imprimé : `ESPÈCES : 2,00`, `Ticket fiscal N 2649` ✔ (mais pas de RENDU — finding #1).
10. **Adversarial double-encaissement** (nouvelle clé idempotency, même caissier) → 200 no-op documenté (garde V5.5 same-cashier), **0 doublon** (1 cash_movement, fiscal_seq inchangé) ✔.
11. **NF525** — `php artisan fiscal:verify-chain --all` → CHAIN OK sur les 4 branches ✔.

**Verdict intégrité : 1,90 € identique au centime sur les 5 surfaces ; statuts propagés partout ; gap-free fiscal.**

## Findings

### F1 (P2) — Le ticket papier n'imprime JAMAIS la ligne « RENDU » (monnaie à rendre)
- `app/Services/Hardware/OrderReceiptEscPosRenderer.php:580` lit `$order->cash_back_amount`, qui n'est **ni une colonne DB ni un accessor** du modèle Order (tinker : `var_dump($o->cash_back_amount)` → NULL ; `cash_back_amount` n'existe que dans `OrderDetailsResource.php:120`). La garde `($p['change'] ?? 0) > 0` (l.181-182) est donc inatteignable.
- Repro exécutée : encaissement 5689 espèces reçu 2,00 € / total 1,90 € → ticket décodé montre `ESPÈCES : 2,00` **sans** `RENDU : 0,10`, alors que l'API (`payments_breakdown[0].change_amount=0.1`) et le reçu écran (`ReceiptComponent.vue:250`, guardé par change_amount>0) affichent bien 0,10 €. Divergence écran↔papier sur un document fiscal.
- Fix : dans `payments()` (l.553-583), calculer `change = max(0, (float)pos_received_amount - (float)total)` pour CASH (miroir de `buildPaymentsBreakdown`).

### F2 (P3) — Asymétrie quote↔create sur la règle V1 « borne = à emporter »
- POST `/api/frontend/order/quote` avec `order_type=25` (KIOSK/sur place) → **200** + quote signé (1,90 €) ; POST `/api/frontend/order` avec le même payload → **422** `« Le service sur place est désactivé en V1 »` (`app/Http/Requests/OrderRequest.php:240`). Le devis est montrable puis la confirmation échoue. L'UI borne réelle envoie 10 (requireExplicitOrderType), impact API-only.
- Fix : appliquer la même garde V1 côté quote (surface kiosk dans `PosController::quote` / `OrderQuoteService`).

### F3 (P3) — `cash_back_amount` négatif et float brut dans l'API
- `app/Http/Resources/OrderDetailsResource.php:120` : `pos_received_amount - total` sans garde → commande PENDING_COUNTER : `cash_back_amount:-1.9`, `cash_back_currency_amount:"-1,90 €"` (réponse création 5689) ; après encaissement : `0.10000000000000009` (bruit flottant non arrondi). L'UI actuelle n'affiche que les variantes guardées — hygiène de contrat.
- Fix : `round(max(0, received - total), 2)`.

## Exclusions respectées
Aucun re-signalement des items healés/escaladés (split overpay, coupon, wallet, etc.). Frozen zones non touchées (lecture seule). Aucune clôture Z. Données créées : commande 5689 uniquement (encaissée proprement, chaîne NF525 verte).

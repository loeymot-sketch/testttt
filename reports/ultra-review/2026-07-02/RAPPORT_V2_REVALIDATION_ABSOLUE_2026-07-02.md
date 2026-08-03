# RAPPORT V2 — RÉVALIDATION ABSOLUE ADVERSARIALE — 2026-07-02
**Mission** : GOAL_FABLE5_ULTRA_V2 — ne pas croire le « 11/11 GREEN », le RÉFUTER. HEAD `61e9ea7b7`
(+ working-tree). Réfère `GOAL_FABLE5_ULTRA_V2_REVALIDATION_ABSOLUE_2026-07-02.md`.

## 1. VERDICT — le « GREEN » a bel et bien été CASSÉ (c'était le but)

L'attaque refute-by-default sur 8 cibles × 10 angles a **brisé 4 des « verts »** et confirmé
**10 findings réels** (post re-vérification indépendante : 6 CONFIRMED + 4 DOWNGRADE, 1 REFUTED).
**Preuve que GREEN ≠ correct** : ma review v1 déclarait « CAISSE GREEN » — mais je n'avais jamais
inspecté la table `cash_movements`. L'adversaire l'a fait → **bug NF525 réel de piste-caisse**.

**4 findings healés cette boucle (TDD, non-frozen, frozen-diff 0, NF525 OK)** ; **6 Uber déférés**
(Production Access EN ATTENTE — backlog go-live, données owner requises).

## 2. FINDINGS CASSÉS → HEALÉS (avec repro live + test de régression)

| # | Sév | Finding (angle) | Fix | Test |
|---|---|---|---|---|
| 1 | **P2** | **CAISSE — double/phantom `cash_movement` sur commandes DIFFÉRÉES** : `posOrderStore` enregistrait un cash-in à la CRÉATION via `$request->pos_payment_method` BRUT (=CASH même si defer) sans gate `$deferToCounter` → commande différée = 1 mouvement à la création + 1 à l'encaissement = **14€ tiroir pour 7€** (corruption réconciliation NF525). Repro live 5425/5426. | `OrderService.php:~1260` gate `&& ! $deferToCounter` (le mouvement n'existe qu'à l'encaissement) | `Cash/PosDeferredNoDoubleCashMovementTest` 2/2 (différé→0, immédiat→1) |
| 2 | **P2** | **Table dine-in IDOR non-auth** : `GET /api/table/dining-order/show/{id}` (apiKey seul, RMB par PK, 0 ownership) exposait PII client (nom/tél/email/solde) par énumération. Repro live show/5410+show/100→200. | `Table/OrderController.__construct` fail-close 404 tant que `pos_dine_in_enabled=false` (dormant V1) — garde AVANT le RMB | `TableDiningOrderIdorTest` 2/2 |
| 3 | **P2** | **Loyalty `/register` account-hijack** : public non-auth attachait un email ARBITRAIRE à un compte téléphone-only existant → forgot-password → prise de contrôle (distinct de la fuite v1 déjà corrigée). Repro live. | `LoyaltyController` : email posé UNIQUEMENT à la création d'un nouveau compte ; plus d'attache sur compte existant via /register public | `LoyaltyRegisterNoLeakTest` +1 (4/4) |
| 4 | **P3** | **BORNE parité preview↔create** : `/pricing/preview` cape 20/ligne mais `/order` + `/order/quote` (ValidJsonOrder) sans AUCUN plafond → quantités absurdes (999999999), order #5420 qty=21 créé. | `ValidJsonOrder` plafond sécurité **999/ligne** (généreux : bloque l'absurde sans gêner le bulk POS ; règle partagée) | `ValidJsonOrderItemCapTest` +2 (5/5) |

## 3. FINDINGS DÉFÉRÉS — UBER go-live (Production Access EN ATTENTE, non live)
Tous latents (Uber pas encore branché ; `uber_menu_map` à peupler par l'owner ; fiscalize à implémenter) :
- **P2** item Uber non résolu → item_id NULL → contrainte NOT NULL → rollback → commande jamais créée
  → 503 retry ×5 (pas une perte silencieuse, mais go-live-bloquant tant que le mapping est vide).
- **P3** `uber.fiscalize` = no-op (aucun code n'alloue de seq pour source_surface=uber_eats — chemin
  fiscal Uber à concevoir avant go-live).
- **P3** dedup `transaction_id` sans index UNIQUE (SELECT-then-INSERT non atomique → doublon sous course).
- **P3** `LIKE '%titre%'` best-effort → mauvais produit si titre court (map vide).
- **P3** `denyOrder`/`storeStatus`/`deny_on_out_of_stock` définis mais jamais câblés (complétude go-live).
- (REFUTÉ : « INSERT webhook_events → 500 » — la fenêtre de course existe mais ne produit pas de 500.)
→ **Recommandation : workstream Uber go-live dédié** (peupler map + implémenter fiscalize + index UNIQUE
`transaction_id` + câbler deny/store-status) quand l'owner obtient Production Access.

## 4. CIBLES QUI ONT TENU (GREEN_HELD sous attaque)
- **Encaissement + chaîne NF525** : allocation seq à l'encaissement seul, gap-free, double-confirm rejeté
  (idempotence), chaîne HMAC intacte avant/après. **Prouvé live** : card #5407 → PAID+CARD+seq 2603+**0
  cash_movement** (pas de phantom), CHAIN OK.
- **KDS + OSS + intersection** : filtre status+payment (kds_station = mythe confirmé), zéro doublage
  cross-surface, OSS public 0 PII.
- **WEB standalone + MOBILE RN** : pas de dérive (data = miroir DB, palette mobile conforme, NO-API-V1
  respecté).

## 5. GATES
- **Frozen-diff 0** (heals non-frozen : OrderService, Table/OrderController, LoyaltyController,
  ValidJsonOrder + tests — aucun fichier §7 touché).
- **NF525 CHAIN OK 4 branches** (avant + après card encaissement live).
- **Suite backend** : **3003 passed / 0 failed / 0 error** (2 incomplete + 29 skipped intentionnels)
  + 4 nouveaux fichiers de tests de régression V2 (cash-movement 2, table-IDOR 2, loyalty +1, qty-cap +2).
  NB : le 1er run avait 1 échec `IdempotencyRequiredRoutesCoverageTest` — **auto-infligé** (mon garde
  dine-in dans le __construct interrogeait `settings` au route-scan) → corrigé (garde déplacé dans les
  méthodes) → re-run 0-failed. La suite a donc attrapé ma propre régression avant livraison (re-prove).
- **Zéro doublage** : re-baseline a évité de re-corriger les 3 heals déjà commités par l'owner (loyalty
  leak, Uber 503, runbooks) — seuls les NOUVEAUX vecteurs (email-attach, cash-trail, IDOR) healés.

## 6. LEÇON CONFIRMÉE (owner avait raison)
« GREEN ≠ correct. » La révalidation adversariale a trouvé un **vrai bug NF525 de piste-caisse** (double
cash_movement) que 2 passes de review « GREEN » avaient manqué — parce que ni v1 ni la validation
per-système n'inspectaient `cash_movements`. La boucle refute-by-default + verify-indépendant + re-prove
est ce qui l'a débusqué. **Barre atteinte** : 4 findings réels healés+testés, 6 Uber tracés go-live,
frozen 0, NF525 OK.

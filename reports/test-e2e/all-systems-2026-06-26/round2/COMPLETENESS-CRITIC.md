# COMPLETENESS-CRITIC — « Qu'avons-nous raté ? » (round 2 final)

**Rôle** : critique de complétude final. READ-ONLY (SELECT only, 0 écriture, 0 fichier code modifié).
**DB** : foodking_e2e (branch 1 = Le Cayenne ; 7/8/9 = branches Faker-seed). Serveur :8766.
**Méthode** : pour chaque catégorie, vérifié file:line + DB, PUIS dédupliqué contre les rapports frères déjà écrits dans `round2/` + `_cross-cutting/` + `web-app/`.

## VERDICT : QUASI-CONVERGÉ — 2 résidus P3 neufs (mineurs), 0 P0/P1/P2 neuf

Les 5 catégories sont **couvertes** ; tout finding substantiel (refund twins, gaps fiscaux, quote↔store, terminal-collect, sync, cohérence menu, dine-in dormant) est **déjà traité/documenté** par un rapport frère (vérifié ci-dessous). Après dédup il ne reste que **2 résidus P3** absents de tout rapport.

---

## RÉSIDUS NEUFS (non présents dans les rapports frères)

### [P3] PosLoyaltyRedeemModal.vue:113 — money point-décimal user-facing (jumeau résiduel du heal#4)
- **Fichier** : `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:113`
  `<strong>−{{ previewDiscountEur.toFixed(2) }} €</strong>` + 2ᵉ occurrence script (`amount: \`${eur.toFixed(2)} €\``).
- **Repro** : POS → redeem loyalty → l'aperçu de remise rend « −5.00 € » (point) au lieu du FR « −5,00 € ». Le fichier n'a **aucun** `Intl('fr-FR')`, ni `currencyFormat`, ni `replace('.',',')` (vérifié : intl=0, replace=0).
- **Pourquoi V1-pertinent** : mandat FR ADR-007 (virgule décimale). Le heal#4 a corrigé `appService.currencyFormat` ; le rapport `_cross-cutting/frontend-format-findings.md` a scopé le money-FR à **POS-cart + checkout + coupon** — ce modal loyalty n'y figure pas. **Vérif-avant-report a réduit le « cluster » de 7→1** : PosRefundModal/PosCounterCollect/CashOverview/CashDrawerDialog/DeliveryBoyCashSession×2 utilisent tous `Intl('fr-FR')` en rendu PRIMAIRE (toFixed = simple fallback try/catch) → **FR-corrects, PAS des défauts**. Seul PosLoyaltyRedeemModal a un toFixed nu en template.
- **Reco heal (NON-frozen)** : router la ligne 113 + le message via `Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'})` ou `appService.currencyFormat`. Display-only (montant correct, séparateur seul).

### [P3] RestoreLeCayenneDessertsAndDrinksSeeder.php:142-145 — prix boissons périmé 1,50 (mine latente)
- **Table/fichier** : `database/seeders/RestoreLeCayenneDessertsAndDrinksSeeder.php:142-145` seed Coca/Coca-Zero/Fanta/Sprite à **1.50**.
- **DB live (SSOT)** : `items` Coca-Cola 33cl = **1.90** == `mobile/data/menu.js:422` == `web/data/menu.js:393` (1.90). État courant **cohérent**.
- **Repro** : `php artisan db:seed --class=RestoreLeCayenneDessertsAndDrinksSeeder` régresserait les boissons DB à 1,50 → divergence vs menu.js (1,90) + board borne (1,90).
- **Pourquoi V1-pertinent** : SSOT prix NF525 ; landmine si re-run (superseded par `OwnerMenuUpdate20260623Seeder`). Non couvert par `web-app/menu-update.md` (qui ne traite que les fichiers menu.js).
- **Reco heal (NON-frozen)** : passer le seeder à 1.90 ou le marquer `@deprecated`.

---

## CATÉGORIES — COUVERTES (preuve + pointeur dédup)

### 1. Modalités non-testées — COUVERT
- DB branch 1 order_type : TAKEAWAY 1236 / POS 158 / DELIVERY 53 / KIOSK 29 = réels & exercés. **DINING_TABLE=20 : 1 commande, NON payée ; `dining_tables`=1 ; `config(pos.dine_in_enabled)=NULL`** → dine-in DORMANT (déjà documenté V1.0.X par `SUPERVISOR-refund-twin-investigation.md`). `order_type=30` (1338, tous 2026-05-28→06-08, 0 récent, serials `300526xxx`) = **seed legacy pré-reset** (valeur enum inexistante), mort. Aucune modalité live non-auditée.

### 2. Jumeaux des heals — COUVERT (sauf le P3 money ci-dessus)
- **Refund (heal#1)** : `OnlineOrderController::changeStatus` + `TableOrderController::changeStatus` atteignent RETURNED→`cashBack` (OrderService:2189) SANS garde `pos-refund` (gardés seulement `online-orders`/`table-orders`). **DÉJÀ trouvé ×2** : `refund-bypass-other-routes.md` + `SUPERVISOR-refund-twin-investigation.md` (P3 latent : online-orders/table-orders détenus par Admin+BM qui ONT pos-refund ; Waiter a table-orders sans pos-refund MAIS bloqué par `OrderStatusRequest::authorize:25` qui omet Waiter, + dine-in off). Confirmé indépendamment, **pas neuf**.
- **Quote≠store (heal#2)** : enforce STORE sur les 4 requests (`PosOrderRequest`/`OrderRequest`/`Kiosk\PricingPreviewRequest`/`TableOrderRequest` via `ValidatesOrderItemVariations`). Couvert `quote-omission-coverage.md` (HOLD).
- **Terminal-collect (heal#8)** : chokepoint unique `PaymentService::confirmCounterPayment`. Couvert `races-concurrence-healed-paths.md` (c — NEW_FINDING P1 = ce heal).
- **Promo (heal#7)** : couvert `promo-kiosk-gate-integrity.md` (HOLD, fail-closed prouvé).

### 3. Invariants NF525 — COUVERT par `fiscal-encashment-edges.md`
- Gap branch 1 = **2506/2507/2508** (orders 4974@2505→5019@2509) : root-cause `Iter15CleanupTestOrdersCommand.php:97` (hard-delete fixtures, **prod-guardé** L64-67) ; alloc `FiscalSequenceService` MAX-based auto-réparatrice (rollback ne peut PAS gapper en prod). 0 doublon. `fiscal:verify-chain --branch=1` = **CHAIN OK**.
- PAID-sans-fiscal = 9 (branch1) **factory pollution** (status=5 enum invalide, serials `ORD-xxxx-YY`, batch même timestamp) + 19 known FISCAL-CPS-01 owner-gated G-FISC-CPS. 63 orphelins cross-Z = monitorés par `fiscal:verify-z-membership` (cron Kernel:91). Snapshot pré-fiscal prouvé. **Tous dédup, 0 neuf.**

### 4. Cohérence menu — COUVERT (sauf P3 seeder ci-dessus)
- DB == mobile == web : Tacos L 7,90 / Tacos M 6,90 / Cayenne 7,40 / Coca 1,90 / Bol Riz 7,90 / formule +2,50. **Cohérent** (`web-app/menu-update.md`).

### 5. Sync/intégration — COUVERT par `sync-snapshot-order5179.md`
- Event `OrderCreated` (seul ShouldBroadcast, centralisé OrderService). Commande 5179 prouvée VISIBLE KDS board+history+sync+OSS ; payload `created_at_iso`/`updated_at`/`version` cohérents ; snapshot figé. 0 broadcast-sans-listener, 0 perte.

---

## Anti-hallucination
Tout cité = vérifié (file:line + SELECT). Le « cluster money 7 composants » a été réduit à 1 après vérif rendu primaire (Intl fr-FR). Le jumeau refund online/table a été reconnu comme **déjà couvert** (non re-revendiqué comme neuf). Sévérité V1-LOCAL appliquée : les 2 résidus = display/landmine = P3.

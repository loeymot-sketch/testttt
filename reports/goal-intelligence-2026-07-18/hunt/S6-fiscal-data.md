# S6 — CHASSE FISCAL BACKEND + INTÉGRITÉ DATA (read-only, 2026-07-18)

Périmètre : logique fiscale NF525 + intégrité data, DB `foodking_e2e` (queries SELECT uniquement, 0 écriture, 0 fichier code modifié). EXCLUS par mandat : chaîne VPS TAMPER (Workstream A), sentinelles, cache-driver UNI-03, dumps SQL trackés.

Méthode : lecture des 7 services `app/Services/Fiscal/*` (frozen, lecture seule), traçage des 6 chemins d'allocation de séquence, exécution des détecteurs read-only (`fiscal:verify-sequence-continuity`, `fiscal:verify-z-membership`, `fiscal:verify-chain --all`), 25+ queries SQL de preuve.

État chaîne : `fiscal:verify-chain --all` → **CHAIN OK sur les 4 branches** (Z + audit). Les findings ci-dessous sont des trous LOGIQUES/DATA, pas des ruptures de chaîne.

---

## FINDINGS CONFIRMÉS

### F1 [P1] `app/Services/Fiscal/FiscalSequenceService.php:97-103` + table `orders` — RÉUTILISATION de fiscal_sequence_no après hard-delete (numérotation dérivée d'une table mutable, non protégée par trigger)

**Mécanisme.** `next()` = `MAX(fiscal_sequence_no)+1` sur `orders`. Si le détenteur du MAX est hard-deleted, l'allocation suivante ré-émet LE MÊME numéro. L'index unique `orders_branch_fiscal_seq_unique` ne protège pas (le détenteur précédent a disparu). Et la famille de triggers d'immutabilité (9 triggers vérifiés via `SHOW TRIGGERS` : audit_logs UPDATE/DELETE, z_reports, order_payments, cash_movements, cash_drawer_sessions, stock_movements, order_items.composition_snapshot) **ne couvre PAS `orders`** — la seule table qui porte les numéros fiscaux. Protection app-level uniquement (SoftDeletes one-way) ; `forceDelete`/SQL brut passent.

**Preuve (reproduite).** La chaîne d'audit append-only enregistre `fiscal_sequence_no` dans le payload de chaque encaissement :
```sql
SELECT JSON_EXTRACT(payload,'$.fiscal_sequence_no') seq, COUNT(DISTINCT resource_id) claimers
FROM audit_logs WHERE action='order.counter_payment_confirmed'
GROUP BY seq HAVING claimers>1;
-- → seq 2579 : 6 orders distincts | seq 2068 : 5 | seq 2624 : 2
```
Détail seq 2068 : orders 4284/4285/4286/4287 (encaissés 2026-06-08, tous hard-deleted depuis) + order 263 (détenteur actuel, 2 €). Cinq ventes différentes revendiquent le même numéro fiscal dans la chaîne signée — dont les montants divergent (4×8 € vs 2 €). Idem 2579 (6×4.90 € vs détenteur actuel 5388 à 1.90 €) et 2624.

**Impact prod.** Les purges d'orders arrivent réellement (gate owner « purge 186 cmd test » 2026-07-08). Une purge de rows numérotées en prod = ré-émission silencieuse de numéros + contradictions permanentes dans la chaîne d'audit → indéfendable en contrôle NF525. Remède (pour plan, pas exécuté ici) : trigger `BEFORE DELETE ON orders WHEN fiscal_sequence_no IS NOT NULL → SIGNAL`, et/ou compteur monotone dédié (table `fiscal_counters`) au lieu de MAX().

### F2 [P1] branch 1 — TROU RÉEL seq 2506-2508 + le détecteur de continuité N'EST PAS PLANIFIÉ

**Preuve.**
```
php artisan fiscal:verify-sequence-continuity
→ Branche 1 : TROU(S) DÉTECTÉ(S) — attendu 2676, présents 2673. Manquants : 2506-2508
```
Rows hard-deleted entre order#4974 (2026-06-19 00:31) et order#5019 (2026-06-20 01:13). Le trou existe depuis ~1 mois, jamais surfacé : `grep -n continuity app/Console/Kernel.php` → **aucune occurrence**. Le scheduler exécute `fiscal:verify-chain` (03:30) et `fiscal:verify-z-membership` (quotidien) mais PAS `fiscal:verify-sequence-continuity` (`VerifyFiscalSequenceContinuityCommand`, read-only, sûr). En prod, un trou de numérotation resterait invisible entre deux audits manuels.

### F3 [P2] `orders` + `RetryFiscalAllocCommand` — 22 orders PAID sans séquence, 22/22 avec flag NULL → invisibles à VIE du cron retry, et aucun détecteur ne les couvre

**Preuve.**
```sql
SELECT COUNT(*), SUM(fiscal_alloc_error_at IS NULL) FROM orders
WHERE payment_status=5 AND fiscal_sequence_no IS NULL;  -- → 22, 22
```
Le retry (`RetryFiscalAllocCommand.php:65-69`, everyMinute) exige `fiscal_alloc_error_at IS NOT NULL` — or seul `finalizePaidKioskOrder` (kiosk CARD/TR) pose ce flag. Toute row PAID-sans-seq née autrement (historique pré-fixes, insertion test, incident) est ignorée pour toujours. `ZReportService::warnOnOrphanedPaidOrders` (l.730-767) ne warn que dans la fenêtre du Z en cours de clôture → un orphelin antérieur à la dernière clôture ne re-warn JAMAIS. `fiscal:verify-z-membership` ne scanne que les orders NUMÉROTÉES. → Une vente PAYÉE hors chaîne n'a AUCUN filet de détection permanent. (Les 22 rows elles-mêmes = fabrications de tests — serials `WEBTEST-*`, `E2E*`, `ORD-0825-VO`, sans audit d'encaissement — les chemins de code actuels allouent tous en tx ; le finding est le trou d'OBSERVABILITÉ, pas un chemin d'écriture vivant.)

### F4 [P2] — 2442 commandes numérotées hors de TOUT Z signé (orphelins « fenêtre-morte pré-C33 »), aucun chemin de régularisation

**Preuve.** `php artisan fiscal:verify-z-membership` → 2442 rows, 100 % motif « fenetre-morte pre-C33 » (0 fuite post-C33 — le fix continuous-partition du 2026-07-07 tient). La borne basse C33 (= closed_at du Z précédent) les exclut À VIE de tout futur Z : orphelins PERMANENTS. Le détecteur est planifié (Kernel:98) et alertera à vie sans outil de rattrapage/apurement documenté. Sur e2e = pollution test ; en prod, toute vente antérieure au deploy C33 dans une fenêtre morte a le même statut.

### F5 [P2] `ZReportService.php:499-515` — ajustement post-Z clé sur `updated_at` MUTABLE → une 2e soustraction du même order dans un Z signé ultérieur est possible

`postZAdjustmentQuery` matche : fiscalDate ≤ from + `updated_at` ∈ fenêtre + statut terminal. Après qu'un order terminal a été soustrait dans Z_n, N'IMPORTE QUEL `save()` ultérieur (backfill, script heal, touch) re-bump `updated_at` → re-match dans la fenêtre de Z_m (m>n) → **re-soustraction du même montant** dans un Z signé. Aucun marqueur « déjà ajusté », aucune clé stable (ex. timestamp de transition terminal).

**Preuve du déclencheur réel (data).** Orders 946/947 (seq 2445/2446, PAID, terminaux le 2026-05-31 03:16 — `order_status_transitions`) ont `updated_at = 2026-06-17 00:34:37` : bumpés 17 jours après leur état terminal, avec 15 clôtures Z entre les deux. Aucun Z signé n'est corrompu À CE JOUR (vérifié : les fenêtres pré-C33 utilisaient `opened_at` → les deux occurrences sont tombées en fenêtre morte ; le Z#27 post-C33 n'a que 4 cancels pre-Z légitimes, 0 post-Z). Mais post-C33 les fenêtres tuilent exactement → le scénario est désormais OUVERT au premier bump d'updated_at sur un terminal déjà ajusté.

### F6 [P2] tables `transactions` + `cash_movements` — colonnes `order_id` SANS FK → 11 + 8 rows orphelines pointant des ventes disparues

**Preuve.**
```sql
SELECT COUNT(*) FROM transactions t LEFT JOIN orders o ON o.id=t.order_id WHERE o.id IS NULL;      -- 11
SELECT COUNT(*) FROM cash_movements cm LEFT JOIN orders o ON o.id=cm.order_id
WHERE cm.order_id IS NOT NULL AND o.id IS NULL;                                                     -- 8
```
`information_schema.KEY_COLUMN_USAGE` : FKs présentes sur order_items(order_id, item_id, branch_id) et order_payments(order_id, terminal_id) ; **aucune** sur transactions.order_id ni cash_movements.order_id. Les 11 transactions orphelines sont des encaissements réels (`COUNTER-4284-*`, `COUNTER-5374-*`…) dont l'order a été hard-deleted (traces du même incident que F1). Les 8 cash_movements orphelins (order_payment IN 4.90 ×5, cashback OUT 20 € ×3) sont PERMANENTS (trigger no-delete) : le ledger tiroir référence des ventes inexistantes → réconciliation caisse inexplicable a posteriori.

### F7 [P3] `ZReportService.php:426` — filtre d'inclusion Z NÉGATIF (`payment_status != UNPAID`) au lieu d'une whitelist

PENDING_COUNTER(15), REFUNDED(20) et toute valeur corrompue passent le filtre. Aujourd'hui inoffensif — prouvé : 0 order PENDING_COUNTER avec seq ; les 2 rows à payment_status invalide (0 et 1, ids 9/68, 2026-05-28, soft-deleted) n'ont pas de seq. Mais l'invariant voulu est « PAID ou REFUNDED » ; une whitelist fermerait le risque qu'un futur état intermédiaire numéroté compte comme CA dans le Z signé.

### F8 [P3] — 8 orders UNPAID+CANCELED détenant des numéros fiscaux (seq 2669-2676), absents de TOUT compteur Z

POS non-différé alloue à la création (OrderService:1194-1197) ; annulation avant paiement → le numéro reste sur une non-vente. Pas un trou (les rows persistent, gap-free OK) mais ces tickets numérotés n'apparaissent NI dans le CA ni dans `cancel_count` du Z (le filtre payment_status≠UNPAID les exclut aussi des compteurs d'annulation, ZReportService:482-487). Traçabilité DB seule ; à documenter comme doctrine ou compter en cancel_count.

### F9 [P3] `XReportService.php:58-64` — tie-break prédécesseur absent (drift X vs Z)

`defaultFrom` = `orderByDesc(closed_at)` sans tie-break `id`, alors que le close Z (ZReportService:255-261) utilise (closed_at, id) pour deux clôtures au même instant. Cas pathologique : X intraday peut choisir un autre prédécesseur que le Z qui signera → snapshot ≠ Z. Aligner l'ordre.

### F10 [P3] — 65 orders avec `queue_number` mais `business_date` NULL (échappent à l'unicité)

L'unique `orders_branch_business_date_queue_unique` tient (0 doublon réel sur rows renseignées — vérifié), mais NULL désactive l'unicité MySQL sur 65 rows (2026-05-28 → 2026-07-07, plus rien depuis). Risque résiduel faible ; backfill + NOT NULL à terme.

### F11 [P3] — index composite manquant `orders(branch_id, created_at)` / `fiscal_dated_at`

Fenêtres Z (`COALESCE(fiscal_dated_at, created_at)` borné) et rapports par date s'appuient sur idx_orders_branch_payment/branch_status. Volume V1 (5.7k rows) : négligeable ; à revoir avant croissance. `audit_logs` a déjà (branch_id, created_at) ✓.

---

## ÉCARTÉS (raison)

- **Doublons queue_number** (A0003 ×5 le 06-08…) → RÉFUTÉ : business_date distincts par row ; `GROUP BY branch, business_date, queue` → 0 collision réelle. Mon premier grouping (DATE(created_at)) était faux.
- **Suppléments (items 4-11) à 0 % TVA au catalogue** → pas de risque fiscal : `SELECT COUNT(*) FROM order_items WHERE item_id IN (4..11)` → **0** vente en ligne autonome ; vendus en extras, taxés au taux de la ligne parente (PricingService : taxe calculée sur le total de ligne extras inclus). Hygiène catalogue seulement.
- **Ventes récentes à tax_rate=0 sur items catalogués 10 %** (5722/5723 « Menu Frites+Boisson » 15/07 seq 2660, 5493, 5498) → rows FABRIQUÉES par tests (serials `WEBTEST-*`/`E2E*`, user admin, aucun audit d'encaissement, composition_snapshot NULL) — pas le funnel réel. Le vrai chemin (PricingService.php:241-263 + FrontendOrderService legacy:442-452) résout la taxe DB au moment de la vente. Les 0 % POS/kiosk de 05-28→07-07 = items boissons créés le 07-05 sans taxe, corrigés le 2026-07-11 (items.updated_at 15:48:48) — historique clos.
- **Boissons à 10 %** (canettes scellées à emporter = 5.5 % légalement) → décision owner 2026-07-11 (7 boissons fixées À 10 %). Sur-déclaration éventuelle ≠ fraude ; re-trancher côté owner, pas un défaut de code.
- **payment_status invalides (0/1)** → 2 rows 2026-05-28 soft-deleted sans seq, impact nul (absorbé dans F7).
- **composition_snapshot réécrit** → AUCUN chemin : écrit à création partout (PricingService:291, FrontendOrderService:470, OrderService:541/1055/1625, miroir refund copie parent:171) ; garde modèle OrderItem:50-58 + trigger DB `order_items_composition_snapshot_no_update` vérifiés présents. Les 38 lignes à snapshot NULL = fabrications tests/historiques.
- **Refund fiscal** → sain : miroir RETURNED/REFUNDED + seq frais + items/payments négatés + cashback tiroir + audit `order.refund.counter_entry` + garde parent-scellé + unique parent_order_id. Le miroir sans `fiscal_dated_at` est correct (created_at = instant d'allocation).
- **Chemins d'allocation** → les 6 couverts en tx (POS create :1194 ; counter-collect PaymentService:364 ; kiosk TPE finalize:1249 ; « marquer payé » OrderService:2670 ; livraison COD :2010 ; refund mirror :107) — échec d'alloc = rollback du PAID (sauf kiosk → flag+retry). Uber exclu par config `uber.fiscalize=false` (décision owner documentée, code Order.php:327).
- **TAMPER VPS / sentinelles / UNI-03 / dumps** → exclus par mandat.

## Synthèse sévérités
P1 ×2 (F1 réutilisation seq après hard-delete — prouvée 2068×5/2579×6/2624×2 ; F2 trou 2506-2508 + détecteur non planifié), P2 ×4 (F3 orphelins PAID indétectables, F4 2442 hors-Z sans rattrapage, F5 re-soustraction updated_at, F6 FK manquantes + 19 orphelins), P3 ×5 (F7-F11). Chaîne HMAC Z+audit : VERTE partout. Aucun fichier modifié ; aucune écriture DB.

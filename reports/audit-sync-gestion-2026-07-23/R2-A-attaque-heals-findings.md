# R2-A — Attaque adversariale des 4 guérisons (CYCLE 2)

> RÔLE : adversaire hostile. READ-ONLY, DB `foodking_e2e` (probes SELECT-only, 0 mutation, 0 commit).
> HEAD `552e85409` (heals `e097ca1df` + `c1dbcb53c`). Cibles : B-1, B-2, D-1, D-2.
> Verify-before-report : chaque tentative = repro réelle (grep/Read code-trace + tinker lecture).

## Tableau de sévérité
| # | Fix | Sévérité | Verdict |
|---|-----|----------|---------|
| R2-1 | B-2 | **P3** (déterministe, faible matérialité, non-fiscal) | **NOUVEAU DÉFAUT** — extra « Œuf » double-décompté (2 u/œuf) |
| R2-2 | B-1 | **P3** (multi-worker/redis uniquement ; mono-worker FIFO = sûr) | **RÉSIDUEL** — reprise async vs stock_levels SYNC = réordonnancement possible |
| R2-3 | D-2 | **P3** (pré-existant, aggravé) | **RÉSIDUEL** — PENDING non-web actionnable désormais 100% invisible (board + compteur) |
| — | D-1 | — | **TIENT** — aucune brèche |
| — | B-1 idempotence/refund | — | **TIENT** |
| — | B-2 anti-collision id | — | **TIENT** |

---

## B-1 — conso annulée (reverseForOrder + listener OrderCanceled)
- **(a) transition terminale SANS OrderCanceled ?** ÉCHEC (fix tient). Tous les points d'entrée terminaux dispatchent `OrderCanceled` (OrderService 2303/2574/3137, PaymentService 856, FrontendOrderService 874, Cleanup 340/408, Uber 369). Les chemins refund-only (`RefundWithCounterEntryService:465`, `Stripe:504`, `PaymentService:222`) ne créent AUCUNE dérive matière : le parent reste en statut NON-exclu (replay l'inclut → LIVE==replay) et le miroir RETURNED **ne dispatche pas OrderCreated** (jamais consommé → rien à reprendre). Cohérent avec `EXCLUDED_STATUSES`.
- **(b) double annulation / replay après reprise ?** ÉCHEC. `reversalExists` + `source_type='order_item_reversal'` distinct → 2ᵉ OrderCanceled = NO-OP. Replay EXCLUT les annulées → ne retouche jamais.
- **(c) mouvement reversal re-traité par replay/consume ?** ÉCHEC. Replay n'appelle que `consumeForOrder` (écrit `order_item` only) sur statuts non-exclus ; la ligne reversal est inerte.
- **(d) annuler jamais-consommée ?** ÉCHEC. Lit les mouvements conso (aucun) → no-op.
- **R2-2 [P3] RÉSIDUEL — réordonnancement async.** `DecrementStockOnOrderCreated` + `ReleaseStockOnOrderCanceled` sont **SYNCHRONES** (aucun `ShouldQueue`) → ordre déterministe. Or `ConsumeRawMaterialsOnOrderCreated` ET `ReverseRawMaterialsOnOrderCanceled` sont **tous deux `ShouldQueue`** (queue `default`, `QUEUE_CONNECTION=redis`). La reprise n'est donc PAS un vrai miroir : sous ≥2 workers concurrents (ou drain de backlog — la condition « worker DOWN » déjà vécue), le job REVERSE peut lire les mouvements AVANT que le job CONSUME ne les écrive → reverse = no-op, puis consume décrémente une commande annulée **jamais reprise** = exactement la dérive B-1, réintroduite. Mono-worker FIFO (consume enqueue < cancel enqueue) reste sûr. Le try/catch swallow du consume empêche le variant retry. Reco : garde « pending-reversal » (re-check) ou rendre la conso synchrone.

## B-2 — extras décomptés (subject_type='extra_group')
- **Décompte réel ?** PARTIEL-OK. DB : 8 lignes `extra_group` présentes (fiche re-jouée). Les extras payants COURANTS matchent bien : `Cheddar`(38 occ.), `Viande supplémentaire`(20), `Sauce supplémentaire`(11, 25 g), `Œuf`(5) → plus dans `skipped[]`. Machinerie NON inerte.
- **Collision subject_id ordinal ↔ id ItemExtra réel ?** ÉCHEC (fix tient). Branche id du moteur exige `subject_type=ItemExtra::class` ; les lignes portent `subject_type='extra_group'` → jamais matchées par id. Seul `OR subject_group` les résout. Design correct.
- **Viande-par-variation reste en skip ?** OUI (honnête, déclaré owner-data). `steak supplémentaire`/`cordon bleu`/`cheese` = lignes mortes (0 occ. réelle). `Cheddar Fondu`/`Champignons`/`Boursin`/`Emmental`/`Option Gratiné` = non mappés → skip (périmètre déclaré).
- **R2-1 [P3] NOUVEAU DÉFAUT — « Œuf » double-décompté.** Collation `raw_material_recipe_lines.subject_group` = **`utf8mb4_unicode_ci`** (ligature-insensible : `œ`≡`oe`). Les ordinaux 4 (`œuf`) ET 5 (`oeuf`), tous deux `raw_material_id=13` qty 1.0, sont des lignes DISTINCTES (subject_id différent, pas de collision UNIQUE) MAIS `recipeLinesForExtra("Œuf")` → `WHERE subject_group='œuf'` matche **les 2 rows** (collation CI). Repro tinker : `match[Œuf] rows=2 :: (sid=4,rm=13,qty=1) (sid=5,rm=13,qty=1)`. `addRecipeLines` SOMME → **2.0 u d'Œuf consommées par 1 supplément œuf** (×quantité). Commandes réelles avec « Œuf » = 5/2000. Le hedge `œuf`/`oeuf` se retourne contre le fix. Reco : supprimer l'ordinal redondant (garder 1 seule ligne œuf).

## D-1 — anti-résurrection terminal→actif
- **Flux légitime cassé ?** ÉCHEC (fix tient). `PENDING→ACCEPT` (from non-terminal) et `DELIVERED→RETURNED` (from non-terminal) passent. `terminal→terminal` (RETURNED→CANCELED) autorisé (to terminal → garde inactive). Aucun reopen légitime (Order::restoring hard-off).
- **abort(422) cassé par le catch ?** ÉCHEC. `catch (HttpException){throw}` (L2582) PRÉCÈDE `catch(Exception)` (L2584) → l'abort survit (pré-lock L2228 ET in-lock L2366, tous deux dans le try).
- **Bypass par autre point d'entrée ?** ÉCHEC. `changeStatus` = entonnoir admin (aucun controller Admin n'écrit `status` en direct). Kiosk promote = whitelist `PENDING`-only (AUDIT-F-013). KDS bump = `PREPARED`-only (L434). Non-admin bloqué en amont par `ValidStatusTransition`. La garde couvre exactement le trou `allows()` Admin.

## D-2 — compteur honnête (exclut PENDING non-web)
- **Web PENDING légitime caché ?** ÉCHEC (fix tient). `SimpleOrderResource` expose `status` (L73), PAS `order_status`. `isWebPending` (`o.status`) et le nouveau `todayCount` (`o.order_status ?? o.status`) résolvent tous deux `o.status` → cohérents. Web PENDING (s=1, isWebPending=true) reste **compté + bucketé** voie « Accepter ». `order_status` = fallback mort inoffensif.
- **Bucket existant re-compté ?** ÉCHEC. Seul `todayCount` change ; `stats.active`/`ready` (longueurs de buckets) inchangés.
- **R2-3 [P3] RÉSIDUEL.** Le fix corrige le mensonge du compteur MAIS un PENDING **non-web actionnable** (téléphone/POS/NULL : 4 pos + 4 delivery + 42 NULL) était déjà hors board (invisible) ; il perd maintenant son **dernier signal** (le compteur). Prémisse « paniers abandonnés » vraie pour kiosk (auto-accept), discutable pour pos/delivery. Sépare de D-2 (compteur), aggravé par lui. Reco : bucket dédié ou alerte pour PENDING non-web non-kiosk.

## Verdict
Les 4 guérisons **tiennent** contre les attaques principales : D-1 étanche, B-1 idempotence/refund solide, anti-collision B-2 correcte, D-2 champ cohérent. **1 nouveau défaut déterministe** (R2-1 Œuf ×2, P3) + **2 résiduels** (R2-2 race async P3, R2-3 invisibilité PENDING non-web P3). Aucun P0/P1/P2. 0 mutation, 0 commit.

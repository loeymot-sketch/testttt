# RAPPORT DE NUIT — 2026-07-03 (autonome, owner endormi)
**Mandat** : boucle test-e2e + amélioration max chaque coin (technique/DB/synchro/sécurité/logique + UI/UX),
agents adversaires, assurer le **long terme**. Je gère et décide ; escalade en dernier recours seulement.
**Discipline** : commits **locaux** uniquement (pas de push nocturne non supervisé), frozen-zones intouchées, TDD.

## 1. RÉSUMÉ EXÉCUTIF
Audit adversarial profond (Wave A, 6 surfaces durabilité/technique, 19 agents) → **12 findings, 10 confirmés,
2 réfutés, coverage DEEP**. J'ai **healé les 4 findings sûrs + haut-ROI** (TDD, frozen 0) et **livré 1 feature
long-terme** (instrumentation temps cuisine). Les findings restants sont **auth-critiques / fiscal-critiques /
architecturaux** → documentés avec le fix exact pour application **supervisée** (ne pas risquer la nuit).

## 2. HEALÉS CETTE NUIT (commits locaux, TDD, frozen 0)

| Commit | Type | Détail | Test |
|---|---|---|---|
| `16f89b0b2` | **Feature** | **Instrumentation temps cuisine** : 3 horodatages (accepted/preparing/prepared) posés au bump KDS (first-write-wins), Order casts, KDS resource expose `actual_prep_seconds`. **Socle de l'analytique de productivité** (temps réel vs `preparation_time` estimé) — le chantier #1 recommandé. Additif. | 2/2 |
| `16f89b0b2` | **Perf/durability** | **A1 index orders** : `(branch_id,payment_status)` [file à-encaisser full-scan] + `(status,updated_at)` [KDS history scan]. Fin de 2 full-scans à ~30k cmd/an. Additif, réversible. | via suite |
| `430334c03` | **Sécurité P2** | **change-payment-status→REFUNDED** honore le gate `pos-refund` (parité twin-route ; un POS Operator pouvait marquer REMBOURSÉ sans le droit = off-book). Miroir exact du gate sœur. | 2/2 |
| `826111b83` | **Synchro P2** | **Alarme worker-down dé-désensibilisée** : `MonitorOutboxStaleness` comptait les orphelins dead-letter (attempts≥5) dans le signal panne-worker → FAILURE permanent → fatigue d'alerte. Séparé en dimension DEAD-LETTER distincte. Rend l'unique alarme sync à nouveau fiable. | 3/3 |
| `86e3eee22` | **Perf P3** | **DashboardService::salesSummary** : boucle 1 SUM/jour (jusqu'à 365 requêtes/an) → **une seule requête GROUP BY**. Confirmé bas-risque (realizedRevenue=where-clauses) + test d'équivalence (sommes/jour exactes, annulée exclue). | 1/1 + 20/20 dashboard |
| _A2_ | **Sécu P3** | **POS customer-display gate** : la route `/pos/customer-display` n'avait aucun gate alors que toutes les routes POS portent `permission:pos` → un staff sans droit POS (Chef/KDS) pouvait pousser un total sur l'afficheur client (borné, non-fiscal). Ctor gate miroir des sœurs. | 2/2 |
| _A2_ | **Durability P3** | **Purge order_quotes** : table en croissance sans purge (~96/j, 3467 lignes) → `PruneOrderQuotesCommand` (expirés + jamais-consommés seuls ; consommés=preuve litige préservés), schedulé 04:25 (miroir webhook:prune). | 2/2 |

## 3. DOCUMENTÉS — fix exact, à appliquer SUPERVISÉ (non healé la nuit, à raison)

| Sév | Finding | Pourquoi pas healé la nuit | Fix exact |
|---|---|---|---|
| ~~P2~~ ✅ **HEALÉ** | ~~Brute-force OTP~~ → **fait `6149e01cf`** : verrou par identité (téléphone) + consume-on-abuse dans `OtpManagerService::verify` (Cache, fail-open), 3/3 tests (succès 1er essai non bloqué, brute-force brûle le code). | | |
| **P2** | **Clôture périodique fiscale (mensuelle/annuelle) + Grand Total perpétuel absents** — n'émet que des Z journaliers | **Architectural + fiscal-critique + cert-readiness** (owner : certif NF525 différée V1). Non bloquant V1 LOCAL. | `foodking:fiscal:close-period {branch} {--month\|--year}` réutilisant `ZReportService::aggregate` sur fenêtre calendaire, enregistrement signé+chaîné + colonne Grand Total cumulé ; planifier 1er du mois / 1er janvier. |
| **P3** | **Complétude archivage 6 ans inobservable** : `archived_at` jamais écrit + pas de catch-up + pas de verify | **TENTÉ la nuit → RÉVERTÉ** : stamper `archived_at` naïvement CASSE le contrat de déterminisme de l'archive (`FiscalArchiveTest > round trip deterministic` : 2 builds identiques byte-à-byte = tamper-evidence). Le stamp modifie le `z_reports.json` entre 2 builds. | **Insight affiné** : le fix DOIT **exclure `archived_at`+`updated_at` du stream `z_reports.json` archivé** (métadonnée opérationnelle, PAS donnée fiscale) AVANT de stamper — sinon perte du déterminisme. Puis (a) stamp post-build, (b) lane catch-up `archived_at IS NULL`, (c) alerte >48h. |
| **P3** | **OUT_FOR_DELIVERY dead-end** state-machine (livraison échouée sans terminal honnête) | **FROZEN §7** (`OrderStateMachine`) → nécessite LOCK+gate owner. | Ajouter une transition OUT_FOR_DELIVERY→(FAILED/RETURNED) sous LOCK owner. |
| **P3** | **Counter-entry double cash-out** : le miroir ne vérifie pas un cash_back pré-Z existant | Refund/fiscal-sensible ; vérificateur a partiellement réfuté le mécanisme. | Vérifier l'absence d'un cash_back pré-Z sur le parent avant la contre-écriture. |
| **P3** | **Pas de remboursement partiel** (tout-ou-rien) | Feature, décision produit owner. | Étendre les 2 chemins refund pour un montant partiel. |

## 3bis. CONVERGENCE — Wave A2 (2ᵉ passe adversariale, systèmes/flux)
2ᵉ vague profonde sur les SYSTÈMES (kiosk/POS/KDS/web/mobile/fidélité + intersections cross-système, 18 agents),
complémentaire de Wave A (durabilité). **Verdict = DRY : 0 nouveau P0/P1/P2**, seulement 3 P3 (2 healés
ci-dessus + 1 documenté ci-dessous). **Intersections cross-système = SOLID** (zéro-doublage tient sous re-attaque).
Fidélité (accrual idempotent, QR signer, redeem math) re-attaquée et **held-green**. → **Convergence atteinte**
sur 2 vagues / 12 surfaces : le cœur est robuste, il ne reste que du polish P3 + les items owner-gated documentés.
- **P3 documenté (non healé)** : `order.total_tax` figé après redeem fidélité POS → l'écran détail/suivi affiche
  une TVA/HT pré-remise incohérente avec le total. **Non-fiscal** (le Z et le ticket proratisent correctement la
  TVA par ratio (subtotal−discount)/subtotal — prouvé). Cosmétique. **Insight affiné (risque blast-radius)** :
  netter dans `OrderDetailsResource` suppose que `total_tax` est stocké BRUT dès qu'il y a une remise — vrai
  pour le chemin POS-redeem, mais les autres chemins (coupons kiosk/web) le stockent peut-être DÉJÀ netté →
  double-nettage = TVA fausse sur beaucoup de vues. **Fix sûr = netter dans `PosRedemptionService` (le seul
  chemin prouvé brut), PAS dans la resource partagée** — après audit des consommateurs de `total_tax` brut.

## 4. RÉFUTÉS (verify-before-report a évité 2 faux positifs)
- **Archive fiscale « sautée » par DST** : RÉFUTÉ — la lane hérite `Europe/Paris` + clôture à 23:59 hors fenêtre DST.
- **Stock `toggleStockable` incommandable définitif** (d'une passe précédente) : RÉFUTÉ — `$newReason = $available ? null` clear correctement.

## 5. UI/UX (visuel, self-driven)
- **Kiosk idle** : ✅ écran attract rendu correctement (branding Cayenne, carrousel hero, 100% HALAL, chips, CTA « Touchez l'écran »), 0 raw-label, palette juste. `nuit-kiosk-idle.png`. (401 /menu = kiosk non-appairé en navigateur neuf, attendu.)
- **KDS** : ✅ empty-state propre et brandé (« Aucune commande en cours / Les nouvelles commandes apparaîtront ici », cloche), 0 erreur console → ma modif resource (`actual_prep_seconds`) n'a rien cassé. `nuit-kds.png`.
- **POS** : ✅ caisse complète (grille catégories + images, file « À ENCAISSER BORNE (7) » = modèle Plan B live-confirmé, ticket à-emporter/livraison, empty-state), **0 erreur console**. `nuit-pos.png`.
- **Conclusion UI/UX** : 3 surfaces rendues proprement, **0 régression visuelle** des changements nocturnes.

## 6. GATES
- **Frozen-diff 0** sur toute la pile de commits nocturnes (aucun fichier §7 touché).
- **Suite complète : 3076 tests / 0 échec** (heals nocturnes verts ensemble) + DashboardService 1/1 + dashboard 20/20.
- **NF525 CHAIN OK** (baseline nuit). Zéro doublage. **UI/UX** : kiosk+KDS+POS rendus propres, 0 régression.
- **5 commits locaux** : `16f89b0b2` (timing+indexes), `430334c03` (REFUNDED), `826111b83` (monitor), `86e3eee22` (dashboard), `3809e7f21` (customer-display+prune).

## 7. RESTE / DÉCISIONS OWNER
- **Appliquer les fix documentés §3+§3bis** en session supervisée (surtout OTP auth-critique + clôture périodique fiscale).
- **Pousser** les 5 commits nocturnes (`16f89b0b2`→`3809e7f21`) — laissés locaux, ta décision.
- **Trancher** la politique de rétention des devis `order_quotes` CONSOMMÉS (preuve de litige — la purge nocturne ne touche que les abandonnés).
- ✅ FAIT cette nuit : pass UI/UX kiosk/KDS/POS (0 régression) + 2ᵉ vague adversariale = **convergence DRY**.

# GOAL — PRODUCTION TOTALE V1 « Le Cayenne » (intégration + perfection validée)
**v2 AUDITÉE** — 2026-06-12 · Panel adversarial 3 juges + vérificateur d'ancres (run `wf_2c4a9aa5-266`) : 4× CONFIRM_WITH_CORRECTIONS, **toutes les corrections intégrées ci-dessous** (BLOQUANTS → §0.0 ; chaque correction marquée `[PANEL]`).
**Mandat owner** : UN plan complet audité capitalisant tous les E2E existants ; lancement groupé d'agents spécialisés post-validation ; discipline illimitée (interface + fonctionnel + visuel + design + global) ; perfection non négociable.

---

## §0.0 PRÉ-REQUIS NO-GO (T-INT.0) — `[PANEL BLOQUANT ×2]` rien ne se lance avant
1. **DISQUE** : 877 Mo libres / 460 Gi (100 %). W-INT exige ≥15-20 Go (clones vendor/node_modules, rebuild Mix, suites, captures). → **Gate owner : purge des worktrees morts (~29 Go)** sur proposition chiffrée (liste preuve par worktree : taille, branche, ahead-vs-spine, fichiers non commités — fournie avec ce plan). Le Greffier vérifie `df` ≥15 Go AVANT T-INT.1, sinon ARRÊT.
2. **SPINE EN MOUVEMENT** : une session active committe des dispute-r3 sur `release/v1-2026-06-10` (HEAD `956933ec5`→`fc6a49ba6` constaté pendant l'audit). → Attendre sa CONVERGENCE (ou coordination PARALLEL_PROTOCOL explicite), puis **PINNER le SHA de base** dans T-INT.1 (`BASE_SHA` figé, écrit dans EXECUTION_STATUS.md). Re-sweep §0.1 au jour J (compteurs dérivants).
3. Kill des serveurs php orphelins :8768/:8769 avant setup (libère ports + RAM) — sauf si la session spine utilise encore :8768 (coordination).

## §0.1 ÉTAT PROUVÉ (sweep 2026-06-12, corrigé `[PANEL]` — à re-sweeper au jour J)
- Spine `release/v1-2026-06-10`, **mouvant** (≥`fc6a49ba6`, dispute-r3 dont P0 fidélité `0f22d2cc9`). Intégrées : cms-ux, bu-borne, pre-cloud-exec (0 ahead).
- Non intégrées (compteurs au 06-12, dérivants) : `heal/ultra-audit-w4-2026-06-11` **9/88** · `heal/clients-next-2026-06-10` **38/143** · `goal/cms-gestion-2026-06-10-spine` **39 ahead de clients-next, 17 uniques vs les deux** `[PANEL: PAS absorbé — T-INT.3bis inconditionnel]`.
- **Origin** `[PANEL corrigé]` : ~35 branches (`git@github.com:loeymot-sketch/testttt.git`), dont la lignée prod `origin/heal/pre-cloud-exec-2026-06-05` (232 derrière release / 0 devant) ; **AUCUNE branche du cycle 06-10+ pushée**. G-PUSH = pousser le tronc intégré.
- Prod OVH = lignée pre-cloud-exec du 06-06 (~230+ commits derrière le tronc futur).
- Doublons/déjà-fermés (ne PAS refaire) : kioskFormatPrice (2×), ex-G6 `b824dd933`, K4 catégorie vide, Google-Maps DrawingManager, F2-01 plafond redeem `465705855`.
- Dettes mesurées : 163 clés `label.*` manquantes fr (`[PANEL]` en label.*=976 / fr=966 ; +153 clés fr orphelines à arbitrer) · datepickers **17/21** sans locale FR · outbox **8 405 pending** · PERF boot SPA 3,2-4 s (nav in-SPA 16 ms, API <150 ms — hard-load 1×/jour) · barème fidélité DB e2e ENCORE 10/100/50 (canon 1/100/100).

## §0.2 ACTIFS E2E EXISTANTS `[PANEL: localisations réelles par branche]`
| Actif | Localisation réelle | Rôle |
|---|---|---|
| 704 fichiers PHPUnit (3 184+) | spine `tests/Feature/**` ; **+8 fichiers W4** ; +univers fidélité 112 (clients-next) | gates techniques par vague |
| **344 specs Vitest** `[PANEL corrigé]` | spine `tests/js/**` ; + specs clients-next (routerRedirectIntegrity, fidélité 18) | gates frontend + sentinelles bundles |
| Harnais Playwright | spine `tests/e2e/` (`__fixtures__`, `__screenshots__`, `_baseline-capture-2026-06-11.mjs`) | parcours UI + baselines |
| Guards lint/audit | spine `tools/lint/*.mjs`, `tools/audit/order-service-symmetry.mjs`, `tools/sentinel-codebase-parity.mjs` | gates statiques |
| Harnais serveur e2e | pattern `:87XX` + `APP_ENV=e2e` + `foodking_e2e` + `PHP_CLI_SERVER_WORKERS=8` ; intégration = **:8770** | mutations live |
| Repros d'audit | **branche w4/worktree ultra-audit-brain** : `reports/test-e2e/ultra-audit-2026-06-10/{RAPPORT_FINAL_W1W2,HEAL_W4-W6_CONVERGENCE}.md` (+23 rapports agents) ; **worktree cms-gestion** : `reports/test-e2e/loyalty-validation-2026-06-12/` (~150 captures) ; dashboard-deep 06-08 (87 shots). T-INT.1 copie ces rapports dans le worktree d'intégration (chemins absolus dans EXECUTION_STATUS.md) | re-jeu adversarial T-INT.6 |
| Comptes dédiés | `[PANEL]` ABSENTS de la DB re-clonée → **re-créer** via pattern tinker en T-INT.4 (`ultraheal@`/`ultraheal-pos@`, jamais les tokens partagés) | validation |

## §0.3 PIÈGES CODIFIÉS (inchangés sauf `[PANEL]`)
1. JAMAIS `git add -A` ; chemins explicites ; jamais push/`--no-verify` sans gate owner.
2. Worktree neuf `integration-v1-2026-06-12` ; vendor + node_modules = `cp -Rc` (clonefile APFS ≈ 0 espace réel mais le REBUILD et les caches en consomment) ; copier `.env`, `.env.testing` ; `mkdir -p storage/framework/{sessions,views,cache/data}`.
3. `.env.e2e` `[PANEL: CRÉATION pas copie]` : copier depuis la racine repo PUIS `sed` `APP_URL=http://127.0.0.1:8770` + **AJOUTER `REDIS_DB=5` + `REDIS_CACHE_DB=6`** (l'isolation n'existe dans aucun .env.e2e source — le worker op consommerait les jobs e2e) + worker dédié pointant db5. `redis-cli -n 6 DEL` clés `kiosk.menu.*` avant toute attestation payload.
4. FS case-insensitive : `tests/Feature/KDS/` (majuscules) canonique.
5. DB tests = `foodking_test` (DEVDB-GUARD) ; mutations = `foodking_e2e` SEULEMENT ; `foodking` locale RANCE ; JAMAIS OVH sans ordre owner.
6. Jamais Vitest+build même process ; full rebuild Mix après edits source ; suites full = 1 run à la fois.
7. Visual mandate : capture → **Read + analyse**, jamais « capturé » sans « lu ».
8. Findings/claims = file:line grep-confirmé + repro sinon REJECTED.

## §0.4 ARBITRAGES DE MERGE PRÉ-TRANCHÉS `[PANEL: +6 lignes]`
| Fichier | Arbitrage |
|---|---|
| `kioskFormatPrice.js` | Version SPINE ; reporter mon spec si cas absents ; adapter imports |
| `OrderService.php` | **Union prouvée saine par le juge NF525** (guard W4 = insertion pure ; spine ne mint que inline-paid ; confirmCounterPayment ne passe pas par changePaymentStatus → guard vivant, flux légitime intact ; firstOrCreate = pas de double OrderPayment). Acceptance : 4 tests guard + `ChangePaymentStatusOutboxTest` + `ChangePaymentStatusTransactionalTest` (versions W4) |
| `KioskMenuService.php` | Union (hunks disjoints K4 + dormance promos) |
| `PosOrderShowComponent.vue` | Union ; hunk spine prime, fallbacks W4 par-dessus |
| **`FrontendOrderService.php` / `OrderQuoteService.php` / `User.php` / `kioskCart.js` / `KioskCartComponent.vue`** `[PANEL]` | UNION avec **primauté aux heals spine dispute-r1→r3** (P0 monétaires fidélité dont `0f22d2cc9` statut rachat) ; re-run univers fidélité 112 + `KioskLoyaltyBillingTest` + repro live solde 2-onglets |
| `loyalty.js` / `PosComponent.vue` | Version clients-next sauf heal spine dispute postérieur sur le même hunk → union manuelle + fidélité 18/18 |
| **cms-spine (T-INT.3bis)** `[PANEL]` | Merge inconditionnel des 17 commits uniques ; si un commit touche un fichier frozen §7 (LOCK-W6) → **exclu du merge + escaladé owner** (cherry-pick du reste) ; acceptance `tests/Feature/Composer/` + sous-catégories + presets |
| `public/js/*`, `mix-manifest.json` | jamais mergés : `--theirs` puis full rebuild final (sentinelles fraîcheur font foi) |
| Docs (BRAIN/plans/reports) | concat/les deux |

---

## §W VAGUES

### W-INT — Intégration (1 Intégrateur + 1 Adversaire-merge, séquentiel)
- T-INT.0 **NO-GO §0.0** : disque ≥15 Go + spine convergé + `BASE_SHA` pinné + ports purgés.
- T-INT.1 Worktree + setup §0.3.2-3 + copie des rapports de repro §0.2.
- T-INT.1b `[PANEL]` **Attestation chaîne BASELINE** : `APP_ENV=e2e php artisan fiscal:verify-chain --all` → CHAIN OK + `SELECT COUNT(*), MAX(id) FROM audit_logs` consignés.
- T-INT.2 Merge W4 selon §0.4 • acceptance : **8 fichiers tests W4** verts (`ChangePaymentStatusOffBookGuardTest`, `ItemVariationGroupAndUniquenessTest`, `CaisseBillableUpgradesSeederTest`, `KdsUnreleasedOrderBumpGuardTest`, `KdsBoardsReleaseFilterTest`, `KioskPromoDormancyGateTest`, `SalesReportOverviewPermissionTest`, `EodPaymentBucketTenderTest`) + `ChangePaymentStatusOutboxTest`/`Transactional` + suites spine Pos/Dashboard/KDS intactes.
- T-INT.3 Merge clients-next selon §0.4 • acceptance : fidélité 112/112 + `routerRedirectIntegrity.spec.js` + Vitest fidélité 18/18.
- T-INT.3bis Merge cms-spine (inconditionnel, §0.4) • acceptance Composer/sous-catégories.
- T-INT.4 Full rebuild Mix → sentinelles vertes ; purge tokens e2e ; **re-créer comptes dédiés (tinker)** ; re-seed `CaisseBillableUpgradesSeeder` ; `[PANEL]` **`foodking:set-loyalty-rates 1 100 100` sur foodking_e2e** • acceptance `GET /api/frontend/loyalty/config → points_per_euro=1`.
- T-INT.5 Gate de sortie : PHPUnit FULL 0 échec (hors baseline risky/skipped documentés) + Vitest FULL 0 échec + **frozen-diff 0 sur les 15 fichiers §7** `[PANEL]` (liste inline : KioskWizardComponent.vue, KioskAppComponent.vue, KioskUpsellComponent.vue, PaymentComponent.vue, PosV5TrancheRow.vue, pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php, FiscalSequenceService.php, ZReportService.php, AuditLogService.php, BranchScope.php, IdempotencyKeyMiddleware.php, PricingService.php, OrderStateMachine.php) + serveur :8770 + smoke 6 surfaces 200.
- T-INT.5b `[PANEL]` **Attestation chaîne POST** : verify-chain OK + historique bit-identique (count croissant only).
- T-INT.6 Re-jeu adversarial des 13 fermetures W4 (repros RAPPORT_FINAL) + repro live fidélité 2-onglets + barème=1.
- Checkpoint : tag local `v1.0-rc1-integration` + BRAIN + manifest. `[PANEL]` **G-PUSH recommandé IMMÉDIATEMENT ici** + option owner **G-OVH-anticipé** (déployer rc1 pour fermer P0+sous-facturation en prod sans attendre W-VAL — avec la séquence sécurisée W-SHIP ②).

### W-REM — Restants (3 voies disjointes // ; R1 seule sur zone §6)
**R1 backend** : T-R1.1 Outbox `[PANEL: QUARANTAINE PAR DÉFAUT]` — les 8 405 pending âgés > cutoff 24 h sont marqués `dispatched_at=now + last_error='expired:quarantined'` **SANS broadcast** (zéro side-effect rejoué : notifs/KDS) ; lane B ne re-broadcast plus les déjà-dispatchés ; lane A bornée ; trou (attempts=5, age>24h) couvert ; `outbox:drain` ne re-broadcaste QUE les frais • tests `OutboxTest`+`OutboxRescueTest` étendus + TO BE CREATED `OutboxLaneBNoRebroadcastTest`, `OutboxQuarantineExpiredTest`, `HealthzOutboxDepthTest` (T-R1.2) • T-R1.3 worker latence <3 s mesurée.
**R2 CENTRAL/i18n-format** : T-R2.0 `[PANEL ordre]` Q-2/Q-7 (affichage ARGENT : montants online-orders €, remise typée €/%) D'ABORD • T-R2.1 163 clés fr (miroir mécanique, parité scriptée=0 ; pas de polissage rédactionnel payment-gateway V1 ; documenter les 153 orphelines) • T-R2.2 wrapper datepicker FR (17 fichiers) + grep-sentinelle • T-R2.3 Q-1/Q-5/Q-6/Q-8 • T-R2.4 `[PANEL]` **drawer ingrédients mensonger** (D-B1-01 : `IngredientService.php:209-212` fallback by-name quand `group_label` null + test ; « Utilisé dans 8 produits » vs drawer « Non utilisé » = risque suppression cassante).
**R3 borne/fidélité** : T-R3.1 F-BV-04/05/07 + `[PANEL]` **F-BV-03** (retry offline étendu aux chunks cart/upsell/payment — pattern shell/idle/catalogue existant) + **contrastes KDS** (tâche ciblée AA) • T-R3.2 `[PANEL INVERSÉ — BLOQUANT juge NF525]` BORNE-BOOT-401 : **voie par défaut = code NON-frozen** (intercepteur/bootstrap token avant 1re requête hors KioskAppComponent) ; si impossible → préparer LOCK doc + ESCALADE owner, le triple-vert ne SUBSTITUE pas le gate • T-R3.3 RGPD fidélité Q-4 (consentement + opt-out, `LoyaltyConsentOptOutTest` TO BE CREATED) + `[PANEL]` F1-02 welcome lazy-mint (+25, décision produit documentée) + F3-03 `SettingsUpdated` dans LoyaltySetupService • T-R3.4 `[PANEL]` **Rédiger LOCK_CAISSE-01-v2** (skill lock-plan : scope `pos-wizard.js:3883-3894` pattern by-name viande/sauces, rollback, triple-vert) → prêt pour contreseing G2 + consigne intérimaire caisse au dossier owner.
Gate sortie : suites full + rebuild + frozen-diff 0 + preuves visuelles analysées par fix.

### W-PERF (time-boxé 0,5 j `[PANEL]`)
T-P.1 mesures boot ×5 pages froides (admin + **borne/KDS d'abord** — les surfaces du service) ; optimisations sans toucher aux bundles kiosk sans décision owner ; **<1,5 s = cible souhaitable, pas gate** — rapport mesuré + options si non atteinte • T-P.2 PR-03/workers documentés.

### W-VAL — VALIDATION TOTALE `[PANEL: mode batché PAR DÉFAUT]`
**6 voies** (BORNE, CAISSE, KDS+OSS, CENTRAL-gestion, CENTRAL-dashboard, `[PANEL]` **STOREFRONT-client** (register/account/loyalty-consult servis par CE backend)) + 1 transverse SHARED/fiscal (chaîne avant/après + sentinelles + stress 30 commandes + **assert barème DB=1/100/100**).
Chaque voie = 4 dimensions (fonctionnel API+DB ; interface tous-boutons Playwright ; visuel/design par page OK/MINEURE/MAJEURE vs DESIGN_SYSTEM.md ; global/croisé) + **RED jumeau** qui re-prouve chaque PASS.
**Exécution par BATCHES de 2-3 voies (QA+RED jumelés), checkpoint commit entre batches** — jamais 12 agents simultanés (2 runs tués par la limite session aujourd'hui même) ; mutations DB e2e sérialisées par fenêtres (préfixes par voie) ; reprise = `resumeFromRunId` + manifests.
**Convergence : 2 cycles complets consécutifs P0+P1+P2=0 ET findings identiques.** P0/P1 → heal immédiat (max 3/cluster puis STUCK) puis rejeu du cycle ENTIER. Un P2 reste bloquant — c'est précisément pourquoi W-REM ferme d'abord les P2 CONNUS `[PANEL]`.

### W-SHIP — Paquet de sortie
Tag `v1.0-rc2-validated` ; BRAIN ; rapport final `reports/test-e2e/production-totale-2026-06-12/RAPPORT_FINAL.md` ; mémoire.
**Dossier owner `[PANEL: format copy-paste]`** — chaque étape = commande EXACTE + sortie attendue + action-si-échec :
① push origin + tag ;
② OVH `[PANEL: étape 0 backup]` : **0)** `ssh lecayenne` → `mysqldump` complet horodaté + `fiscal:verify-chain --all` AVANT (baseline, attendu CHAIN OK ; si FAIL → STOP, P0 ops préexistant) + `SHOW TRIGGERS` G5 (attendu ≥4 : no_update/no_delete × audit_logs/z_reports ; si absents → STOP, les poser d'abord) → **1)** code → **2)** migrations → **3)** `fiscal:verify-chain --all` (attendu : historique bit-identique ; si FAIL → restore dump, STOP) → **4)** `CaisseBillableUpgradesSeeder` → **5)** `foodking:set-loyalty-rates 1 100 100` (D11) → **6)** `outbox` quarantaine puis drain frais → **7)** purge cache menu → **8)** smoke 6 surfaces ;
③ contreseings : G2 (LOCK v2 prêt, T-R3.4) + consigne intérimaire caisse ; G7 optionnel ; TIME_FORMAT 1 ligne ; IP imprimante ; contrat TPE.

## §A ARMÉE
| Vague | Agents | // |
|---|---|---|
| W-INT | Intégrateur + Adversaire-merge | séquentiel |
| W-REM | 3 Implémenteurs (R1/R2/R3) + 3 RED post-commit | 3 |
| W-PERF | 1 | seul |
| W-VAL | batches 2-3 voies × (QA+RED) + SHARED | 4-6/batch |
| Transverse | Greffier (EXECUTION_STATUS.md, manifests, BRAIN, df disque à chaque checkpoint `[PANEL]`) | continu |

## §G GATES OWNER
| Gate | Quoi | Quand |
|---|---|---|
| **G-DISK** `[PANEL BLOQUANT]` | purge worktrees morts ~29 Go (liste preuve fournie) | **AVANT T-INT.1** |
| G-SPINE | convergence/coordination session dispute-r3 + pin BASE_SHA | AVANT T-INT.2 |
| G-PUSH | push origin tronc intégré + tag | post-T-INT.6 (immédiat recommandé) |
| G-OVH(-anticipé en option) | déploiement séquence ② sécurisée | post-W-VAL (ou rc1 anticipé) |
| G2 | contreseing LOCK_CAISSE-01-v2 (doc produit en T-R3.4) | dès prêt |
| G7 / DATA | promo borne optionnelle ; TIME_FORMAT ; imprimante ; TPE | au fil de l'eau |

## §F RÈGLE FINALE
DONE = un seul tronc portant tout, suites vertes ×2 cycles identiques, 0 P0/P1/P2 hors gates listés, frozen-diff 0 (15 fichiers), chaîne NF525 attestée avant/après, mesures perf commitées, dossier owner copy-paste. La perfection se constate dans les preuves.

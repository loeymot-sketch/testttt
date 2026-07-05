# GOAL — ULTRA REVIEW FULL STACK — 2026-07-02

> Mission owner (/goal verbatim) : « essaye de tout comprendre et ultra review technique et
> test-e2e de chaque system ! max compréhention et rapport complet de tout notre structure
> et code review puis syncronisation et security review et puis UI et UX review max smart
> avec agents adversaire »

## §0 Préambule

- **Nature** : mission **AUDIT / REVIEW / RAPPORT** — read-only sur le code produit.
  Livrable = rapport complet vérifié. **Aucun heal** sans décision owner (les findings
  sont rapportés classés P0..P3, avec repro + evidence). Aucun commit, aucun push.
- **Working-tree decision (§0.1)** : l'arbre porte des modifs owner non commitées
  (OnlineOrderController, TableOrderController, OrderRequest, AppLibrary,
  DeliveryFeeService, config/menu_images, BranchTableSeeder, public/css/app.css).
  Décision : **AUDIT AS-IS** — l'état réel courant est l'objet de la review. Zéro
  stash/commit/restore.
- **Env vérifié 2026-07-02** : HEAD `594eb92f5` branche `pos/category-first-caisse-2026-06-23` ;
  serveur Laravel `127.0.0.1:8766` UP (200) ; mysql+redis+soketi UP ; `queue:work redis`
  relancé (bg) ; DB=`foodking_e2e` ; `POS_SIMULATION_HARDWARE=true` (dev, choix assumé
  CONSTITUTION §2 — PAS un finding).
- **Pipeline par vague** : agents adversaires — chaque finding passe un **verify
  adversarial indépendant** (refuter avec file:line + repro exigés, sinon REJECTED),
  discipline `verify-before-report` + CLAUDE.md §3ter.
- **Convergence GOAL** : les 6 dimensions livrées avec findings 100% vérifiés + e2e réel
  exécuté par système avec captures analysées + `RAPPORT_ULTRA_REVIEW_COMPLET` écrit +
  BRAIN §2/§3 màj. Frozen-diff = 0 garanti par construction (mission read-only).
- **Advisor (Axis 8)** : un agent critic de complétude tourne en W1 et review cette
  décomposition AVANT le lancement des vagues 2-5 ; ses manques détectés sont intégrés.

## §1 Map des systèmes + anchors (vérifiés 2026-07-02 via find/ls — sortie réelle non vide)

| # | Système | Anchors vérifiés (échantillon) | Frozen à l'intérieur |
|---|---|---|---|
| 1 | BORNE (kiosk) | `resources/js/components/frontend/kiosk/*.vue` (18+ composants, vus) ; `app/Http/Controllers/Auth/KioskMachineLoginController.php` ; `Frontend/KioskEventController.php` ; `Admin/KioskSetupController.php`, `Admin/KioskMachineController.php` ; `app/Services/Kiosk/**` | wizard trio Vue (auditable, no-edit) |
| 2 | CAISSE (POS) | `Admin/{PosController,PosOrderController,AdminPosV4Controller,PosCategoryController,PosLoyaltyController,ComposerProfileController,ComposerStepController}.php` ; `Admin/Pos/PosReceiptPrintController.php` ; `resources/js/components/admin/pos/*.vue` (vus) ; `public/js/pos-wizard.js` (299 Ko, vu) + `public/css/pos-wizard.css` | pos-wizard.js/css + admin-pos-v4.blade.php (STRICT) ; PaymentComponent.vue ; PosV5TrancheRow.vue |
| 3 | KDS + OSS | `Admin/KdsSyncController.php` ; `Admin/OrderStatusScreenController.php` ; `resources/js/components/admin/kitchenDisplaySystem/{KdsOrderCard,KdsOrderLine,KdsV2Grid,KdsStatusBanner,KdsUndoToast,KdsHistoryDrawer}.vue` (vus) | aucun |
| 4 | WEB+APP storefront | `resources/js/components/frontend/**` hors kiosk (SYSTEM_MAP §4) ; standalone `/Users/1millnonstop/Downloads/web` + `mobile/` = hors repo, pointeurs seulement | aucun |
| 5 | CENTRAL | `app/Http/Controllers/Admin/**` (~100 contrôleurs, SYSTEM_MAP §5) hors POS/KDS ; `Admin/Fiscal/{ZReportController,XReportController}.php` (vus) | aucun |
| S | PARTAGÉ | `app/Services/Fiscal/{AuditLogService,FiscalChainValidator,FiscalSealingService,FiscalSequenceService,XReportService,ZReportCashEnrichmentService,ZReportService}.php` (ls vérifié) ; `app/Services/Pricing/{PricingService,CompositionSnapshotBuilder,TaxCalculator,DiscountCalculator,…}.php` (ls vérifié) ; bus sync (SYNC_CONTRACT) ; `Webhook/UberWebhookController.php` (vu) | Fiscal, PricingService, BranchScope, IdempotencyKeyMiddleware, OrderStateMachine |

Tests : `tests/Feature/**` très fourni (ls vérifié : Admin/, Auth/, Branch/, Cash/,
Catalog/, Composer/, …). Inventaire complet = tâche W1-11.

## §X Vagues (séquentielles ; fan-out parallèle read-only À L'INTÉRIEUR de chaque vague)

| Vague | Contenu | Artefact d'acceptance (chemin exact) |
|---|---|---|
| W0 ✅ | Pré-vol : cold-start canonique, env/daemons, anchors | ce document + `reports/ultra-review/2026-07-02/state.json` |
| W1 | **COMPRÉHENSION MAX** : 11 lecteurs parallèles (borne, caisse, kds+oss, central, web-storefront, order-core+pricing+fiscal, bus-sync, auth-sécu, impression+uber+deploy, db-schema, tests-inventory) + 1 **critic de complétude** (= advisor) | `reports/ultra-review/2026-07-02/01-structure/*.md` + `01-STRUCTURE.md` |
| W2 | **CODE REVIEW technique** par système + shared core : finders multi-dimensions (correctness, robustesse/erreurs, data-integrity, perf/N+1, architecture) → **verify adversarial par finding** | `reports/ultra-review/2026-07-02/02-CODE-REVIEW.md` (findings CONFIRMED only) |
| W3 | **SYNC REVIEW** : SYNC_CONTRACT vs code @HEAD (events/outbox/canal/payload/degradation) + **e2e sync LIVE** (commande → domain_events → soketi → KDS/OSS) | `reports/ultra-review/2026-07-02/03-SYNC.md` + preuves live |
| W4 | **SECURITY REVIEW** : authz (Sanctum abilities, Spatie, FormRequests), BranchScope/isolation, IDOR/mass-assignment/XSS, secrets, webhook HMAC Uber, idempotency, NF525 (chaîne + boot guards) + sentinelles exécutées : `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`, `FormRequestAuthzDriftSentinelTest` + `php artisan fiscal:verify-chain --all` | `reports/ultra-review/2026-07-02/04-SECURITY.md` |
| W5 | **TEST-E2E + UI/UX par système** (serveur :8766 live) : parcours réels Playwright par surface — kiosk idle→commande, POS caisse (catégorie→produit→panier→paiement), encaissement, KDS, OSS, admin login/dashboard/items — captures lues + analysées ; paires **QA-visual / RED-visual** adversaires ; heuristiques UX + a11y | `reports/ultra-review/2026-07-02/05-E2E-UIUX.md` + `captures/**` |
| W6 | **SYNTHÈSE** : rapport complet consolidé (structure + verdicts par système + findings P0..P3 vérifiés + gates owner) + BRAIN §2/§3 + memory | `reports/ultra-review/2026-07-02/RAPPORT_ULTRA_REVIEW_COMPLET_2026-07-02.md` |

**Checkpoint fin de vague** : state.json màj (vague, statut, artefacts) — reprise propre
après interrupt (manifest = state.json ; journaux workflow conservés par le harnais).
**Parallélisme** : tout est read-only code ⇒ fan-out libre à l'intérieur d'une vague ;
les vagues restent séquentielles (les artefacts de W1 nourrissent W2-W5 ; W6 dépend de tout).
**Règle anti-flake** : un finding n'entre au rapport que CONFIRMED par un adversaire
indépendant (file:line + repro). Les non-reproduits sont listés en annexe REJECTED.

## §G Owner gates (aucun ne bloque cet audit — documentés au rapport)

| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| G1 | Déploiement VPS du code actuel (les « bugs terrain » connus = ancien code VPS) | Owner physique | run `tools/deploy-vps.sh` + parité md5 bundles | rapport de déploiement + BRAIN §2 | PENDING (hors scope audit) |
| G2 | Impression physique SAGA + pont ESC/POS `127.0.0.1:9100` | Owner physique | photo ticket + pont actif | BRAIN §2 | PENDING (hors scope) |
| G3 | TPE réel caisse (fin du mode simulé) | Owner physique | terminal fonctionnel | CONSTITUTION §2 | PENDING (choix assumé) |
| G4 | Heal des findings P0/P1 du rapport | Owner (décision) | GO heal par finding | rapport W6 §verdicts | PENDING (post-rapport) |

## §R Références
`CONSTITUTION.md` · `SYSTEM_MAP.md` · `SYNC_CONTRACT.md` · `PARALLEL_PROTOCOL.md` ·
`PROJECT_BRAIN.md §2` · skills : `ultra-architect-planify` (ce doc), `test-e2e`
(convergence adversariale W5), `verify-before-report` (gate findings), `ultra-audit-profond`
(pipeline par tâche si heal ultérieur).

## §F Règle finale (DONE)
DONE = rapport complet livré avec **0 finding non vérifié surfacé**, e2e réel exécuté sur
chaque système avec captures analysées, sync/security/UI-UX couverts, BRAIN màj.
« Presque fini » ≠ fini. Partiel > faux : tout ce qui n'a pas pu être prouvé est marqué
explicitement NON-PROUVÉ avec la raison.

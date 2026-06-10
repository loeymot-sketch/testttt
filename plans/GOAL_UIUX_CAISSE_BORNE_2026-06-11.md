# GOAL — UI/UX Design System CAISSE + BORNE (toutes pages, boucle e2e-capture-heal adversariale)
— 2026-06-11 · skill `ultra-architect-planify` · advisor consulté (CONFIRM-WITH-CORRECTIONS, corrections intégrées)

---

## §0 PREAMBLE

### §0.1 Working tree (décision documentée)
- **Worktree d'exécution** : `.claude/worktrees/release-v1-2026-06-10` — branche `release/v1-2026-06-10`, HEAD départ `25cb5dac1` (UX-5 DESIGN_SYSTEM policy + Vague 0 design convergée). C'est la **spine release unique** (verdict superviseur 2026-06-10 : converger sur UNE release).
- **Serveur live** : `http://127.0.0.1:8768` (PID vérifié, cwd = ce worktree) → DB **`foodking_e2e` jetable** (`.env.e2e`) → **mutations E2E autorisées** sur :8768. ⛔ `:8766`/`pre-cloud-exec` = autre worktree, ne PAS l'utiliser pour cette mission. ⛔ Tout `php artisan` de cette mission = préfixé pour pointer `foodking_e2e` (jamais la DB par défaut — devdb footgun).
- Untracked pre-existants (`node_modules`, `reports/...`, `storage/media-library/temp/...`) : hors-scope, jamais `git add -A` (règle dure §3 CONSTITUTION).

### §0.2 Scope
- **IN** : Système A CAISSE (8 pages directes + ~9 surfaces indirectes), Système B BORNE (18 pages directes + DS kit 15 composants + overlays). UI/UX : layout, hiérarchie visuelle, palette Cayenne (#F4501E/#FFB800/#1A1A1A light-mode), i18n FR 100%, a11y axe-core, états vides/erreur/offline, boutons non-fonctionnels/mal visibles, flux complets (panier→paiement→confirmation ; commande→encaissement→historique).
- **OUT** : KDS/OSS, storefront client web, mobile/web standalone, backend logique métier (sauf si un bug UX a une root-cause backend mineure non-frozen), cloud.
- **Scope-expansion flag** : tout finding hors de ces 2 systèmes → documenté dans le rapport, PAS healé.

### §0.3 Convergence criteria (rejets durs — Axis 6)
DONE = **2 cycles consécutifs avec P0+P1=0 ET ensembles de findings identiques**, sur DB e2e resetée au même seed avant chaque cycle. Rejets automatiques : raw label visible · layout cassé · console error · frozen-diff ≠ 0 · P0 RED non traité · « presque bon ».

### §0.4 Pipeline par tâche + skills
Exécution par tâche → pipeline `ultra-audit-profond` (référence unique, non re-décrit). Boucle page-par-page adversariale → discipline `test-e2e` (équipe GStack + superviseur adversaire). Frozen-override éventuel → `lock-plan` + gate owner.

### §0.5 Garde-fous spécifiques (advisor — intégrés comme règles dures)
1. **Baseline anti-régression Vague 0** : W0 capture les 2 systèmes au HEAD départ dans `tests/captures/baseline-25cb5dac1/`. Tout heal dont le diff visuel touche une surface déjà convergée doit citer un finding-ID — sinon REJECT + revert.
2. **`docs/design/DESIGN_SYSTEM_POLICY_2026-06-10.md` = NORMATIF**. Chaque heal cite la règle policy qu'il implémente. Un finding qui CONTREDIT la policy = escalade owner (jamais heal silencieux). Le sourcing design W0 enrichit un annexe NOUVEAU (`docs/design/DESIGN_REFERENCES_2026-06-11.md`), ne réécrit JAMAIS la policy.
3. **Heal = BARRIER** : audit fan-out parallèle → dédupe/clustering par root-cause → **healer UNIQUE sérialisé** (hotspots partagés : `resources/lang/fr.json`, tokens SCSS, composants communs). Jamais 2 implémenteurs sur fichiers non-disjoints. Commit checkpoint par cluster, paths explicites.
4. **Full rebuild Mix après CHAQUE batch de heal** (Mix skippe les bundles inchangés → heal « sans effet » fantôme). Jamais Vitest + build dans le même script (race connue).
5. **Tripwire frozen après chaque batch** : `git diff --stat <start>..HEAD -- <liste frozen §7>` = 0 ligne, sinon ABORT + revert.
6. **Reset DB e2e au seed snapshot avant chaque cycle de convergence** (sinon les états empty/full mutent entre cycles → fausse non-convergence).
7. a11y = **axe-core obligatoire** (les screenshots ne voient pas nested-interactive/contrast — leçon 2026-06-04).

---

## §1 MAP PRINCIPAL (ancres vérifiées 2026-06-11 via find/grep/ls sur le worktree release-v1)

| Système | Maturité | Ancres primaires (vérifiées) | Tests existants |
|---|---|---|---|
| A. CAISSE (POS) | production-validated, Vague 0 design convergée | `Admin/{PosController,PosOrderController,AdminPosV4Controller,PosCategoryController,PosLoyaltyController}.php`, `Admin/Pos/PosReceiptPrintController.php`, `resources/js/components/admin/{pos,posOrders,cash,cashOverview,cashSessionReport,encaissement}/**` (23+9 composants), routers `{posRoutes,posOrderRoutes,cashOverviewRoutes,cashSessionReportRoutes,encaissementRoutes,historiqueRoutes}.js` | `tests/Feature/Pos/` 16 suites (PosCashTrail, SplitPaymentEndToEnd, QuoteBinding, PosLoyaltyRedeem, FritesWizardComposer…) |
| B. BORNE (Kiosk) | production-validated, light-mode mandat owner | `Frontend/KioskEventController.php`, `Auth/KioskMachineLoginController.php`, `Admin/{KioskSetupController,KioskMachineController}.php`, `resources/js/components/frontend/kiosk/**` (24 composants + `ds/` 15), router `kioskRoutes.js` (19 routes) | `tests/Feature/Kiosk/` 5 suites (KioskPaymentConfirmAmount, KioskAutoLoginGate, FinalizePromotionGuard…) |

**Frozen zones pertinentes** (canon : CLAUDE.md §7 / `memory/reference_frozen_zones.md`) :
- CAISSE strict no-touch : `public/js/pos-wizard.js` · `public/css/pos-wizard.css` · `resources/views/admin-pos-v4.blade.php`. Auditable-avec-gate : `PaymentComponent.vue`, `v5/PosV5TrancheRow.vue`.
- BORNE auditable-avec-gate : `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`.
- Règle : findings sur fichiers frozen = **documentés FROZEN-GATE dans §G**, jamais healés en autonome.

## §2 MAP SEPARATED
Aucun système standalone dans ce GOAL (mobile/web hors-scope §0.2).

---

## §3 SYSTÈME A — CAISSE (POS)

### Contract
Terminal principal staff : prise de commande (grille + wizard popup), encaissement multi-moyens (espèces/TR/carte-manuelle SumUp), tiroir, gestion commandes (en cours, encaissées, historique), fidélité, fiscal NF525 (lecture seule pour cette mission).

### Anchors (vérifiés `find`/`grep` 2026-06-11, sortie non-vide)
- Pages : `posRoutes.js` (`/admin/pos`, `/admin/pos/floorplan`), `posOrderRoutes.js` (`/admin/pos-orders`, `show/:id`, `/admin/pos-orders-tracker`), `encaissementRoutes.js:11`, `cashOverviewRoutes.js:11`, `cashSessionReportRoutes.js:10`, `historiqueRoutes.js:12`.
- Composants : `admin/pos/{PosComponent,ItemComponent,ParkedOrdersComponent,PosLoyaltyRedeemModal,PosCounterCollectModal,PosRefundModal,ReceiptComponent,PosOrdersTrackerComponent,CreateCustomerAddressComponent,FloorplanComponent,SkeletonGrid}.vue` + `v5/{PosV5Pill,PosV5Numpad,PosV5QtyStepper,PosV5TotalRow,PosV5StatChip,PosV5Card,PosV5Button,PosV5SearchInput}.vue` ; `posOrders/{PosOrderListComponent,PosOrderComponent,PosOrderShowComponent,PosOrderMapComponent,PosOrderReceiptComponent}.vue` ; `cash/PosCashDrawerSessionDialog.vue` ; `cashOverview/CashOverviewComponent.vue` ; `cashSessionReport/CashSessionReportListComponent.vue` ; `encaissement/EncaissementComponent.vue` ; `orderHistory/{HistoriqueComponent,HistoriqueListComponent}.vue`.

### Sub A1 — Page POS principale (`/admin/pos`)
**Anchors** : `PosComponent.vue`, `ItemComponent.vue`, `SkeletonGrid.vue`, `PosV5*.vue`, wizard popup `public/js/pos-wizard.js` (FROZEN strict — audit visuel read-only), `ParkedOrdersComponent.vue`, `PosLoyaltyRedeemModal.vue`, `CreateCustomerAddressComponent.vue`, `Admin/PosController.php`.
**Tasks** :
- T-A1.1 Audit visuel+flux grille produits/catégories/recherche/skeleton — palette, densité, raw labels, états vides, responsive.
  • test: (Vitest existant `resources/js/tests/` filtré pos) + visual `http://127.0.0.1:8768/admin/pos`
- T-A1.2 Audit ticket/panier latéral (qty stepper, tranche rows [frozen-audit], total row, remises) — lisibilité, hiérarchie, boutons mal visibles.
  • visual: `/admin/pos` avec commande en cours (mutation e2e OK)
- T-A1.3 Audit wizard popup composition (FROZEN strict) — capture + analyse SEULEMENT ; findings → §G.
- T-A1.4 Audit modals (parked/loyalty-redeem [LOCKED redeem cf. memory]/customer) — focus-trap, overlay, fermeture, FR.
  • test: `tests/Feature/Pos/PosLoyaltyRedeemTest.php` PASS (non-régression)
**Acceptance** : captures analysées 0 raw label / 0 console error / axe-core 0 critical ; `tests/Feature/Pos/PosMenuRuntimeAccessTest.php` + `PosLoyaltyRedeemTest.php` PASS ; pixel-guard baseline Vague 0 respecté.

### Sub A2 — Encaissement + paiement
**Anchors** : `PaymentComponent.vue` (FROZEN-gate, audit-only), `PosCounterCollectModal.vue` (NON-frozen — voie d'unification owner), `EncaissementComponent.vue` + `encaissementRoutes.js:11`, `PosRefundModal.vue`, `app/Services/PaymentService.php` (lecture).
**Tasks** :
- T-A2.1 Audit `/admin/encaissement` (liste commandes à encaisser, badges source borne/caisse, actions) — flux complet borne→encaissement sur DB e2e.
  • test: `tests/Feature/Pos/SplitPaymentEndToEndTest.php` PASS · visual `/admin/encaissement`
- T-A2.2 Audit `PosCounterCollectModal` (Espèces/TR/Carte-réf SumUp) — clavier numérique, rendu monnaie, erreurs saisie, FR money format.
  • test: `tests/Feature/Pos/PosCashTrailTest.php` PASS
- T-A2.3 Audit PaymentComponent (FROZEN-gate) — capture+analyse only ; findings → §G.
- T-A2.4 Audit refund modal + duplicata/remboursement markers (`ReceiptDuplicata/RemboursementMarker.vue`) — états, mentions légales FR.
**Acceptance** : flux encaissement e2e GREEN sur :8768 ; 0 console error ; tests ci-dessus PASS ; frozen-diff 0.

### Sub A3 — Gestion commandes + historique
**Anchors** : `posOrders/*.vue` (5), `PosOrdersTrackerComponent.vue`, `orderHistory/{HistoriqueComponent,HistoriqueListComponent}.vue`, `Admin/PosOrderController.php`, `Admin/Pos/PosReceiptPrintController.php`, `app/Services/ReceiptDataService` (rendu ticket serveur — advisor : auditer le rendu réel, pas que la modal).
**Tasks** :
- T-A3.1 Audit `/admin/pos-orders` + `show/:id` — table, filtres, pagination, badges statut/paiement, détail commande, receipt preview.
  • visual: `/admin/pos-orders` peuplé (mutations e2e)
- T-A3.2 Audit `/admin/pos-orders-tracker` (suivi temps réel) — états, polling/Echo dégradation visible proprement.
- T-A3.3 Audit `/admin/historique` (commandes encaissées) — filtres dates FR, montants FR, export éventuel.
  • test: (test TO BE CREATED si heal logique : `tests/Feature/Pos/HistoriqueUiContractTest.php`)
- T-A3.4 Audit rendu ticket serveur (preview HTML print) — Opérateur/Désignation/TVA/mentions (acquis campagne 2026-06-04, non-régression).
**Acceptance** : captures 4 surfaces analysées, 0 raw label, formats FR (€, dates) corrects ; pixel-guard OK.

### Sub A4 — Cash management
**Anchors** : `cashOverview/CashOverviewComponent.vue` (`/admin/cash-overview`), `cashSessionReport/CashSessionReportListComponent.vue` (`/admin/cash-sessions-report`), `cash/PosCashDrawerSessionDialog.vue`, `tests/Feature/Pos/CashDrawerSessionOwnershipTest.php`.
**Tasks** :
- T-A4.1 Audit `/admin/cash-overview` — cartes/KPI, lisibilité montants, états vides.
- T-A4.2 Audit `/admin/cash-sessions-report` — table sessions, ouverture/clôture, écarts.
- T-A4.3 Audit dialog ouverture/clôture tiroir (fond de caisse) — saisie, validation, FR.
  • test: `tests/Feature/Pos/CashDrawerSessionOwnershipTest.php` PASS
- T-A4.4 Audit `/admin/pos/floorplan` — V1 dine-in DÉSACTIVÉ (flag `pos.dine_in_enabled=false`) : vérifier que la surface est proprement masquée/inerte, pas cassée.
  • test: `tests/Feature/Pos/FloorplanControllerTest.php` PASS
**Acceptance** : 4 surfaces capturées+analysées, tests cités PASS, 0 console error.

---

## §4 SYSTÈME B — BORNE (Kiosk)

### Contract
Parcours client libre-service : idle → catégories → produits → wizard composition → panier → fidélité → upsell → paiement (Plan B routé comptoir) → waiting → confirmation. Light-mode 100% (mandat owner), FR, palette Cayenne, touch-first 1080×1920 portrait.

### Anchors (vérifiés)
- Router `kioskRoutes.js` : 19 routes (login, idle, categories, products/:categoryId, wizard/:itemId, cart, loyalty, upsell, payment, waiting/:orderId, confirmation, admin, cash-instruction, error/{network,menu-unavailable,product-removed,payment-refused}) + guard panier-vide `kioskRoutes.js:80`.
- Composants : 24 `Kiosk*.vue` + `ds/` 15 (`KsButton,KsCard,KsModal,KsStepper,KsChip,KsFilterChip,KsBadge,KsAllergenBadge,KsPriceLine,KsHero,KsCartBottomSheet,KsVirtualKeyboard,KsConsentModal,KsA11ySettings,KsThemeToggle`).
- ⚠️ `KsThemeToggle.vue` potentiellement en contradiction avec mandat light-mode-100% → finding ESCALADE owner (§G), pas de heal.

### Sub B1 — Entrée + catalogue
**Anchors** : `KioskLoginComponent.vue`, `KioskIdleScreenComponent.vue`, `KioskPromoCarouselComponent.vue`, `KioskCategoriesComponent.vue`, `KioskAppComponent.vue` (FROZEN-gate), `Auth/KioskMachineLoginController.php`.
**Tasks** :
- T-B1.1 Audit `/kiosk/login` + auto-login gate — branding, erreurs FR, virtual keyboard.
  • test: `tests/Feature/Kiosk/KioskAutoLoginGateTest.php` PASS
- T-B1.2 Audit `/kiosk/idle` (attract screen + promo carousel) — CTA visible, light-mode, animations.
- T-B1.3 Audit `/kiosk/categories` + `/kiosk/products/:categoryId` — images par catégorie (acquis e5067d464), grille touch, prix FR, badges allergènes/rupture.
- T-B1.4 Audit inactivity overlay + CatalogChangeToast — timing, lisibilité, reset panier.
**Acceptance** : captures 1080×1920 analysées, 0 raw label, axe-core 0 critical, light-mode constaté.

### Sub B2 — Wizard composition + DS kit
**Anchors** : `KioskWizardComponent.vue` (FROZEN-gate), `KioskPosWizardComponent.vue`, `KioskStepMenuComponent` (chunk wizard-step), `ds/*` 15 composants, `tests/Feature/Pos/FritesWizardComposerTest.php` (composer hinge).
**Tasks** :
- T-B2.1 Audit wizard bout-en-bout sur 3+ vrais produits Le Cayenne (tacos/sandwich/menu — produits DB UNIQUEMENT, jamais inventés) — étapes, stepper, choix viande/crudités/sauces, prix JAMAIS affiché par étape (policy 0-prix-sur-étape).
- T-B2.2 Audit DS kit en situ (boutons, chips, modal, price-line, allergen badge) — cohérence tokens, tailles touch ≥48px.
- T-B2.3 Audit `KsA11ySettings` + `KsVirtualKeyboard` — fonctionnels, FR, contraste AA.
- T-B2.4 `KsThemeToggle` vs mandat light-mode → documenter, ESCALADE §G.
**Acceptance** : wizard FROZEN = findings documentés only ; DS heals hors-frozen OK ; `FritesWizardComposerTest.php` PASS.

### Sub B3 — Panier + fidélité + upsell
**Anchors** : `KioskCartComponent.vue`, `ds/KsCartBottomSheet.vue`, `KioskLoyaltyComponent.vue`, `KioskUpsellComponent.vue` (FROZEN-gate), `KioskOrderSummaryComponent.vue`, guard panier-vide `kioskRoutes.js:80`.
**Tasks** :
- T-B3.1 Audit `/kiosk/cart` — édition qty, suppression, récap composition, total, panier-blanc (acquis heal 2026-06-10, non-régression).
- T-B3.2 Audit `/kiosk/loyalty` — saisie tel, points, lisibilité, skip clair.
- T-B3.3 Audit `/kiosk/upsell` (FROZEN-gate) — capture+analyse only ; findings → §G ; vérifier promo fantôme (finding W1-W3 connu).
- T-B3.4 Audit guard panier-vide (accès direct payment/loyalty/upsell URL → redirect cart propre).
**Acceptance** : flux cart→loyalty→upsell capturé+analysé, 0 console error, redirects corrects.

### Sub B4 — Paiement + post-commande + erreurs
**Anchors** : `KioskPaymentComponent.vue`, `KioskCashInstructionComponent.vue` (Plan B comptoir), `KioskWaitingComponent.vue`, `KioskConfirmationComponent.vue`, `KioskErrorLayoutComponent.vue` + 4 erreurs, `KioskOfflineConflictModalComponent.vue`, `KioskToastComponent.vue`, `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php`.
**Tasks** :
- T-B4.1 Audit `/kiosk/payment` + Plan B `cash-instruction` (routage comptoir) — clarté instruction client, numéro commande énorme/lisible.
  • test: `tests/Feature/Kiosk/KioskPaymentConfirmAmountTest.php` PASS
- T-B4.2 Audit `waiting/:orderId` + `confirmation` — n° commande, temps estimé, reset auto vers idle.
- T-B4.3 Audit les 4 pages d'erreur + offline conflict modal + toasts — déclenchables (simuler), FR, action de sortie claire.
- T-B4.4 Flux mutation e2e complet borne : idle→…→confirmation sur :8768 (DB e2e), puis la commande apparaît à `/admin/encaissement` (lien W5).
**Acceptance** : flux complet GREEN, 4 erreurs capturées, `PaymentReconcileTest.php` + `KioskPaymentConfirmAmountTest.php` PASS.

---

## §A AGENT ARMY MAP

Rôles (cf. fan-out matrix du skill — frontend visual = Architect/Security/UX-A11y/Implementer/RED/QA-Vis/RED-Vis) :

| Rôle | Type | Mission |
|---|---|---|
| Scout-page (×N parallèles) | general-purpose | 1 agent par CLUSTER de pages : met en état (navigue, peuple), capture Playwright, analyse visuelle (Read screenshot), liste findings P0-P3 schéma Axis 4, persiste `reports/test-e2e/uiux-caisse-borne-2026-06-11/<round>/<cluster>.md` |
| Dim-i18n | general-purpose | Sweep transversal raw labels + FR (grep `Label\.|kiosk\.|0undefined` + revue captures) — calibrage sévérité unique |
| Dim-a11y | general-purpose | axe-core sur chaque page (browser_run_code), contrast/nested-interactive/button-name |
| Dim-console | general-purpose | console + network cleanliness par page |
| Synthesizer | moi (main) | dédupe + clustering root-cause + plan de heal par cluster |
| Healer UNIQUE | general-purpose (Edit/Bash) | sérialisé, 1 cluster à la fois, commit checkpoint paths explicites, cite la règle policy |
| RED-team / Adversaire | general-purpose read-only | dispute chaque batch healé : re-capture indépendante, cherche défauts cachés, REJECT si « presque » |
| QA-Vis + RED-Vis | parallèles | re-analyse indépendante des mêmes captures post-heal |

Dispatch : scouts + dimensions = fan-out parallèle (1 message). Healer = JAMAIS parallèle. Adversaire = après chaque batch, avant clôture de vague. Rapports persistés disque (survit aux interruptions). Cap ~1200 mots/agent.

**Clusters de capture** (setup d'état partagé) :
- C1 : /admin/pos + ticket + modals (état : session caisse ouverte + commande en cours)
- C2 : /admin/encaissement + counter-collect + refund (état : commandes borne+caisse pending)
- C3 : /admin/pos-orders + show + tracker + historique (état : commandes payées)
- C4 : /admin/cash-overview + cash-sessions-report + drawer dialog + floorplan
- C5 : kiosk login→idle→categories→products (+inactivity)
- C6 : kiosk wizard 3 produits + DS kit
- C7 : kiosk cart→loyalty→upsell
- C8 : kiosk payment→cash-instruction→waiting→confirmation + 4 erreurs

## §X CONVERGENCE WAVES

| W | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| **W0 Préflight** | Branche backup `backup/pre-uiux-2026-06-11` ; vérif :8768 up + DB e2e + seed snapshot (`mysqldump foodking_e2e` → restore point) ; baseline Vitest count ; **baselines screenshots 2 SYSTÈMES** `tests/captures/baseline-25cb5dac1/` ; sourcing design refs 2024-26 (web-research → `docs/design/DESIGN_REFERENCES_2026-06-11.md`, annexe NOUVEAU) | sourcing ∥ baselines | backup OK + baselines lues + refs écrites |
| **W1 Audit CAISSE** | Clusters C1-C4 scouts ∥ + 3 dimensions ∥ | fan-out read-only total | rapports 7 agents sur disque, synthèse clusters root-cause |
| **W2 Heal CAISSE** | Healer unique par cluster → rebuild Mix full → re-capture → QA-Vis+RED-Vis ∥ → adversaire | heal sérialisé, vérif ∥ | tripwire frozen 0 · Vitest PASS · adversaire 0 P0/P1 nouveaux · commits checkpoint |
| **W3 Audit BORNE** | C5-C8 scouts ∥ + 3 dimensions ∥ (APRÈS W2 : heals tokens/fr.json déjà posés) | fan-out read-only | idem W1 |
| **W4 Heal BORNE** | idem W2 (healer unique) | heal sérialisé | idem W2 |
| **W5 Cross-flow E2E** | Mutation :8768 — borne A→Z puis encaissement caisse → historique ; caisse directe → paiement → pos-orders ; vérif badges/sync visibles | 2 flux séquentiels | flux GREEN + captures analysées |
| **W6 Convergence finale** | Reset DB seed → cycle complet capture 2 systèmes → adversaire ; re-reset → 2e cycle ; comparaison ensembles findings | cycles séquentiels | **2 cycles P0+P1=0, findings identiques** · frozen-diff 0 global · Vitest full · BRAIN §2/§3 |

**Interrupt-resume** : à toute interruption → commit WIP `wip(W<n>)`, manifeste `reports/test-e2e/uiux-caisse-borne-2026-06-11/INTERRUPT_W<n>_<ts>.md` (dernier commit vert, tâche en cours, file d'attente), BRAIN §2. Reprise = lire manifeste + smoke dernière tâche.
**Convergence-failure** : 3e heal-loop raté sur même cluster → STOP, agent Plan root-cause, `STUCK_W<n>.md`, choix owner A/B/C/D.

## §G OWNER GATES

| Gate | Description | WHO | WHAT | WHERE | Status |
|---|---|---|---|---|---|
| G1 | Findings UI sur `pos-wizard.js`/`admin-pos-v4.blade.php` (strict no-touch) | Owner | LOCK doc contresigné si heal voulu | `plans/LOCK_*.md` + rapport §FROZEN | PENDING (collecte) |
| G2 | Findings sur `PaymentComponent.vue`/`PosV5TrancheRow.vue`/kiosk frozen ×3 | Owner | LOCK + gate par fichier | idem | PENDING (collecte) |
| G3 | `KsThemeToggle` vs mandat light-mode 100% | Owner | décision garder/retirer | rapport final §ESCALATIONS | PENDING |
| G4 | Push de la branche release | Owner | ordre push explicite (§3quater) | — | PENDING |

Protocole : gates ne bloquent AUCUNE vague W0-W6 (tout le reste est non-frozen) ; les findings frozen s'accumulent dans le rapport final.

## §R RÉFÉRENCES
`docs/design/DESIGN_SYSTEM_POLICY_2026-06-10.md` (normatif) · `docs/DESIGN_BRIEF_KIOSK_2026.md` · `docs/design/{POS,KIOSK}_DESIGN_VALIDATION_2026-04-27.md` · skills `ultra-audit-profond`/`test-e2e`/`lock-plan` · `PROJECT_BRAIN.md §2` · CLAUDE.md §6-§8.

## §F FINAL RULE
DONE = production-perfect : 2 cycles adversariaux consécutifs P0+P1=0 findings identiques, frozen-diff 0, Vitest full PASS, baselines Vague 0 non régressées, BRAIN mis à jour, rapport final + gates owner listées. Pas de « presque ».

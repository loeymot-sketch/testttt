# GOAL — FINISH TEST-E2E ACROSS ALL SYSTEMS (max depth + breadth) — 2026-06-06

**Mission (owner, voice):** « terminer les test-e2e pour toutes les systèmes avec analyse + compositions profondes — tester **chaque fonctionnalité dans chaque système** ; **chaque agent vise UN système exact, petit système, avec toute sa Memory + tout son contexte**, pour qu'on assimile chacun en profondeur ; **maximum profondeur ET largeur** ; prépare le GOAL d'abord — je te dirai quand lancer. »

> **STATUT : PREPARE-ONLY. Aucun agent d'exécution n'est lancé. L'owner est la porte de lancement (« après je te dirai quand »).** Ce document est le *spine* d'orchestration ; il se lit en < 15 min et se lance d'un mot.

---

## §0 — PRÉAMBULE (cadre + choix de conception)

### 0.1 — LAUNCH DIALS (l'owner règle ceci AVANT le « go » ; défauts = mon choix de superviseur)
| Dial | Question | DÉFAUT (mon choix) | Alternative | Pourquoi ça compte |
|---|---|---|---|---|
| **DIAL-1 System-A** | WEB + APP standalone : profondeur ? | **LIGHT** — regression-confirm + gap-fill au mock-boundary (ils ont déjà convergé 2026-06-06) ; profondeur concentrée sur les 4 systèmes opérationnels centraux | FULL-DEPTH (re-décomposer web+mobile comme les autres) | « toutes les systèmes » (lecture littérale = inclut WEB+APP) **entre en tension** avec le mandat permanent owner « site+app DÉJÀ FINIS, SÉPARÉS, bosse QUE caisse/dashboards/KDS ». Je n'abandonne pas System-A en silence et je ne le sur-construis pas en silence — **tu tranches au lancement.** |
| **DIAL-2 Env E2E** | Contre quelle surface ? | **LOCAL :8000** (mandat surface-de-validation CLAUDE.md §6 ; `php artisan serve` auto) | + smoke parité **cloud OVH** | No-cloud sans ordre explicite (CONSTITUTION §3.3). Le cloud est déployé mais la surface de vérité reste locale. |
| **DIAL-3 Parallélisme** | Agressivité ? | **Voies disjointes en parallèle** (SYSTEM_MAP garantit 0 collision hors §6) | Séquentiel strict | Les 5 voies sont structurellement disjointes → parallèle sûr. §6 partagé = sérialisé. |
| **DIAL-4 Plafond profondeur** | Jusqu'où ? | **« chaque route/état atteignable depuis les anchors + abus + visuel analysé »** | Plafonner (happy-path only) | « max profondeur » = défaut exhaustif. Tu peux plafonner si tu veux un cycle plus court. |

### 0.2 — CHOIX DE CONCEPTION CENTRAL (résout « max profondeur » vs plan exécutable)
Le GOAL est un **spine**, pas un dépôt de profondeur. Chaque unité nomme : **anchors vérifiés + spec canonique (chemin) + en-têtes de checklist fonctionnelle**. L'**énumération exhaustive flux-par-flux est DÉLÉGUÉE à l'agent de l'unité au moment de l'exécution**, via son **CONTEXT-PACK** (§A). C'est ainsi qu'on obtient « max profondeur » (chaque agent va au fond de SON petit système) SANS plan ingérable (> 45 KB = historiquement non-exécuté). **Le context-pack-par-unité (§A) est l'artefact-cœur de ce GOAL, pas le diagramme de vagues.**

### 0.3 — RÈGLE « PETIT SYSTÈME » (calibrage owner « petit système, assimiler en profondeur »)
Une unité = ce qu'**UN agent peut couvrir EXHAUSTIVEMENT dans UN seul contexte**. Test dur : si l'agent ne peut pas énumérer+tester toutes les fonctionnalités de l'unité sans déborder son contexte → **scinder encore**. 25 unités ci-dessous = première découpe ; un agent qui sature SCINDE et le signale (mini-decompose).

### 0.4 — PIPELINE PAR TÂCHE (ne pas redécrire)
Chaque unité s'exécute via le skill **`test-e2e`** (boucle visuelle double-agent QA+RED) + **`ultra-audit-profond`** (pipeline 14 étapes). Le GOAL ne réécrit pas ces pipelines.

### 0.5 — CONVERGENCE (critère de rejet, par unité — emprunté à `test-e2e`)
Unité DONE **uniquement si** : **2 cycles consécutifs avec P0+P1 = 0 ET jeux de findings IDENTIQUES** (garde anti-flake) ; **chaque screenshot Read + analysé** (pas juste capturé) ; 0 raw label / 0 layout-break / 0 console-error ; frozen-diff = 0. Sinon → heal (max 3) → escalate.

### 0.6 — DISPOSITION SURFACE FROZEN (règle dure — évite de gâcher 3 heals contre un mur)
E2E **run/lecture** contre une surface frozen (pos-wizard, PaymentComponent, PosV5TrancheRow, wizard kiosk) = **AUTORISÉ**. Mais un **défaut réel trouvé sur une surface frozen NE PEUT PAS converger par heal** → **DOCUMENTER + OWNER GATE (LOCK), NE PAS boucler.** L'agent écrit le finding + repro + fix-proposé et s'arrête sur cette surface.

### 0.7 — TRAITEMENT DES ~300 SPECS LEGACY (anti-débris / anti-flake)
« Terminer les E2E » ≠ empiler des specs sur un tas non-maintenu. Les `tests/e2e/_*-YYYY-MM-DD.spec.js` (one-offs datés) = **référence seule, supersédés**. Chaque unité **établit/possède UNE spec canonique profonde** : `tests/e2e/canonical/<système>/<unité>.spec.js`. L'agent réutilise la logique utile des one-offs, puis la spec canonique est la SSOT de l'unité. (`(test À CRÉER)` marqué partout où elle n'existe pas encore.)

### 0.8 — WORKING TREE
Worktree `pre-cloud-exec`, branche `heal/pre-cloud-exec-2026-06-05`. Pas de push (gate humain). E2E local :8000. Specs canoniques = nouveaux fichiers (hors frozen) → commit autorisé scope-minimal par unité.

---

## §1 — MAP PRINCIPAL : les 5 systèmes (anchors VÉRIFIÉS · E2E existant compté)
Source : `CONSTITUTION.md §4` + `SYSTEM_MAP.md` (voies disjointes, grep-vérifiées). E2E existant = `find tests/e2e -iname` ce jour.

| # | Système | Maturité | Anchors-clés (vérifiés) | E2E existant |
|---|---|---|---|---|
| 1 | **BORNE (kiosk)** | mûr | `components/frontend/kiosk/**`, `kioskRoutes.js`, `kioskCart.js`+`kioskOfflineQueue.js`, ctrl `KioskMachineLoginController`, `app/Services/Kiosk/**` | kiosk 46 + borne 7 |
| 2 | **CAISSE (POS)** | mûr, le + critique | `components/admin/{pos,posOrders,cash,cashOverview,cashSessionReport,encaissement}/**`, `PosController`/`PosOrderController`/`CashDrawerSessionController`, `PaymentService`/`SplitPaymentService`/`CashDrawerService` | pos 56 + cash 7 + caisse 5 + payment 3 |
| 3 | **KDS + OSS** | mûr | `components/admin/{kitchenDisplaySystem,orderStatusScreen}/**`, `KitchenDisplaySystemController`+`KdsSyncController`+`OrderStatusScreenController`, `OssSyncService.js`, `KdsOrderRecalled` | kds 47 + oss 21 + status 2 |
| 4 | **WEB + APP** (standalone) | convergé 2026-06-06 (mock-boundary) | `/Users/1millnonstop/Downloads/web/**`, `mobile/**`, `components/frontend/**` (≠kiosk) | menu 6 + (specs web/mobile dans leurs repos) |
| 5 | **CENTRAL** | mûr | `components/admin/**` (≠POS/KDS dirs) : dashboard/items/stock/reports/settings/users, `DashboardController`+`StockRuptureDashboardController`+`ItemController`+reports | dashboard 8 + stock 11 + admin 13 + item 2 + loyalty 1 |
| §6 | **SHARED / CROSS-SURFACE** | transverse | sync bus (`OrderCreated`/`OrderStatusChanged`/`KdsOrderRecalled`, `branch.{id}`, soketi, `WebSocketService.js`), NF525 chain, `OrderService`/`FrontendOrderService`/`OrderStateMachine`, BranchScope/Idempotency | sync 21 + offline 1 + fiscal 2 |

**Lecture-clé :** l'E2E est lourd sur kiosk/pos/kds/oss/sync mais **mince exactement là où le risque récent vit** : loyalty (1), offline-résilience (1), payment (3), fiscal (2), item (2), status (2). « Finir l'E2E en profondeur » a le plus à ajouter là.

---

## §2 — SÉPARATION (rappel dur)
WEB + APP = **System A, standalone, NO API wireup V1**. Ce GOAL ne les câble PAS au backend (DIAL-1 light par défaut). Palette mobile = NOIR/ORANGE/JAUNE/BLANC (≠ Cayenne red). Storefront client servi par le backend (`frontend/{home,menu,account,checkout}`) = même voie WEB+APP, distinct du kiosk.

---

## §3 — UNITÉS · BORNE (kiosk) — 4 unités
> Format unité : Anchors · Surface live · Spec canonique (supersède) · Checklist (EN-TÊTES — l'agent énumère chacune exhaustivement) · Known-weak · Convergence.

#### U-B1 — Kiosk idle / attract / session-start
- Anchors : `KioskAppComponent.vue` (FROZEN run-only), `kioskRoutes.js`, `KioskMachineLoginController.php`, route `/auth/kiosk-login` (`api.php:167`)
- Surface : `http://127.0.0.1:8000/kiosk/idle`
- Spec canonique : `tests/e2e/canonical/borne/b1-idle-session.spec.js` (supersède `_wave-*-kiosk-nowizard-*`, `_deep-s1-kiosk-*`)
- Checklist (headers) : 1. idle/attract render + branding + i18n FR · 2. touch-to-start → session token (`kiosk:order`, TTL) · 3. machine-login bind `KioskMachine` row · 4. re-idle timeout/reset · 5. a11y/touch-targets + 0 raw label
- Known-weak : —
- Convergence : §0.5 ; frozen (KioskApp) → défaut = §0.6 gate.

#### U-B2 — Kiosk wizard composition (FROZEN run-only)
- Anchors : `KioskWizardComponent.vue` (FROZEN), `app/Services/Kiosk/PricingPreviewService.php`, `kdsCustomization` mirror
- Surface : `/kiosk/idle` → start → wizard
- Spec canonique : `tests/e2e/canonical/borne/b2-wizard-composition.spec.js` (supersède `_wave-c-z3-wizard-sandwich-tacos-*`, `_bug-kiosk-valider-wizard-*`)
- Checklist : 1. 4 templates (sandwich/tacos/bols/menu-formule) compo complète · 2. options/variations/suppléments + prix preview backend (PricingService SSOT) · 3. allergènes modal · 4. validation par étape · 5. 0 raw label / overflow
- Known-weak : composition snapshot figé (NF525) — vérifier non-réécrit
- Convergence : frozen → défaut = §0.6 gate.

#### U-B3 — Kiosk cart + upsell + OFFLINE queue
- Anchors : `kioskCart.js`, `kioskOfflineQueue.js`, `KioskUpsellComponent.vue` (FROZEN)
- Spec canonique : `tests/e2e/canonical/borne/b3-cart-upsell-offline.spec.js`
- Checklist : 1. add/edit/remove cart + recap groupé · 2. upsell rules déclenchement · 3. **offline : coupure réseau → cart persiste → resync** · 4. quote binding
- Known-weak : offline kiosk peu couvert E2E
- Convergence : §0.5.

#### U-B4 — Kiosk submit → confirmation → Plan-B counter routing
- Anchors : `FrontendOrderService.php` (§6 shared), `config/kiosk.php payment_route_all_to_counter`, `OrderCreated` event
- Spec canonique : `tests/e2e/canonical/borne/b4-submit-confirm-routing.spec.js` (supersède `_bug-kiosk-valider-*`)
- Checklist : 1. submit → fiscal alloc (kiosk paid path) · 2. confirmation screen + numéro · 3. **Plan-B : routage encaissement comptoir** · 4. `OrderCreated` publié → KDS (lien cross-surface S1) · 5. idempotence double-submit
- Convergence : §0.5 ; touche §6 (FrontendOrderService) = pas d'édition, E2E seulement.

---

## §4 — UNITÉS · CAISSE (POS) — 5 unités  ⭐ système le + critique
> **Folder Wave-1 audit (`wfa1r9zqm`, en cours)** : ses findings confirmés s'injectent dans « Known-weak » des unités C1–C5 à l'exécution.

#### U-C1 — POS order-taking + wizard (wizard = FROZEN strict run-only)
- Anchors : `PosComponent.vue` (NON-frozen, fixable), `public/js/pos-wizard.js`+`admin-pos-v4.blade.php` (FROZEN STRICT), `PosController`
- Surface : `http://127.0.0.1:8000/admin/pos`
- Spec canonique : `tests/e2e/canonical/caisse/c1-order-taking.spec.js` (supersède `_deep-s2-pos-*`, `_red-a-pos-round3-*`, `_pageby-pos-*`)
- Checklist : 1. types commande sur-place/emporter/comptoir · 2. plan de salle (si `dine_in` flag) · 3. catalogue/catégories/options nav · 4. **ergonomie tactile** : tap-targets ≥44px, 0 double-couche/flou/doublon, contraste · 5. add-to-cart + parked resume
- Known-weak (Wave-1 audit, voir `reports/test-e2e/all-systems-2026-06-06/WAVE1_POS_AUDIT_FINDINGS.md`) : **POS-ERG-01** grille occultée par cart @1024×768 (15/45 tuiles), **POS-ERG-02** operator-bar brand→0px + nav overlap, **POS-ERG-04** steppers 22px<44, **POS-ERG-05** segmented 40px/discount 34-36px<44 (tous `PosComponent.vue`/`pos-v5.css` non-frozen, fixables) ; **POS-ERG-07** wizard en-US currency = FROZEN `pos-wizard.js:218` → **G-FROZEN gate, flag-only**
- Convergence : wizard frozen → défaut = §0.6 gate ; PosComponent défaut = heal OK.

#### U-C2 — POS paiement / encaissement (PaymentComponent = FROZEN run-only)
- Anchors : `components/admin/encaissement/**`, `PosCounterCollectModal` (NON-frozen, c'est le point d'unification owner), `PaymentComponent.vue`+`PosV5TrancheRow.vue` (FROZEN), `PaymentService`/`SplitPaymentService`
- Spec canonique : `tests/e2e/canonical/caisse/c2-encaissement.spec.js` (supersède payment specs)
- Checklist : 1. espèces (rendu monnaie, pas de sur-comptage tiroir) · 2. CB / TR (réf-only, simulé) · 3. split tenders + Σ==total · 4. pourboire · 5. encaissement comptoir unifié (borne≠caisse → 1 système) · 6. garde fiscale (alloc à PAID)
- Known-weak : encaissement unification (memory feedback 2026-06-05) ; TPE simulé = §2 boundary (PAS un défaut) ; **POS-ERG-03** (Wave-1) cart/total/CTA en-US `0.90€` vs catalogue FR `0,90 €` (`PosComponent.vue:2093` non-frozen, formatter POS-local) ; **OFF-02** double cash-line sur replay offline (cf. U-S3)
- Convergence : PaymentComponent frozen → §0.6 gate.

#### U-C3 — POS cash management (PIN / rôles / open-close / fond / comptage)
- Anchors : `components/admin/{cash,cashOverview}/**`, `CashDrawerService.php`, `Admin/Pos/CashDrawerSessionController.php`, `CashDrawerSession.php`
- Spec canonique : `tests/e2e/canonical/caisse/c3-cash-management.spec.js` (supersède cash specs)
- Checklist : 1. ouverture + fond de caisse · 2. multi-utilisateur + attribution caissier · 3. rôles + PIN sur ops sensibles · 4. mouvements (no-sale, in/out) · 5. clôture + comptage/écart · 6. NF525 cash-trail (simulation = matériel only)
- Known-weak (Wave-1) : **CASH-01** cash-OUT skips (refund/cashback sans session) indétectables → sur-comptage caisse invisible EOD (`RefundWithCounterEntryService.php:280`/`CashOverviewController.php:308`, non-frozen) ; **CASH-03** no-sale sans session = `Log::warning` seul, pas de `audit_logs` row (brise 'Action tracée' sur vrai matériel) ; ⚠️ **PIN/rôles = FEATURE-SCOPE owner (G-PIN)** — l'audit a *réfuté* « no PIN » comme NON-défaut V1 (mono-opérateur, `permission:pos` gate), MAIS ton brief Mission-2 demande explicitement PIN+rôles → décision owner, pas un heal
- Convergence : §0.5.

#### U-C4 — POS Z-report / fiscal ops (chaîne NF525 = run-only)
- Anchors : `components/admin/cashSessionReport/**`, `ZReportService` (FROZEN run-only), `fiscal:verify-chain`, safety-net cron (ZOpen/ZClose corrigés Mission-1)
- Spec canonique : `tests/e2e/canonical/caisse/c4-zreport-fiscal.spec.js` (supersède fiscal specs)
- Checklist : 1. clôture Z (fenêtre half-open, PAID+sequenced only) · 2. séquence monotone gap-free · 3. chaîne HMAC re-vérifiée open/close · 4. refund-netted TVA · 5. immutabilité post-Z (409) · 6. **attestation chaîne before==after** (`fiscal:verify-chain --all`)
- Known-weak : split-bucketing (memory : M6-002/S13-02 frozen)
- Convergence : frozen Fiscal → défaut = §0.6 gate ; chaîne change inattendu = escalate humain immédiat.

#### U-C5 — POS orders mgmt + recall + refund
- Anchors : `components/admin/posOrders/**`, `PosOrderController`, refund counter-entry path
- Spec canonique : `tests/e2e/canonical/caisse/c5-orders-recall-refund.spec.js` (supersède `_red-a-pos-*`)
- Checklist : 1. liste/filtre commandes · 2. parked → recall · 3. refund (counter-entry, no double-book, UNIQUE) · 4. annulation (void un-collected, no seq) · 5. warnings recall
- Convergence : §0.5.

---

## §5 — UNITÉS · KDS + OSS — 4 unités

#### U-K1 — KDS réception + grille + routage par poste
- Anchors : `KitchenDisplaySystemComponent.vue` (Echo re-fetch ~1925-1953), `KdsV2Grid.vue`, `KitchenDisplaySystemController.php`
- Surface : `http://127.0.0.1:8000/kds`
- Spec canonique : `tests/e2e/canonical/kds/k1-reception-routing.spec.js` (supersède `_le-cayenne-v2-pos-kds-*`)
- Checklist : 1. réception temps-réel nouvelle commande · 2. **routage par poste (chaud/froid/bar/dessert)** · 3. grille V2 layout 4-col · 4. **ergonomie cuisine** : lisibilité distance, contraste fort, tap-targets · 5. 0 raw label
- Known-weak (Wave-1) : ⚠️ **l'agent-audit KDS a TIMEOUT — pas d'audit UI/bump/routage préalable** → cette unité part SANS filet, audite à fond. Sync partiel trouvé : **KDS-02** poller fallback s'ARRÊTE sur non-5xx (401/403/404/429) → cuisine perd son filet de dégradation en silence (`KdsSyncService.js:372`) ; **KDS-03** KDS sous compte admin (branch≤0) = 0 push live + banner caché (`KitchenDisplaySystemComponent.vue:1911`)
- Convergence : §0.5.

#### U-K2 — KDS bump lifecycle + recall + undo + alertes retard
- Anchors : `KdsOrderCard.vue`, `KdsOrderLine.vue`, `KdsUndoToast.vue`, `KdsStatusBanner.vue`, `changeStatus`/`recall`, `KdsOrderRecalled` event
- Spec canonique : `tests/e2e/canonical/kds/k2-bump-recall-delay.spec.js` (supersède `_red-c-oss-*` partie KDS)
- Checklist : 1. bump nouveau→préparation→prêt→servi · 2. undo toast · 3. recall (`KdsOrderRecalled`→outbox→OSS) · 4. **alertes retard (seuil + visuel)** · 5. multi-bump concurrent
- Known-weak : ⚠️ **audit KDS UI a timeout (cf. U-K1)** — audite bump/recall/alertes-retard à fond sans filet préalable
- Convergence : §0.5.

#### U-K3 — KDS history + regroupement par table
- Anchors : `KdsHistoryDrawer.vue`, `kdsCustomization.js`
- Spec canonique : `tests/e2e/canonical/kds/k3-history-grouping.spec.js`
- Checklist : 1. history drawer · 2. regroupement par table · 3. customization render par type (sandwich/taco/bol/menu) · 4. filtre/recherche
- Convergence : §0.5.

#### U-K4 — OSS écran statut client
- Anchors : `components/admin/orderStatusScreen/**`, `PreparingAndReadyComponent.vue`, `OssSyncService.js`, `OrderStatusScreenController.php`, feed public `GET /api/frontend/oss-order`
- Surface : `http://127.0.0.1:8000/admin/order-status-screen`
- Spec canonique : `tests/e2e/canonical/kds/k4-oss-status.spec.js` (supersède `_red-c-oss-*`, `_pos-first-page-and-oss-*`)
- Checklist : 1. colonnes preparing/ready · 2. cadence poll OssSyncService (mur 5s — cf. SYNC_CONTRACT) · 3. transition auto preparing→ready · 4. branding + lisibilité distance · 5. dégradation si ws down
- Known-weak (Wave-1) : **DOC-01** SYNC_CONTRACT.md §5/§7 stale — OSS wall réellement **5s (pas ~60s)**, KDS banner **fail-safe-to-visible** (pas suppress-en-local) → maj doc ; **KDS-03** OSS/KDS sous compte admin = 0 push
- Convergence : §0.5.

---

## §6 — UNITÉS · WEB + APP (DIAL-1 = LIGHT par défaut) — 3 unités
> Si DIAL-1 = FULL-DEPTH, ces 3 unités se re-décomposent comme les autres. Par défaut = regression-confirm + gap-fill.

#### U-W1 — Web standalone (regression-confirm)
- Anchors : `/Users/1millnonstop/Downloads/web/**`, `data/menu.js` (mirror canonique)
- Spec : specs du repo web standalone (leur propre apparatus) + capture parité
- Checklist : 1. menu 45 items parité · 2. wizard 4 templates · 3. cart/checkout mock-boundary · 4. a11y 0 crit/0 serious (axe-core) · 5. visual @390px
- Convergence : confirm dernier état convergé (memory `project_goal_system_a_web_mobile`), gap-fill si régression.

#### U-W2 — Mobile standalone (regression-confirm)
- Anchors : `mobile/**`, `mobile/data/menu.js`
- Checklist : 1. parité menu/wizard/cart · 2. palette NOIR/ORANGE/JAUNE/BLANC · 3. a11y · 4. loyalty 10pt/€ (gate-D1 owner)
- Convergence : confirm convergé ; loyalty ratio = owner gate G-LOY.

#### U-W3 — Backend customer storefront + order tracker
- Anchors : `components/frontend/{home,menu,account,checkout}/**`, `customerRoutes.js`, order tracker subscribe `branch.{id}`
- Surface : `http://127.0.0.1:8000/` (storefront client)
- Spec canonique : `tests/e2e/canonical/web/w3-storefront-tracker.spec.js`
- Checklist : 1. home/menu render · 2. checkout flow · 3. **order tracker temps-réel** (subscribe branch, statut live) · 4. account/auth client (Sanctum customer token)
- Convergence : §0.5.

---

## §7 — UNITÉS · CENTRAL — 5 unités

#### U-CE1 — Dashboard temps-réel
- Anchors : `DashboardController.php`, `components/admin/dashboard/**` (Overview/OrderStatistics/SalesSummary/StockLowAlerts/LastZReportWidget/AuditTrail), `store/modules/dashboard.js`
- Surface : `http://127.0.0.1:8000/admin`
- Spec canonique : `tests/e2e/canonical/central/ce1-dashboard-realtime.spec.js` (supersède dashboard specs)
- Checklist : 1. **stats live-update sur nouvelle commande (vs stale-jusqu'à-reload)** · 2. ventes/CA agrégats corrects · 3. LastZReport widget · 4. low-stock alerts · 5. BranchScope sur agrégats (0 fuite/double-compte) · 6. empty/error states
- Known-weak (Wave-1) : **DASH-04** (P2) widgets SLA/low-stock affichent 'tout va bien' sur échec API (faux-négatif) → état 'Données indisponibles' ; **DASH-01** KPIs Overview/Channel/OrderStats/SalesSummary fetch-once-on-mount, stale jusqu'à reload (les siblings pollent 15-30s) ; **DASH-03** CustomerStats/TopCustomers orphelins (backend calcule, rien ne rend) ; **DASH-05** SLA cuisine>15min via `updated_at` proxy (masque retards) ; **DASH-06** 'Total articles menu' compte inactifs → drift 45-SSOT
- Convergence : §0.5.

#### U-CE2 — Catalogue mgmt (items/catégories/options/ingrédients)
- Anchors : `ItemController`, CatalogStudio, `components/admin/{items,ingredients,coupons,offers}/**`
- Surface : `http://127.0.0.1:8000/admin/items`
- Spec canonique : `tests/e2e/canonical/central/ce2-catalogue.spec.js`
- Checklist : 1. CRUD item (45 SSOT — JAMAIS inventer) · 2. catégories/options/variations · 3. ingrédients/allergènes · 4. coupons/offers · 5. images Spatie · 6. prix → PricingService SSOT
- Known-weak : SSOT 45 items (anti-drift) ; multi-variation (report data-repair)
- Convergence : §0.5.

#### U-CE3 — Stock / inventaire + rupture dashboard
- Anchors : `StockRuptureDashboardController.php`, `components/admin/stock/**`, `AvailabilityService`, `ItemBranchAvailability`
- Surface : `http://127.0.0.1:8000/admin/stock-rupture-dashboard`
- Spec canonique : `tests/e2e/canonical/central/ce3-stock-rupture.spec.js` (supersède stock specs)
- Checklist : 1. niveaux stock · 2. rupture auto-86 + preventive cron · 3. dashboard rupture 5-7j · 4. mouvements stock idempotents · 5. low-stock → dashboard alert (lien CE1)
- Convergence : §0.5.

#### U-CE4 — Reports + history + exports
- Anchors : `SalesReportController`/`ItemsReportController`/`AnalyticController`, `OrderHistoryController`, `components/admin/{salesReport,itemsReport,orderHistory,transactions}/**`
- Spec canonique : `tests/e2e/canonical/central/ce4-reports-exports.spec.js`
- Checklist : 1. sales report (période, totaux) · 2. items report · 3. order history filtre · 4. transactions · 5. exports (format, contenu) · 6. BranchScope
- Convergence : §0.5.

#### U-CE5 — Settings + users + RBAC + loyalty admin
- Anchors : Settings cluster (~26 ctrl), users (Administrator/Employee/Chef/Waiter/Customer/DeliveryBoy), Spatie permissions, `PosLoyaltyController` (loyalty Mission-1)
- Spec canonique : `tests/e2e/canonical/central/ce5-settings-users-rbac.spec.js` (supersède admin/loyalty specs)
- Checklist : 1. settings CRUD · 2. users CRUD + rôles Spatie · 3. **RBAC `permission:settings` gating** (FormRequest authz) · 4. **loyalty caisse phone-keyed** (Mission-1 fix `d2b244df5` : status=5 mint+accrual, PAS sur ticket, optionnel) · 5. employés/chefs/waiters
- Known-weak : loyalty E2E = 1 spec seulement (mince) → c'est ici qu'on approfondit ; FormRequest authz drift (sentinel baseline)
- Convergence : §0.5.

---

## §8 — UNITÉS · SHARED / CROSS-SURFACE (transverse — §6, jamais édité, E2E seulement) — 4 unités
> Ces unités testent les CONTRATS partagés en bout-en-bout. Elles ne MODIFIENT aucun fichier §6 (lock+gate) — elles VALIDENT le comportement cross-surface.

#### U-S1 — Cycle de vie commande cross-surface (le flux quotidien complet)
- Anchors : `OrderService`/`FrontendOrderService`/`OrderStateMachine` (§6), `branch.{id}` channel
- Spec canonique : `tests/e2e/canonical/shared/s1-order-lifecycle-e2e.spec.js` (supersède `_debug-pos-kiosk-kds-oss-*`)
- Checklist : 1. **borne commande → KDS reçoit → OSS affiche → caisse encaisse → Z clôture** (le but quotidien CONSTITUTION §1) · 2. transitions état légales only · 3. statut propagé sur toutes surfaces · 4. multi-commande concurrent
- Convergence : §0.5 — c'est LE test de vérité du système.

#### U-S2 — Bus de synchro temps-réel + dégradation
- Anchors : `OrderCreated`/`OrderStatusChanged`/`KdsOrderRecalled`, `routes/channels.php:41`, soketi, `WebSocketService.js`, outbox `MonitorOutboxStaleness`
- Spec canonique : `tests/e2e/canonical/shared/s2-sync-bus-degradation.spec.js` (supersède sync specs)
- Checklist : 1. event reçu cross-surface < latence contrat · 2. ordering self-heal (re-fetch REST authoritative) · 3. **dégradation ws-down → fallback polling, HTTP jamais bloqué** · 4. outbox dedupe exactly-once · 5. worker-down détectable
- Known-weak : crash-orphan monitor blind spot (WP-03) ; **KDS-01** (Wave-1) poller cadence-adaptative lit `wsService.state` inexistant → échelle WS-aware = dead code (`KdsSyncService.js:298`, fix `getState?.()`) ; **KDS-02** poller halt sur non-5xx (cf. U-K1)
- Convergence : §0.5.

#### U-S3 — Résilience OFFLINE (la caisse-ne-s'arrête-JAMAIS) ⭐ crux
- Anchors : `posOfflineQueue.js`, `posOfflineQueueDb.js`, `usePosOfflineState.js`, `PosComponent.vue` ; sentinels `posOfflineQueueImpl.spec.js`/`posOfflineReplayUrlSentinel.spec.js`/`posOfflineCashReceivedSentinel.spec.js`
- Spec canonique : `tests/e2e/canonical/shared/s3-offline-resilience-e2e.spec.js`
- Checklist : 1. **coupure réseau → commande/paiement QUEUED (0 perte)** · 2. reconnexion → resync auto (ordre, retry, backoff) · 3. **replay idempotent (0 double-submit)** · 4. persistance IndexedDB across reload/restart offline · 5. conflit 409/stale · 6. feedback caissier (état offline + count pending) · 7. aucun chemin de double-comptage paiement
- Known-weak (Wave-1, crux) : **OFF-02 (P1, le + grave)** enqueue offline ne vide PAS le cart → re-clic = 2e copie avec nouvelle idempotency-key → **double commande + double ligne cash au replay** (`PosComponent.vue:3922`, fix = reset cart comme le succès online :3725) ; **OFF-01** enqueue seulement si `navigator.onLine===false` → serveur-injoignable-interface-up **perd la vente** (`:3910`, traiter 5xx/timeout comme offline) ; **OFF-03** auto-flush avale silencieusement les échecs sync → caissier jamais prévenu (`usePosOfflineState.js:71`). Forces validées (NE PAS régresser) : replay idempotent lost-response OK, IndexedDB persiste cross-reload + fallback localStorage Safari, PII strippé, MAX 50 reject-new garde les + vieilles ventes cash, banner dégradé visible. (Réfuté : OFF-04 purge-TTL = non-atteignable.)
- Convergence : §0.5 — be thorough, c'est le crux robustesse owner.

#### U-S4 — NF525 chaîne + auth/idempotency cross-surface
- Anchors : Fiscal chain (§6 frozen), BranchScope (frozen), IdempotencyKeyMiddleware (frozen), Sanctum abilities
- Spec canonique : `tests/e2e/canonical/shared/s4-fiscal-auth-idempotency-e2e.spec.js`
- Checklist : 1. chaîne HMAC tamper-detect · 2. attestation before==after tout flux · 3. POST mutating idempotent (X-Idempotency-Key) · 4. BranchScope isolation staff/admin · 5. kiosk:order ability scope-limité (pas d'API admin)
- Convergence : frozen → défaut = §0.6 gate ; chaîne change = escalate.

---

## §A — ARMÉE D'AGENTS + CONTEXT-PACK (l'artefact-cœur — « chaque agent vise UN petit système avec toute sa Memory/contexte »)

### A.1 — Modèle : 1 unité = 1 agent E2E dédié + son context-pack
**25 unités → 25 agents E2E** (B1-4, C1-5, K1-4, W1-3, CE1-5, S1-4), chacun **possédant SON petit système** et le testant exhaustivement. Chaque agent reçoit un **CONTEXT-PACK** (ci-dessous) — c'est ce qui réalise « toute sa Memory + tout son contexte ».

### A.2 — CONTEXT-PACK template (collé dans le prompt de chaque agent à l'exécution)
```
TU POSSÈDES L'UNITÉ <U-Xn> : <nom>. Tu la testes EXHAUSTIVEMENT (chaque fonctionnalité, chaque route/état atteignable).
1. MÉMOIRE À CHARGER : CONSTITUTION.md · SYSTEM_MAP.md (ta voie) · SYNC_CONTRACT.md (si sync) · PROJECT_BRAIN.md §2 ·
   memory pertinentes (loyalty/offline/encaissement/frozen-zones selon l'unité).
2. ANCHORS (ouvre-les, vérifie file:line) : <liste de l'unité §3-§8>.
3. SURFACE LIVE : <url :8000> — admin@lecayenne.fr / 123456.
4. CONTRAINTES DURES : frozen zones (run-only, défaut→§0.6 gate, JAMAIS éditer) · NF525 (chaîne intacte) ·
   FR locale · TPE simulé = boundary PAS défaut · System-A séparé · 0 produit inventé (45 SSOT).
5. SPEC CANONIQUE À POSSÉDER : tests/e2e/canonical/<...> (réutilise les one-offs datés, supersède-les).
6. CHECKLIST (énumère CHAQUE en-tête en flux concrets : happy + edge + abus) : <headers de l'unité>.
7. PREUVE : screenshot Read+analysé (raw label/layout/console/i18n/contraste/a11y) · repro file:line ·
   frozen-diff=0 · (si fiscal) chaîne before==after.
8. CONVERGENCE : 2 cycles P0+P1=0 + findings identiques. Défaut sur surface frozen → DOCUMENTE+gate, NE boucle PAS.
9. RÈGLE PETIT-SYSTÈME : si tu ne peux pas tout couvrir en 1 contexte → SCINDE et signale (mini-decompose).
SORTIE : findings structurés {sev, file:line, repro, evidence, reco, frozen?} + strengths + verdict converge/heal/gate.
```

### A.3 — Fan-out matrix (rôles qui tirent par type d'unité)
| Type unité | E2E-owner | QA-Visual | RED-Visual (adverse) | Security | A11y | SRE/Sync |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Frontend visuel (kiosk/pos-ux/kds/oss/dashboard/web) | x | x | x | . | x | . |
| Backend-logic (fiscal/cash/orders/reports) | x | . | x | x | . | . |
| Cross-surface/sync (S1/S2) | x | x | x | x | . | x |
| Offline (S3) | x | x | x | x | . | x |
| Auth/NF525 (S4/C4) | x | . | x | x | . | . |

### A.4 — Discipline dispatch
- **Audit/E2E read-only fan-out** = parallèle, single message, par vague.
- **QA-Visual + RED-Visual** = parallèle OK (lecture screenshots) ; RED ré-analyse indépendamment + dispute.
- **Agent qui écrit une spec canonique** = jamais 2 sur le même fichier (specs disjointes par unité → sûr).
- **Adversarial refute-by-default** sur findings (code mûr 2969 tests verts) — tue les P0 hallucinés.
- **Reporting contract** : chaque agent persiste `reports/test-e2e/all-systems-2026-06-06/<wave>/<unité>.json`.

---

## §X — VAGUES DE CONVERGENCE (parallélisme + checkpoint + interrupt-resume)
Voies disjointes (SYSTEM_MAP) → parallèle sûr. §8 SHARED = après les voies (teste les contrats une fois les unités vertes).

| Vague | Unités | Parallélisme | Checkpoint sortie |
|---|---|---|---|
| **W1 CAISSE** | C1-C5 | spécs disjointes ∥ ; fold Wave-1 audit | 5/5 converge OU frozen-defect→gate ; frozen-diff=0 ; chaîne OK |
| **W2 KDS+OSS** | K1-K4 | ∥ (peut tourner ∥ W1, voies disjointes) | 4/4 converge ; sync borne→KDS vérifié |
| **W3 BORNE** | B1-B4 | ∥ | 4/4 ; frozen kiosk-defects→gate |
| **W4 CENTRAL** | CE1-CE5 | ∥ | 5/5 ; dashboard live-update prouvé |
| **W5 WEB+APP** | W1-W3 | ∥ (standalone, isolé) | DIAL-1 light=confirm ; full=converge |
| **W6 SHARED/CROSS** | S1-S4 | séquentiel (teste contrats §6, après voies) | S1 flux quotidien vert bout-en-bout ; S3 offline 0-perte ; S4 chaîne attestée |
| **W7 CONVERGENCE GLOBALE** | re-run tout + adversarial | — | 2 cycles consécutifs P0+P1=0 findings identiques sur TOUT |

**Checkpoint par vague (6 obligatoires)** : tâches PASS/baseline-documenté · frozen-diff=0 (`git diff --stat`) · NF525 chaîne inchangée/append-only · visual gate analysé · RED dispute fait, P0/P1 healed ou gated · BRAIN §2/§3 màj.

**Interrupt-resume** : si vague coupée → commit WIP `wip(<wave>): partial through U-Xn` + manifeste `reports/test-e2e/all-systems-2026-06-06/INTERRUPT_<wave>_<ts>.md` (last-green SHA, unité en cours, prochaine, JSONs déjà écrits) + BRAIN §2. Reprise = lire manifeste → smoke 1-unité → continuer.

**Convergence-failure (3 heals sur même cluster)** : STOP, spawn `Plan` agent root-cause → `STUCK_<wave>.md` → escalate owner (A accept-doc / B pivot / C defer V1.0.X / D human gate). Ne pas auto-choisir.

---

## §G — OWNER GATES (WHO / WHAT / WHERE)
| Gate | Description | WHO | WHAT | WHERE | Statut |
|---|---|---|---|---|---|
| **G-DIAL1** | System-A profondeur (light vs full) | Owner | choix au lancement | §0.1 DIAL-1 | PENDING |
| **G-DIAL2** | Env (local vs +cloud parité) | Owner | choix au lancement | §0.1 DIAL-2 | PENDING |
| **G-FROZEN** | Défaut réel trouvé sur surface frozen (pos-wizard/PaymentComponent/PosV5/kiosk-wizard/Fiscal) | Owner | LOCK doc + contreseing pour fix | §0.6 + commit tag | PENDING (si trouvé) |
| **G-PIN** | PIN par opérateur + ré-auth sur ops cash sensibles (ouvre/clôture/no-sale/refund) | Owner | confirmer si V1 (brief Mission-2 le demande) OU différer V2 | §4 U-C3 ; Wave-1 CASH-02 réfuté-comme-non-défaut-mono-opérateur | PENDING |
| **G-LOY** | Loyalty ratio pts/€ (backend 10 vs app 1) | Owner | valeur | memory gate-D1 | PENDING |
| **G-TPE** | TPE simulé = boundary, PAS défaut | Owner (déjà acté) | confirmer reste simulé | CONSTITUTION §2 | ACTÉ |
| **G-NF525** | Tout changement chaîne = escalate humain | Owner | attestation before==after | `fiscal:verify-chain` | STANDING |
| **G-LAUNCH** | **Lancer l'exécution de ce GOAL** | **Owner** | **« go »** | ce fichier | **PENDING — c'est la porte que tu tiens** |

---

## §R — RÉFÉRENCES
- SSOT : `CONSTITUTION.md`, `SYSTEM_MAP.md`, `SYNC_CONTRACT.md`, `PARALLEL_PROTOCOL.md`, `PROJECT_BRAIN.md §2`
- Mission-1 livré : `reports/audit/system-b-final-2026-06-06/VALIDATION_TABLEAU_AND_PLAN.md` (loyalty `d2b244df5`, WP-06 `50a62ec81`)
- Mission-2 brief : `plans/GOAL_POS_SYSTEM_ORCHESTRATION_2026-06-06.md` ; Wave-1 audit en cours `wfa1r9zqm`
- System-A : `reports/goal-system-a-2026-06-06/{START_HERE,POS_INTEGRATION_GUIDE,PARITY_WEB_MOBILE,CONVERGENCE_FINAL}.md`
- Skills : `test-e2e` (boucle visuelle), `ultra-audit-profond` (14 étapes), `superpower-gstack`, `ultra-architect-planify`
- E2E : `playwright.config.js` (baseURL :8000), `tests/e2e/**` (300 specs ; canoniques nouvelles sous `tests/e2e/canonical/`)

## §F — RÈGLE FINALE (DONE)
DONE = **chaque fonctionnalité de chaque système E2E-vert + visuellement analysé + adversarialement convergé** (2 cycles identiques P0+P1=0) ; specs canoniques possèdent chaque unité ; défauts surface-frozen DOCUMENTÉS+gatés (pas bouclés) ; chaîne NF525 attestée before==after ; frozen-diff=0 ; BRAIN à jour. **Pas « presque » — production-parfait par unité, ou bloqué+escaladé.** Livrer ≥10× : boucler audit→E2E→sécurité→visuel sur tous écrans/fonctions jusqu'à ce qu'aucune unité ne reste non-validée.

---
**FIN DU SPINE. Statut = PREPARE-ONLY. J'attends ton « go » (G-LAUNCH) + tes réglages de DIALS.**

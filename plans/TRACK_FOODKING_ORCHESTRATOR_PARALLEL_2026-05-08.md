# Track FoodKing — Orchestrateur Parallèle (mode exécution)
**Date :** 2026-05-08
**Rôle :** Claude orchestrateur en parallèle de l'agent exécuteur (qui travaille sur F-001..F-017)
**Branche :** `claude/blissful-mclean-c915c2` (worktree)
**Politique :** Zéro conflit avec l'agent. Greenfield prioritaire. Edits scope-minimal sur fichiers safe uniquement.
**GSTACK :** Think → Plan → Build → Review → Test → Ship → Reflect

---

## 0. Contexte d'exécution

L'agent exécuteur (Claude Opus) tourne en autonomie sur les 17 findings audit (`plans/PLAN_AUDIT_F0XX_*.md` + handoff `VALIDATION_WAVES_AND_NEXT_HANDOFF_2026-05-08.md`). État au 2026-05-08 :

| Phase | Status |
|---|---|
| Wave 1 (F-004, F-005, F-006, F-007, F-008, F-014) | ✅ closed (commit `b8f05e609`) |
| Wave 2 (F-001, F-002, F-003, F-010, F-011, F-013) | ✅ closed |
| F-009 cash-acknowledge | 🔄 en cours / récent |
| F-015 production blocker queue config | À faire |
| F-016a-BIS stock orchestration (REFINED via stock_levels existing) | 🔄 en cours (refined plan vu) |
| F-016b UI stock manager | À faire |
| F-017 massive E2E test suite | À faire |
| Audit cumulatif final + merge | À faire |

L'owner demande au présent orchestrateur (moi) **d'exécuter en parallèle** des améliorations qui :
1. **Ne conflictent pas** avec l'agent
2. **Améliorent le système** en V1 (audit + design + sync + tests)
3. **Ajoutent l'intégration delivery platforms** (Uber Eats, Deliveroo, Delicity) — la seule nouvelle option autorisée
4. **Pas de feature creep** — V1 only, pas de SaaS, pas de mobile, pas de roadmap 24 mois

---

## 1. ZONE SAFE — Ce que je peux toucher

### 1.1 Greenfield (zéro risque conflit)

**Code backend :**
- `app/Services/Delivery/*` (nouveau) — adaptateurs UberEats / Deliveroo / Delicity
- `app/Domain/Delivery/*` (nouveau) — value objects, enums
- `app/Models/Delivery*.php` (nouveaux)
- `app/Http/Controllers/Webhook/DeliveryPlatformWebhookController.php` (nouveau)
- `app/Http/Controllers/Admin/DeliveryPlatformController.php` (nouveau)
- `app/Http/Requests/Delivery/*` (nouveaux)
- `app/Jobs/ProcessDeliveryPlatformOrder.php` (nouveau)
- `app/Listeners/SyncDeliveryOrderToFrontendOrder.php` (nouveau)
- `database/migrations/2026_05_08_*_delivery_*.php` (nouveaux)

**Code frontend :**
- `resources/js/components/admin/deliveryPlatforms/*` (nouveau dossier)
- `resources/js/components/admin/dashboard/widgets/DeliveryPlatformsWidget.vue` (nouveau)

**Tests :**
- `tests/Feature/Delivery/*`
- `tests/Unit/Services/Delivery/*`
- `tests/Feature/Webhook/*`
- Tout test dans un namespace nouveau

**Docs :**
- `docs/DELIVERY_PLATFORMS.md` (nouveau)
- `docs/SECURITY_WEBHOOKS.md` (nouveau)

### 1.2 Edits scope-minimal autorisés (fichiers safe ne croisant pas l'agent)

**Admin Vue (audit + amélioration UX, pas de logique métier) :**
- `resources/js/components/admin/dashboard/*` (analytics, layout)
- `resources/js/components/admin/customers/*` (CRM)
- `resources/js/components/admin/employees/*`, `chefs/*`, `waiters/*`
- `resources/js/components/admin/messages/*`, `pushNotification/*`
- `resources/js/components/admin/offers/*`, `coupons/*`
- `resources/js/components/admin/settings/*` (ajouter onglet delivery-platforms)
- `resources/js/components/admin/transactions/*`
- `resources/js/components/admin/components/*` (shared UI library)

**POS Vue (uniquement parties non touchées par F-001..F-017) :**
- `resources/js/components/admin/pos/CreateCustomerAddressComponent.vue` (probablement safe)
- `resources/js/components/admin/pos/ReceiptComponent.vue` (probablement safe — F-001 utilise pour invariant tests sans code change)

**Kiosk non-wizard non-frozen-by-owner :**
- `KioskAdminComponent.vue` (admin maintenance kiosk)
- `KioskLoginComponent.vue`
- `KioskIdleScreenComponent.vue`
- `KioskInactivityOverlayComponent.vue`
- `KioskToastComponent.vue`
- `KioskErrorLayoutComponent.vue`, `KioskErrorNetworkComponent.vue`, `KioskErrorMenuUnavailableComponent.vue`, `KioskErrorPaymentRefusedComponent.vue`, `KioskErrorProductRemovedComponent.vue`
- `KioskLoyaltyComponent.vue`

### 1.3 ZONES INTERDITES (territoire agent ou frozen propriétaire)

**Frozen owner :**
- `public/js/pos-wizard.js` (POS wizard Vanilla)
- `public/css/pos-wizard.css`
- 8 composants kiosk wizard Vue : `KioskWizardComponent`, `KioskPosWizardComponent`, `KioskCartComponent`, `KioskCategoriesComponent`, `KioskUpsellComponent`, `KioskPromoCarouselComponent`, `KioskOrderSummaryComponent`, `KioskProductListComponent`

**Agent en cours / déjà fait F-001..F-017 :**
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/PaymentService.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Menu/AvailabilityService.php` (étendu par F-016a-BIS)
- `app/Services/Stock/*` (territoire F-016a-BIS)
- `app/Services/Kiosk/KioskMenuService.php` (F-016a-BIS enrichissement)
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Http/Requests/Frontend/PaymentConfirmRequest.php`
- `app/Http/Resources/NormalItemResource.php` (F-016a-BIS)
- `app/Http/Controllers/HealthController.php` (F-015)
- `app/Domain/Order/OrderStateMachine.php` (frozen domaine)
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (F-016 echo handlers)
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` (F-002, F-008, F-009)
- `resources/js/components/admin/pos/PosComponent.vue` (F-016 echo handlers, F-006)
- `resources/js/components/admin/pos/ItemComponent.vue` (probablement F-016)
- `.env.example` (F-015)
- `docs/REALTIME_SETUP.md` (F-015)
- `tests/e2e/*` (F-017)
- Migrations `2026_04_15_*` à `2026_05_08_*` liées stock/cash/payment_confirm/extras_availability

---

## 2. TRACKS D'EXÉCUTION (4 tracks parallèles)

### Track A — Delivery Platform Integration (greenfield, P0 user-requested)

**Objectif :** Permettre à l'admin de configurer Uber Eats, Deliveroo, Delicity. Réception de commandes externes via webhook, mapping vers `FrontendOrder`, broadcast au KDS.

**Architecture :**
```
[Uber Eats / Deliveroo / Delicity webhook]
       ↓
POST /api/webhooks/delivery/{platform}/order-created
       ↓
DeliveryPlatformWebhookController
       ├─ Validate signature HMAC per platform
       ├─ DispatchableJob ProcessDeliveryPlatformOrder
       └─ 200 ACK
       
ProcessDeliveryPlatformOrder (queue)
       ├─ Adapter resolve (UberEatsAdapter / DeliverooAdapter / DelicityAdapter)
       ├─ Map payload → FrontendOrder data
       ├─ Lookup branch by external store_id
       ├─ Create FrontendOrder (status=ACCEPT, payment_status=PAID, source_surface='uber_eats|deliveroo|delicity')
       ├─ Allocate fiscal_sequence_no (NF525)
       ├─ Persist order_items + variations + extras
       ├─ event(OrderCreated) → outbox → broadcast KDS
       └─ Log + metric
```

**Sub-tasks :**
- A.1 Schema migrations : `delivery_platforms`, `delivery_platform_branch_configs`, `delivery_platform_external_orders` (mapping log)
- A.2 Models + relations
- A.3 Services adapters (UberEats, Deliveroo, Delicity) avec interface commune
- A.4 Webhook controller + signature validation per platform
- A.5 Job ProcessDeliveryPlatformOrder
- A.6 Routes webhook + admin
- A.7 Admin UI configuration (greenfield Vue dossier)
- A.8 Tests Feature + Unit (10+ scénarios)
- A.9 Documentation

**Effort :** 4-6 jours-agent

### Track B — Audit Admin Dashboard + Améliorations Design

**Objectif :** Audit qualitatif des composants Vue admin (hors POS wizard, hors Kiosk wizard, hors zone agent) et améliorations design ciblées.

**Sub-tasks :**
- B.1 Audit dashboard pages (Explore sub-agent read-only)
- B.2 Audit customers/employees/messages pages
- B.3 Audit settings pages (où ajouter onglet delivery-platforms)
- B.4 Identifier 10-15 améliorations UX/design ciblées
- B.5 Exécuter améliorations (sub-agents en parallèle)

**Effort :** 3-5 jours-agent

### Track C — Sync Robustness Audit (root level)

**Objectif :** Audit "à la racine" du système de synchronisation. NE PAS modifier Outbox pattern (territoire agent F-015) mais auditer les zones connexes.

**Sub-tasks :**
- C.1 Audit Echo client lifecycle (websocket reconnect, auth refresh, channel re-subscribe)
- C.2 Audit polling fallback robustness (KDS, OSS, POS)
- C.3 Audit error logging consistency
- C.4 Audit conflict resolution (2 caissiers, double-edit)
- C.5 Identifier améliorations sans toucher zones agent
- C.6 Exécuter améliorations scope-minimal

**Effort :** 2-3 jours-agent

### Track D — Tests Massifs Hors-Conflit

**Objectif :** Compléter la couverture tests sur les zones non couvertes par F-017 (qui est E2E).

**Sub-tasks :**
- D.1 Tests Unit sur services non touchés par agent
- D.2 Tests Feature sur endpoints non couverts
- D.3 Tests sécurité (XSS, SQL injection, CSRF, mass-assignment)
- D.4 Tests performance benchmarks (N+1 queries)
- D.5 Tests delivery platform integration (couplé Track A)

**Effort :** 3-4 jours-agent

---

## 3. PIPELINE GSTACK APPLIQUÉ

```
THINK   → Spawn 5 audit sub-agents (Explore, read-only) en parallèle :
          1. Admin Dashboard surface audit
          2. Kiosk non-wizard surface audit
          3. POS non-wizard surface audit (Receipt, CustomerAddress)
          4. Sync robustness audit (Echo client, polling, errors)
          5. Delivery platform architecture exploration
        → Fusionner les findings dans plans/AUDITS_RESULTS.md

PLAN    → Décomposer en sub-tasks atomiques par track
        → Prioriser par impact V1 + zéro-conflit
        → Pour chaque sub-task : acceptance criteria + frozen-zones intactes

BUILD   → Spawn general-purpose sub-agents en parallèle (1 sub-agent par sub-task)
        → Greenfield d'abord (Track A delivery)
        → Edits scope-minimal ensuite (Tracks B, C, D)
        → Discipline TDD : test rouge → fix → vert
        → Format commit `parallel(track-X.Y): <résumé>` pour distinguer du travail agent

REVIEW  → Self-review checklist anti-drift par sub-task
        → Vérifier zéro touche zones interdites (§1.3)
        → Diff < 200 lignes par commit

TEST    → Run cumulatif après chaque sub-task
        → Vérifier que les tests existants passent toujours
        → Vérifier que les nouveaux tests sont déterministes

SHIP    → Commit isolé par sub-task
        → Branche peut rester `claude/blissful-mclean-c915c2` (le merge final orchestré plus tard avec branche agent)
        → Push branche distante pour traçabilité

REFLECT → Update memory + Graphiti après chaque track close
        → Document final dans `plans/TRACK_FOODKING_ORCHESTRATOR_FINAL_REPORT.md`
```

---

## 4. SUB-AGENT ALLOCATION

| Track | Phase | Sub-agent | Mode |
|---|---|---|---|
| THINK | Audits parallèles 5 surfaces | Explore × 5 | Read-only, zéro conflit |
| PLAN | Synthèse | Plan × 1 | Read-only |
| BUILD A | Delivery integration | general-purpose × 4-5 (1 par sub-task) | Greenfield write |
| BUILD B | Admin design | general-purpose × 3-4 | Edits scope-minimal |
| BUILD C | Sync audit | general-purpose × 1-2 | Read + scope-minimal edits |
| BUILD D | Tests massifs | general-purpose × 3-4 | Tests-only writes |
| REVIEW | Self-checks | Inline | — |
| TEST | Cumulative | Bash inline | — |

---

## 5. ACCEPTANCE CRITERIA GLOBAUX

- [ ] **AC1** Zéro modification des zones interdites §1.3 (vérifié par git diff)
- [ ] **AC2** Tous les tests existants passent (no regression sur baseline)
- [ ] **AC3** Track A delivery platform : 3 plateformes configurables, webhook OK, mapping order OK, KDS reception OK
- [ ] **AC4** Track B admin design : ≥10 améliorations livrées avec captures avant/après
- [ ] **AC5** Track C sync : audit livré, ≥5 améliorations actionnables
- [ ] **AC6** Track D tests : ≥30 nouveaux tests Unit + Feature + Sécurité
- [ ] **AC7** Documentation à jour : DELIVERY_PLATFORMS.md, SECURITY_WEBHOOKS.md
- [ ] **AC8** Graphiti push à chaque track close
- [ ] **AC9** Merge clean possible avec branche agent (zéro conflit)

---

## 6. RISK REGISTER

| Risque | Probabilité | Mitigation |
|---|---|---|
| Conflit fichier avec agent | Low (zone safe stricte) | Vérification git status fréquente, freeze zones list §1.3 |
| Sub-agent invente du code (hallucination) | Medium | Verification post-execution par lecture, tests obligatoires |
| Tests flaky introduits | Medium | TDD strict + déterminisme + Carbon mock |
| Scope creep delivery platform | High (greenfield = tentation) | Limiter strict aux 3 plateformes + admin UI minimale + webhook + mapping |
| Drift entre plan et code actuel | Medium | Drift verification AVANT chaque sub-task |
| Frozen-zones touchées par accident | Low | Anti-drift checklist 12 cases + grep guard |

---

## 7. REPORTING

À la fin de chaque track : `reports/orchestrator_parallel_2026-05-08/REPORT_TRACK_<X>.md`

À la fin globale : `plans/TRACK_FOODKING_ORCHESTRATOR_FINAL_REPORT_2026-05-08.md` avec :
- Métriques (LOC, tests, coverage, performance)
- Liste exhaustive des fichiers touchés
- Diff agent-compatibility check
- Recommandations merge
- Graphiti episode UUID

---

## 8. SIGNATURE

- Orchestrateur : Claude (parallel mode)
- Agent autre : Claude Opus exécuteur (F-009 → F-015 → F-016 → F-017 en cours)
- Politique : zéro conflit, V1 only, GSTACK strict
- Engagement : exécuter sans validation supplémentaire (ordre owner explicite)

— *Travailler en parallèle, pas en concurrence. Bâtir sans empiéter. Déléguer pour décupler.*

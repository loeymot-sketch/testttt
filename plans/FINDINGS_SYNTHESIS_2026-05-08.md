# Findings Synthesis — Audits Parallèles 2026-05-08

**Source :** 5 audits Explore/Plan en parallèle (zéro conflit avec agent F-016)
**Total findings actionnables :** ~80 sur 5 surfaces
**Effort cumulé estimé :** ~95-115 heures-agent (parallélisable)

---

## 1. Synthèse par track d'exécution

### Track 1 — Delivery Platform Integration (greenfield) — HIGHEST priority owner-asked

Plan complet livré par Plan sub-agent : 29 sub-tasks, 50-55h, architecture complète, 33 tests planifiés.

**Phases d'exécution séquentielles (chaque phase peut spawner plusieurs build sub-agents en parallèle) :**

- **Phase 1 (5-6h)** : 3 migrations + 3 Models + 1 DTO + interface + registry + service provider + 2 enums (Source 30, PaymentGateway DELIVERY_PLATFORM)
- **Phase 2 (10-12h)** : UberEats adapter + VerifyDeliverySignature middleware + WebhookController + ProcessDeliveryWebhookJob + Routes + IngestionService E2E + Tests
- **Phase 3 (8-10h)** : Deliveroo adapter + Delicity adapter + PushStatusToDeliveryPlatform listener + Tests cumulés
- **Phase 4 (6-8h)** : Admin Vue UI (DeliveryPlatformsPage + Row + ConfigModal + HealthBadge) + Settings tab + Health endpoint + Doc + Seeder

### Track 2 — NF525 Critical Gaps (scope-minimal, high impact)

**Critical** : `ReceiptComponent.vue:259` n'affiche PAS `fiscal_sequence_no` ni signature HMAC. F-001 alloue le champ mais l'affichage manque → ticket non-NF525-ready au plan visuel/audit.

**Sub-tasks :**
- 2.1 (45 min) : Ajouter `fiscal_sequence_no` + signature courte HMAC dans `ReceiptComponent.vue` (display only, scope-minimal)
- 2.2 (50 min) : QR code `qrcode.vue` encodant `{order_id, fiscal_seq, hmac_short}` pour archivage
- 2.3 (5 min) : Supprimer dead code `SmModalCreateComponent` import dans `CreateCustomerAddressComponent.vue:111`

### Track 3 — Sync Robustness (scope-minimal, defensive coding)

10 recos identifiées par audit. Je sélectionne les 5 plus impactantes hors zone agent :

- **3.1 (1h)** : `_refreshEchoAuth()` defensive guard (`bootstrap.js:111-116`)
- **3.2 (0.5h)** : warn console si `window.Echo` undefined dans `eventContract.js:331-335`
- **3.3 (1h)** : `ws:heartbeat` cache key set par `DispatchDomainEventsJob` (HealthController.ready le check déjà mais clé jamais settée — bug actuel)
- **3.4 (1h)** : Rate-limit `/api/broadcasting/auth` 20 req/min/IP (defense brute-force)
- **3.5 (1.5h)** : Frontend axios error handler 409 → toast warn '[Sync] Order changed elsewhere'

### Track 4 — Quick Wins UX/A11y/i18n (scope-minimal)

**Admin dashboard hardcoded FR :**
- 4.1 (1h) : i18n `RealtimeReportComponent`, `AuditTrailComponent`, `SlaAlertsComponent`, `ChannelStatsComponent`, `ErrorBoundary` (5 composants)
- 4.2 (0.5h) : `:key="customer"` → `:key="customer.id"` dans `CustomerListComponent.vue:86`
- 4.3 (0.5h) : ARIA progressbar sur `ChannelStatsComponent` barres %
- 4.4 (1h) : Polling retry+backoff dans 3 widgets dashboard

**Kiosk error components :**
- 4.5 (1h) : Mapper `TPE_ERROR_CODES` → messages FR clairs dans `KioskErrorPaymentRefusedComponent`
- 4.6 (45 min) : Visual urgency `.countdown-critical` (<10s) dans `KioskInactivityOverlayComponent`
- 4.7 (1h) : Video fallback timeout `KioskIdleScreenComponent` (3s no loadstart → fallback animé)
- 4.8 (45 min) : Toast emoji ARIA `role="img"` dans `KioskToastComponent`
- 4.9 (45 min) : Contrast WCAG AA fixes (3 classes `kiosk-lang-btn`, `kiosk-loyalty-skip`, `kiosk-admin-sub`)

### Track 5 — Tests Massifs Hors-Conflit

- 5.1 (3h) : Delivery platform tests (couplé Track 1, déjà planifié 33 scénarios)
- 5.2 (1h) : Tests sécurité broadcast/auth rate-limit + auth failures structured logging
- 5.3 (1h) : Tests memory leak Echo handlers (mount/unmount Vue components → assert listeners count)
- 5.4 (1h) : Tests heartbeat cache key population

---

## 2. Ordre d'exécution & parallélisation

```
Build sub-agent A : Track 1.1 (Delivery Phase 1 schema + contracts) [5-6h]
Build sub-agent B : Track 2 (Receipt NF525 + dead code) [1.5h]
Build sub-agent C : Track 4 (i18n + ARIA quick wins admin) [3h]
Build sub-agent D : Track 4 kiosk (TPE codes, video fallback, contrast) [4h]
              ↓ all parallel, no overlap
Build sub-agent E : Track 1.2 (Delivery Phase 2 UberEats E2E) [10-12h]
Build sub-agent F : Track 3 (Sync improvements 5 items) [5h]
              ↓
Build sub-agent G : Track 1.3 (Deliveroo + Delicity + StatusPush) [8-10h]
Build sub-agent H : Track 5 (tests sec + memory + heartbeat) [3h]
              ↓
Build sub-agent I : Track 1.4 (Admin UI delivery + doc) [6-8h]
              ↓
Validation cumulative + commit + Graphiti
```

---

## 3. Frozen-zones strictes (rappel)

**Owner-frozen :**
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`
- 8 composants kiosk wizard Vue

**Agent en cours / déjà fait :**
- `OrderService`, `FrontendOrderService`, `PaymentService`, `Fiscal/*`, `Stock/*`
- `OrderController` Frontend, `PaymentConfirmRequest`, `NormalItemResource`
- `KioskAppComponent`, `KioskPaymentComponent`, `PosComponent`, `ItemComponent`
- `HealthController` (F-015), `.env.example`, `docs/REALTIME_SETUP.md`
- `tests/e2e/*` (F-017)

---

## 4. Acceptance criteria globaux

- [ ] Zéro modification frozen-zones (vérifié par git diff)
- [ ] Tous tests existants passent (no regression)
- [ ] Track 1 : Delivery Phase 1+2 livrée + 1 plateforme (UberEats) E2E vert + 10+ tests
- [ ] Track 2 : Receipt affiche fiscal_sequence_no + QR + dead code supprimé
- [ ] Track 3 : 5 sync improvements livrés + tests
- [ ] Track 4 : i18n 5 composants + ARIA 4 fixes + visual urgency + contrast 3 classes
- [ ] Track 5 : 30+ nouveaux tests
- [ ] Documentation : DELIVERY_PLATFORMS.md, SECURITY_WEBHOOKS.md
- [ ] Graphiti push à chaque track close

---

## 5. Risk register synthèse

| Risque | Mit |
|---|---|
| Conflit fichier avec agent | Zone safe stricte §3 + git status check fréquent |
| Sub-agent invente du code | Verification post-execution + tests obligatoires |
| Scope creep delivery | Limiter strict 3 plateformes + UI minimale + webhook + status push |
| Frozen-zones touchées | Anti-drift checklist 12 cases + grep guard |
| Tests flaky | TDD strict + Carbon mock + déterminisme |

---

## 6. Reporting

Chaque track close → `reports/orchestrator_parallel_2026-05-08/REPORT_TRACK_X.md`
Final → `plans/TRACK_FOODKING_ORCHESTRATOR_FINAL_REPORT_2026-05-08.md`

Push Graphiti episode `Orchestrator Parallel Track X closed` à chaque clôture.

---

— *5 audits → 80 findings → 5 tracks → exécution parallèle. La discipline d'orchestration n'est jamais perdue de vue.*

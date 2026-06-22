# 🎯 ULTRA-PLAN V1 GO-LIVE → V1.0.1 → V1.x

**Date** : 2026-05-09
**Branche** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` HEAD `aa31c68b7`
**Trigger** : User a demandé `/ultraplan` — généré localement après audit iter15 (5 systèmes audit YC GStack)
**Discipline** : CLAUDE.md §5 LOOP étape 2 — Plan + STOP + owner validation gate

---

## §0 — Context & current state

**Iter15 audit verdicts** :
- ✅ Caisse POS 9.2/10 CLEAN — merge-ready
- ⚠️ Borne Kiosk 7/10 HEAL — 1 P1 critique (FR-lock setLocale)
- ⚠️ Sync Events 6/10 HEAL — 1 P1 critique (6 listeners idempotency)
- ✅ Tracking + Stock 9/10 CLEAN — merge-ready
- ✅ Global Cross-System 9/10 CLEAN — merge-ready

**0 P0 trouvés.** 2 P1 critiques à corriger avant merge V1 (~1.25j-agent).

**16 domaines production-ready** validés post iter11-15 :
1. Architecture event-driven (Outbox + Pusher + polling fallback 5s)
2. Multi-tenant BranchScope 11 models
3. Pricing SSOT NF525 (composition_snapshot frozen)
4. Fiscal hash chain HMAC + DB DELETE triggers MySQL
5. Idempotency dual-layer (middleware + DB UNIQUE) + webhook_events unifié
6. Order state machine + lockForUpdate races iter13
7. Sanctum kiosk:order single-ability strict
8. Stock concurrency + listener escalation iter12+13
9. Daily quota stale reset cron iter13
10. Cash audit F-003 chain-signed
11. Allergen FR + composition_snapshot
12. Production guards AppServiceProvider
13. Polling fallback KDS 5s
14. i18n + a11y OSS WCAG 2.1 (iter14)
15. Listener idempotency firstOrCreate (4/10 listeners iter14)
16. Fiscal orphan retry GATE-FZH-ALLOC iter14

---

## §1 — PHASE A : Pre-merge V1 (2 P1 critiques) — 1.25j-agent

### A1 — Borne FR-lock ADR-007 fix (0.25j)

**Problème** : `KioskIdleScreenComponent.vue:238` + `KioskAppComponent.vue:407` appellent `setLocale(lang)` runtime, contre ADR-007 immutable FR-lock policy.

**Fix scope-minimal** :
1. Lire les 2 fichiers + identifier les sites exacts
2. Retirer les appels `setLocale()` (les sélecteurs UI sont déjà masqués `v-if="false"`)
3. Vérifier que `applyKioskA11yFromStore(store)` force toujours `'fr'` au boot
4. Tests régression : `tests/js/kioskIdleScreenFrLock.spec.js` (si existe) ou créer

**Sub-agent assignment** : 1 agent A11y+i18n (lit + edit + test)

**Acceptance criteria** :
- ✅ `grep -n "setLocale" resources/js/components/frontend/kiosk/Kiosk{IdleScreen,App}Component.vue` retourne 0
- ✅ Vitest `kioskFrLock` tests verts (ou nouveaux tests créés et verts)
- ✅ Manual run : navigation kiosk = locale 'fr' immutable

**Risk register** :
- Risk : retrait du `setLocale` casse un flow legacy d'init → mitigation : tests régression + manual smoke
- Risk : composables ailleurs appellent `setLocale` → grep exhaustif `setLocale\|i18n.global.locale =`

**Rollback** : `git revert <commit-A1>` — 1 commit isolé

---

### A2 — Sync Events 6 listeners idempotency refactor (1j)

**Problème** : 6 listeners outbox utilisent `DomainEvent::create()` sans idempotency_key — race-non-atomic sous queue retry storms.

**Fix scope-minimal** : Apply iter14 SPECIALIST-2 pattern aux 6 listeners restants :

```php
// Pattern target (iter14 reference)
$idempotencyKey = sha1(implode('|', [
    EventType::CATALOG_CHANGED,
    $aggregateId,
    $changedFieldsHash,
    $correlationId,
]));
$domainEvent = DomainEvent::query()->firstOrCreate(
    ['idempotency_key' => $idempotencyKey],
    [/* event_type, aggregate, payload, channel, ... */]
);
```

**Listeners à refactor** :
1. `app/Listeners/PersistOrderTableChangedToOutbox.php` — key = `sha1(event|order_id|old_table|new_table)`
2. `app/Listeners/PersistCatalogChangedToOutbox.php` — key = `sha1(event|item_id|action|hash(payload))`
3. `app/Listeners/PersistCouponChangedToOutbox.php` — key = `sha1(event|coupon_id|action)`
4. `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` — key = `sha1(event|item|branch|is_available)`
5. `app/Listeners/PersistItemExtraAvailabilityChangedToOutbox.php` — key idem
6. `app/Listeners/PersistItemVariationAvailabilityChangedToOutbox.php` — key idem

**Sub-agent assignment** : 1 agent Architect+Tester (1 PHP refactor + tests)

**Acceptance criteria** :
- ✅ Les 6 listeners utilisent `firstOrCreate(['idempotency_key' => ...], [...])` cohérent avec iter14
- ✅ `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php` couvre les 10 listeners (étendre si nécessaire)
- ✅ Nouveau test `ListenerReplayDedupeTest` : same listener fired 2× → 1 row only
- ✅ Migration `2026_05_09_180000_add_idempotency_key_to_domain_events.php` appliquée (UNIQUE index)
- ✅ Cumulative Outbox|Persist|DomainEvent tests verts (target: 110+/110+)

**Risk register** :
- Risk : computed key collision sur événements legitimately distincts → mitigation : intégrer correlation_id (request-scoped) ou occurred_at_iso (event-scoped)
- Risk : migration UNIQUE pas encore appliquée prod → mitigation : `php artisan migrate --pretend` + run sur staging d'abord

**Rollback** : `git revert <commit-A2>` — 6 listeners revert ensemble

---

### A3 — Tests régression + commit + push (0.25j)

**Tâches** :
1. Run cumulative `php artisan test --filter='Outbox|Persist|DomainEvent|Kiosk|FrLock|i18n'`
2. Frozen-zones diff verify (cf manifest §7 frozen)
3. Vitest run kiosk specs
4. Commit avec message structuré (cohérent CLAUDE.md style)
5. Push origin
6. Update PROJECT_BRAIN.md §3 LAST DONE

**Acceptance criteria** :
- ✅ Tests cumulative iter11-15 verts (~705/705 + new tests)
- ✅ Frozen-zones strict 4/4 = 0 lines diff vs main
- ✅ Push successful
- ✅ BRAIN.md §3 + §7 mis à jour

---

## §2 — PHASE B : V1 GO-LIVE deploy (~0.5-1j-agent + owner actions)

### B1 — Backup prod mysqldump (owner)
```bash
mysqldump foodking_prod > pre-V1-backup-2026-05-09.sql
```

### B2 — Migrate --pretend staging snapshot (owner)
```bash
php artisan migrate --pretend
# Vérifier les 4 nouvelles migrations 2026_05_09_*
```

### B3 — Smoke test live multi-surfaces
- Kiosk idle → wizard → cart → payment ✅
- POS cash + card flow ✅
- KDS list + status update ✅
- Admin dashboard ✅
- OSS preparing/ready ✅

### B4 — Merge → main
```bash
gh pr merge <PR-V1> --squash --delete-branch
```

### B5 — Deploy + monitoring active
- Deploy artifact production
- Verify queue workers `queue:work --queue=high` HA
- Verify cron daemon active
- Monitor Sentry breadcrumbs first 1h
- Verify outbox dispatch latency < 2s

**Acceptance criteria** :
- ✅ V1 deployed prod
- ✅ 0 errors Sentry first hour
- ✅ Outbox pending < 10 rows < 30s
- ✅ Smoke captures saved

**Risk register** :
- Risk : Migration applique sur dirty data (orders existing) → mitigation : backup + pretend + spot-check
- Risk : Pusher prod creds incorrects → mitigation : polling fallback 5s + alert
- Risk : Queue worker oublié → mitigation : production guards throw, deploy doc

**Rollback** : `gh pr revert <merge-PR>` + restore mysqldump backup

---

## §3 — PHASE C : V1.0.1 hardening sprint (~7-8j-agent, budget Q4=A)

Décomposé en **4 batches de 2j-agent** :

### Batch C1 — Auth Hardening (J1-2)

**C1.1 — FormRequest authz refactor 88 endpoints (1-1.5j)**
- Migrer authz scattered controller-level → FormRequest::authorize()
- Reusable trait `AuthorizesPosOperator`, `AuthorizesAdmin`, `AuthorizesBranchManager`
- Tests régression policy-driven

**C1.2 — Password policy min:12 + complexity (0.5j)**
- ChangePasswordRequest + CustomerRequest + UserRequest min:12
- Pattern enforce: upper + digit + special
- Migration password_policy_v2 si existe

**C1.3 — Sanctum TTL sensitive ops 8h → 1h (0.5j)**
- KioskMachineLoginController : keep 480 min (kiosk hot path)
- New short-lived token for admin sensitive ops (payment refund, fiscal Z close, etc.)
- `config/sanctum.php` add `sensitive_token_ttl_minutes => 60`

**C1.4 — API key versioning (1j)**
- Add `api_keys` table avec `key_version`, `revoked_at`
- Middleware `VerifyApiKey` check version + revocation
- Console command `foodking:api:rotate-key`

**Sub-agent assignment** : 2 agents Security + Tester en parallèle

**Acceptance** :
- ✅ FormRequests::authorize() concrets sur 88 endpoints
- ✅ Password tests min:12 + complexity verts
- ✅ Sanctum TTL configurable env
- ✅ API key versioning testé (rotate + revoke)

---

### Batch C2 — Sync remaining (J3-4)

**C2.1 — Frontend correlation_id dedup cache 120s (0.5j)**
- `resources/js/composables/useEventDedup.js` — Map cache TTL 120s
- Hook dans `useOnEvents.js` listener handlers
- Reject si correlation_id déjà vu

**C2.2 — Listener idempotency catch-up V1.0.1 (0.5j si reste après A2)**
- Si A2 a couvert les 6 → skip
- Sinon : finaliser refactor

**C2.3 — Admin polling 60s → 10s adaptive (0.5j)**
- KDS sync service : si WS down > 5s, polling adaptive
- Reset 60s si WS reconnecté
- Metric `kds.polling_interval_ms`

**C2.4 — Contract REQUIRED_PAYLOAD_KEYS hardening (0.5j)**
- `app/Domain/Events/EventContract.php` validation strict
- Locked enum REQUIRED_PAYLOAD_KEYS per event_type
- Test régression contract violation → fail fast

---

### Batch C3 — UI + i18n (J5-6)

**C3.1 — KDS limit-50 overflow flag UI (0.5j)**
- API response `{orders, hasMore: bool, total_pending}`
- Vue UI badge "X+ commandes en attente, voir plus"
- Pagination optionnelle

**C3.2 — i18n cleanup KitchenDisplaySystemComponent.vue 68 raw strings (1j)**
- Extract vers `kds.*` namespace
- Locales fr/en/ar
- Tests Vitest régression i18n keys

**C3.3 — A11y additional landmarks (0.5j)**
- POS PosComponent.vue role="application" + landmarks
- Admin dashboards role="main" cohérent
- Vitest a11y assertions

---

### Batch C4 — Performance + observability (J7-8)

**C4.1 — DB index `orders(payment_status, fiscal_seq, fiscal_alloc_error_at)` (0.25j)**
- Migration `2026_05_XX_add_perf_index_orders_fiscal_retry.php`
- Verify retry cron query plan EXPLAIN

**C4.2 — Cash drawer Cache::lock prevention (0.25j)**
- CashDrawerService::open + close wrap Cache::lock branch_id
- Race test multi-cashier double-click

**C4.3 — Latency SLI metrics (1j)**
- Prometheus exporters: `kiosk.payment_confirm.latency_ms`, `outbox_dispatch.latency_p95_ms`, `kds.list.latency_ms`
- Datadog / Sentry breadcrumbs
- Alerting si p95 > 2s

**C4.4 — `/api/sync/status` monitoring endpoint (0.5j)**
- Returns `{outbox_pending, outbox_failed, last_dispatch_latency_ms, polling_fallback_active, fiscal_orphan_count}`
- Auth admin only
- Dashboard ops integration

**C4.5 — Tests régression V1.0.1 + audit final (0.5j)**
- Cumulative tests + E2E
- Audit final via 5 sub-agents YC GStack (cf iter15 méthode)
- Verdict CLEAN/HEAL/BLOCK pre-V1.0.1 release

---

## §4 — PHASE D : V1.x backlog post-V1.0.1 (long-term roadmap)

| Item | Effort | Priorité | Track |
|---|---|---|---|
| F-016b stock dashboard UI (Q3=A) | 5-7j | Important | V1.x |
| 17 advisories security composer triage | 2j | Important | V1.x security |
| - LOW firebase/php-jwt CVE-2025-45769 | 0.25j | Low | |
| - MEDIUM laravel/framework CVE-2025-27515 | 0.5j | Medium | |
| - MEDIUM psy/psysh CVE-2026-25129 | 0.25j | Medium | |
| Stripe webhook idempotency (parité SenangPay) | 1j | Important | V1.x |
| SenangPay webhook handler implementation | 1j | Required (post creds) | V1.x |
| Laravel 9 → 10 migration | 5j | Important | Track séparé |
| Laravel 10 → 11 migration | 3j | Important | Track séparé |
| Spatie permissions 5 → 6 | 2j | Medium | Track séparé |
| ESLint v10 + Vue plugin setup | 2j | Medium | DX |
| Saga pattern Order + Payment + Stock | 5-8j | Strategic | V1.x |
| Hardware Security Module (HSM) HMAC | 5j | Compliance grade | V2 |
| Distributed tracing OpenTelemetry | 3j | Observability | V1.x |
| Zero-trust network mTLS POS↔Kiosk↔Admin | 8j | Security advanced | V2 |

**Total V1.x estimé** : ~40-50j-agent réparti sur 6-9 mois.

---

## §5 — Risk register cross-phases

| Risk | Phase | Severity | Mitigation |
|---|---|---|---|
| FormRequest authz refactor casse endpoints existants | C1 | HIGH | Tests régression policy-driven complet, deploy par batch 20 endpoints |
| Migration UNIQUE idempotency_key collisions sur prod data legacy | A2 | HIGH | Pretend + dry-run staging snapshot + idempotency_key NULL fallback |
| Pusher prod creds non testés | B5 | MEDIUM | Polling fallback 5s + alert si dispatch p95 > 5s |
| Queue worker oublié deploy | B5 | HIGH | Production guards throw + supervisor systemd config doc |
| FR-lock fix retire un flow legacy d'init | A1 | MEDIUM | Manual smoke test + tests Vitest régression |
| 17 advisories security delay → exploits prod | D | MEDIUM | Triage week 1 post V1.0.1, prioritize CRITICAL |
| Laravel 9 EOL → security patches stop | D | MEDIUM | Track séparé V1.x roadmap, ne pas bloquer V1 |

---

## §6 — Acceptance criteria global

### V1 GO-LIVE
- ✅ 2 P1 critiques fixed (FR-lock + 6 listeners)
- ✅ Tests cumulative ~700+ verts
- ✅ Frozen-zones strict 4/4 = 0 diff
- ✅ Backup mysqldump prod
- ✅ Migrate --pretend staging OK
- ✅ Smoke test live captures
- ✅ Merge → main + deploy
- ✅ 0 errors Sentry first hour

### V1.0.1 RELEASE (~7-8j post V1)
- ✅ FormRequest authz consolidated 88 endpoints
- ✅ Password min:12 + complexity
- ✅ Sanctum TTL sensitive 1h
- ✅ API key versioning
- ✅ Frontend correlation_id dedup
- ✅ Admin polling adaptive
- ✅ KDS overflow flag UI
- ✅ i18n KDS cleanup
- ✅ DB perf index fiscal retry
- ✅ Cash drawer Cache::lock
- ✅ Latency SLI metrics + /api/sync/status
- ✅ Tests régression CLEAN audit final

### V1.x (long-term)
- ✅ F-016b stock dashboard UI
- ✅ Security advisories triagées (17 → 0)
- ✅ Laravel 9→10→11 migrated
- ✅ Spatie 5→6 migrated
- ✅ ESLint + Saga pattern
- ✅ Distributed tracing + HSM (V2 compliance)

---

## §7 — Decision gates owner (à valider)

### Gate 1 — Pre-Phase A (now)
**Décision** : Apply 2 P1 critiques (FR-lock + 6 listeners) avant merge V1 ?
- 🔴 Option A *(recommandé)* : OUI — apply maintenant (~1.25j-agent), V1 ship-ready après
- 🟡 Option B : NOPE — accept HEAL risk V1 ship maintenant + V1.0.1 fix
- 🟢 Option C : NOPE FR-lock seul (P1a critique compliance), defer 6 listeners V1.0.1 (P1b risk reduit)

### Gate 2 — Pre-Phase B (après A done)
**Décision** : V1 GO-LIVE direct ou attente ?
- 🟢 GO si tests verts + smoke test OK + backup ready
- 🟡 WAIT si nouveaux risques découverts pendant Phase A

### Gate 3 — Pre-Phase C (après V1 deployed stable)
**Décision** : Lancer V1.0.1 sprint immédiatement ou pause observation ?
- 🟢 Lance C1 (Auth) immédiatement après V1 stable 1 semaine
- 🟡 Pause si production incidents non-prévus

### Gate 4 — Pre-Phase D (après V1.0.1 release)
**Décision** : Priorisation V1.x backlog ?
- F-016b stock dashboard (Q3=A budget) vs Security advisories vs Laravel migration ?

---

## §8 — Sub-agents YC GStack assignment summary

| Phase | Sub-agents | Rôles | Effort total |
|---|---|---|---|
| A1 (FR-lock) | 1× A11y+i18n | A11y + Tester | 0.25j |
| A2 (6 listeners) | 1× Architect+Tester | Architect + Tester | 1j |
| A3 (tests) | 1× Tester | Tester + SRE | 0.25j |
| B (deploy) | Owner + 1× SRE support | SRE | 0.5j-1j |
| C1 (auth) | 2× Security + Tester en parallèle | Security + Tester | 2j |
| C2 (sync) | 1× Architect+Tester | Architect + Tester | 2j |
| C3 (UI) | 1× A11y+i18n+Tester | A11y + Tester | 2j |
| C4 (perf) | 1× DBA+SRE+Tester | DBA + SRE + Tester | 2j |
| D (V1.x) | Selon priorisation Gate 4 | All roles | 40-50j |

**Total Phase A+B+C** : ~9-10j-agent → V1.0.1 production-grade complet.

---

## §9 — Conclusion

**FoodKing V1 est SOLID** (audit iter15 : 0 P0, 16 domaines validés). 2 P1 critiques (~1.25j) entre nous et le merge V1.

**Roadmap claire** :
- **Cette semaine** : Phase A (P1 fixes) + Phase B (V1 GO-LIVE)
- **Semaines 2-3** : Phase C (V1.0.1 hardening sprint 8j-agent)
- **Mois 2-9** : Phase D (V1.x backlog 40-50j)

**Discipline LOOP §5 respectée** : ce plan est généré en TYPE A (audit/plan only) → écrit dans BRAIN.md §4 → STOP + ask owner gate validation.

— *ULTRA-PLAN v1 généré 2026-05-09 par Claude single-agent en mode multi-perspective YC GStack. Owner doit valider Gate 1 pour démarrer Phase A.*

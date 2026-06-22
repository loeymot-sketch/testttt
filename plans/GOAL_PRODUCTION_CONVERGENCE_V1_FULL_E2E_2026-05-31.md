# GOAL — Production Convergence V1 Le Cayenne — Full E2E + Abuse-E2E + Rush Test

**Goal ID:** `GOAL_PRODUCTION_CONVERGENCE_V1_FULL_E2E_2026-05-31`
**Owner:** Le Cayenne, Marseille — V1 LOCAL personnel
**Author:** Claude (orchestrateur + cerveau projet)
**Baseline HEAD:** `a928ee88d` (post-VAT10 + F1 reactivation)
**Date:** 2026-05-31
**Status:** PLAN ONLY — DO NOT EXECUTE without owner explicit GO

---

## §0 — Mission & Scope

### 0.1 Mission (one sentence)

Prouver, empiriquement, qu'au commit baseline le système FoodKing V1 Le Cayenne reste **fiscalement correct (NF525)**, **fonctionnellement complet** sur les 15 systèmes (10 visibles + 5 infra), **synchrone cross-surface (≤ 1s p95)**, **résistant à l'heure de rush (50-200 cmd/min, race-safe)**, **intègre historiquement et en data (audit chain + composition snapshots + branch isolation)**, et **résilient à l'abus** (state-machine forbidden transitions, fraude prix/remise, IDOR, idempotency replay, burst concurrent) — sous quadruple-méthodo `test-e2e + abuse-e2e + GStack + adversarial`, jusqu'à convergence stable `P0+P1=0` deux rounds consécutifs.

### 0.2 Scope IN

- **Systèmes opérationnels visibles (10)** : Kiosk borne · POS caisse · KDS cuisine · OSS écran client · Admin dashboard · Historique unifié · Encaissement unifié · Stock (rupture/86) · Livreur (DeliveryBoy) · Sync cross-surface
- **Systèmes infra (5)** : NF525 fiscal chain · Branch multi-tenant · Authz (Sanctum + Spatie) · Pricing SSOT · Idempotency (HTTP + DB)
- **Frontends standalone (2, hors-scope-wire-V1)** : Mobile RN + Web `/Users/1millnonstop/Downloads/web/` — visuel/parité only, **AUCUN wire backend testttt**
- **Cross-cutting** : VAT-10 TTC (la base post-fix F1) · Remises live (coupon + fidélité, post-reactivation) · Kill-switch `POS_MANUAL_DISCOUNT_ENABLED=false` resilience · Refund (status-flip + counter-entry mirrors) · Z aggregation identity (`total_tva == Σ total_by_tax_rate` EXACT)

### 0.3 Scope OUT (refus explicite)

- Cloud / SaaS multi-tenant / VPS / ALB → **future, non V1** (cf. `feedback_no_cloud_until_owner_initiates.md`)
- Wire mobile/web standalone aux APIs → **owner mandate**
- Refactor de zones frozen (§7 CLAUDE.md) → **owner-gate + LOCK obligatoire**
- Push vers `origin/*` → **owner explicit only**
- Auto-flip de flags fiscaux production → **owner explicit only**
- Migration Laravel 9→10/11 → **track séparé**
- F-016b stock dashboard UI → **V1.x backlog**

### 0.4 Non-goals (anti-drift)

- **Pas de "nice-to-have" refactor** : test-only, fix-only en réponse à un finding réel, file:line cité.
- **Pas de nouvelle feature** : convergence ≠ ajout fonctionnel.
- **Pas de scope creep adversarial** : un finding adversarial = real-only OR refuted-with-evidence ; pas de "trouvons-en plus pour être thorough".
- **Pas d'invention** : SSOT items table / config/menu.php / kiosk.menu_pricing — JAMAIS inventer un produit, un prix, une couleur, un endpoint.

---

## §1 — Decomposition système × test dimension

### 1.1 Matrice de couverture (15 × 7)

Pour chaque système, **7 dimensions** doivent toutes être prouvées GREEN avant verdict système :

| Dimension | Définition | Outil principal |
|---|---|---|
| **D1. Happy-path** | Le golden path exécute end-to-end sans erreur | Playwright + PHPUnit Feature |
| **D2. Edge cases** | Empty, max-qty, stock-out, payment-fail, retries | PHPUnit + Playwright |
| **D3. Abuse / adversarial** | Forbidden transitions, fraude, IDOR, replay, burst | abuse-e2e + adversarial agents |
| **D4. Sync cross-surface** | Une action sur surface X → effet visible sur Y ≤ 1s p95 | Playwright multi-context + WS measure |
| **D5. Historique + audit** | Chaque action persiste correctement, append-only, gap-free | PHPUnit Fiscal + Audit grep |
| **D6. Data integrity** | Composition snapshots, prix SSOT, branch isolation, NF525 chain | PHPUnit Sentinels + chain verify |
| **D7. Visual + a11y** | Capture Playwright lue par moi (Read tool), pas juste prise | Playwright + manual screenshot read |

### 1.2 Systèmes × dimensions (matrice de priorité)

```
                D1   D2   D3   D4   D5   D6   D7
Kiosk           ★★★  ★★★  ★★★  ★★★  ★★   ★★★  ★★★
POS             ★★★  ★★★  ★★★  ★★★  ★★★  ★★★  ★★
KDS             ★★★  ★★★  ★★★  ★★★  ★★   ★★   ★★
OSS             ★★   ★★   ★★   ★★★  -    -    ★★
Admin           ★★★  ★★★  ★★   ★★   ★★   ★★★  ★★
Historique      ★★★  ★★★  ★★   ★★   ★★★  ★★★  ★★
Encaissement    ★★★  ★★★  ★★★  ★★★  ★★★  ★★★  ★★
Stock           ★★   ★★★  ★★   ★★★  ★★   ★★★  ★★
Livreur         ★★   ★★★  ★★★  ★★   ★★   ★★   ★★
Sync            ★★★  ★★★  ★★★  ★★★  -    ★★★  -
NF525           ★★★  ★★★  ★★★  -    ★★★  ★★★  -
Branch isol.    ★★★  ★★★  ★★★  -    -    ★★★  -
Authz           ★★★  ★★★  ★★★  -    -    ★★★  -
Pricing SSOT    ★★★  ★★★  ★★★  ★★   ★★★  ★★★  -
Idempotency     ★★★  ★★★  ★★★  ★★★  ★★   ★★★  -
```
★★★ = must-prove, ★★ = should-prove, - = N/A

### 1.3 Surfaces (URLs canoniques V1)

```
http://127.0.0.1:8000/kiosk/idle                — Kiosk borne accueil
http://127.0.0.1:8000/kiosk/categories          — Kiosk catalogue
http://127.0.0.1:8000/kiosk/wizard/<item>       — Kiosk wizard
http://127.0.0.1:8000/kiosk/cart                — Kiosk cart (← Q2 UI loyalty/promo)
http://127.0.0.1:8000/kiosk/upsell              — Kiosk upsell
http://127.0.0.1:8000/kiosk/loyalty             — Kiosk loyalty entry
http://127.0.0.1:8000/kiosk/payment             — Kiosk payment
http://127.0.0.1:8000/kiosk/confirmation        — Kiosk receipt
http://127.0.0.1:8000/admin/pos                 — POS V4 caisse (frozen wizard)
http://127.0.0.1:8000/admin/dashboard           — Admin dashboard
http://127.0.0.1:8000/admin/historique          — Historique unifié
http://127.0.0.1:8000/admin/encaissement        — Encaissement unifié (cash+carte)
http://127.0.0.1:8000/admin/items               — Catalogue
http://127.0.0.1:8000/admin/stock-rupture-dashboard — Stock 86
http://127.0.0.1:8000/admin/order-status-screen — OSS écran client
http://127.0.0.1:8000/admin/observability       — Observability dashboard
http://127.0.0.1:8000/kds                       — KDS cuisine
http://127.0.0.1:8000/login                     — Login admin
```

### 1.4 Personas (auth × branch)

| Persona | Login | Branch | Ability | Usage |
|---|---|---|---|---|
| Admin | `admin@lecayenne.fr / 123456` | 0 (super) | `*` | Tests cross-branch + escalations |
| Branch Manager | (factory) | 1 | manager perms | Discount > 10% approval |
| POS Operator | (factory) | 1 | `pos-discount-up-to-10` | POS rush, encaissement |
| Chef | `chef@lecayenne.fr / test1234` | 1 | kds:read+bump | KDS sync, persona cuisine |
| Kiosk machine | (factory `KioskMachine`) | 1 | `kiosk:order` | Kiosk flows |
| Customer (auth) | (factory) | nullable | tokenCan kiosk:order | Customer-facing kiosk/web |
| Guest (anon) | — | 0 | `auth_token` w/ `kiosk:order` | IDOR probes |
| Livreur | (factory `DeliveryBoy`) | 1 | livreur perms | Cash session, delivery |

---

## §2 — Wave plan (sequential by risk, parallel inside wave)

> **Discipline** : chaque wave a un **gate explicite** (must-be-green) avant la suivante. Healing rule = 3 cycles max sur le même finding ; au-delà → escalate owner.

### Wave A — Pre-flight & baseline capture (durée ~30 min, série)

**A0. Hygiene physique**
- A0.1 Disk : `df -h /private/tmp` doit montrer ≥ 5 Gi libres ; sinon `rm -rf .playwright-mcp` + cleanup task tmp.
- A0.2 Browser session : vérifier qu'aucune autre session Claude ne détient le profil `mcp-chrome-9b44929` (sinon arbitrer via owner ou utiliser `--isolated`).
- A0.3 Dev server : `curl http://127.0.0.1:8000/kiosk/idle` doit retourner `200` ; sinon `php artisan serve` background.
- A0.4 Bundle : `npx mix --production` une fois en début pour figer le bundle ; aucun rebuild inter-wave sauf source change.

**A1. Baseline gates (capture immutable, sert de reference comparators)**
- A1.1 PHP suite : `php artisan test 2>&1 | tee reports/test-e2e/$GOAL/baseline_php.log` → exiger `passed/failed = 2755/0` (ou supérieur).
- A1.2 Vitest : `npx vitest run 2>&1 | tee reports/test-e2e/$GOAL/baseline_vitest.log` → exiger `1879/0`.
- A1.3 NF525 chain : `php artisan fiscal:verify-chain --all 2>&1 | tee reports/test-e2e/$GOAL/baseline_chain.log` → exiger `CHAIN OK on every active branch`.
- A1.4 Frozen-zone diff : `git diff --stat HEAD -- <13 §7 files>` → exiger empty.
- A1.5 Z-membership : `php artisan fiscal:verify-z-membership 2>&1 | tee reports/test-e2e/$GOAL/baseline_zmember.log`.
- A1.6 Captures baseline screenshots (18 surfaces × 3 viewports `1920×1080`, `768×1024`, `375×667`) → `reports/test-e2e/$GOAL/screenshots/baseline/`.

**A2. State snapshot (Graphiti episode)**
- `mcp__graphiti__add_memory` group=`foodking` : episode "GOAL convergence baseline at $HEAD, gates: php=$X, vitest=$Y, chain=OK, frozen=0".

**A3. Risk register (init)**
- Liste pré-connue (cf. §13) ; à chaque wave, append nouveaux risques découverts.

**A4. Gate Wave A → Wave B** : tous les baselines logged + frozen-zone diff = 0 + chain OK. Sinon STOP + escalate.

---

### Wave B — Per-system happy-path (test-e2e + GStack, parallèle 5 agents)

**Objectif** : pour chaque système, prouver que le golden path fonctionne post-baseline (régression de surface).

**B1. Dispatch (5 sub-agents read-only via Workflow + Skill `test-e2e`)**

| Agent | Système | Outputs |
|---|---|---|
| `gstack-kiosk` | Kiosk borne (8 surfaces) | Playwright capture pre-checkout, payment flow CASH + simulation_hardware, screenshots × 8 × 3 viewports |
| `gstack-pos` | POS caisse | Playwright capture POS V4 wizard (FROZEN — read only), encaissement borne flow, screenshots × 4 |
| `gstack-kds-oss` | KDS + OSS | Playwright multi-context : POS create → KDS new card visible ≤ 1s + OSS reflects "En préparation" |
| `gstack-admin-hist` | Admin + Historique + Encaissement | Capture des 6 admin pages + 1 historique paid order + 1 encaissement borne |
| `gstack-sync-stock` | Sync + Stock + Livreur | Stock 86 toggle visible KDS + OSS ≤ 1s ; livreur cash session open/close |

Each sub-agent runs the `test-e2e` skill (2-team) + emits structured JSON findings.

**B2. Schema findings (JSON, enforced via Workflow `agent({schema})`):**
```json
{
  "system": "Kiosk|POS|KDS|...",
  "happy_paths_tested": [{"path": "...", "ok": true|false, "screenshot": "...", "evidence_file_line": "..."}],
  "p0_findings": [{"id":"K0-01","title":"...","file_line":"...","repro":"...","severity":"P0|P1|P2|P3"}],
  "console_errors": [], "network_4xx_5xx": []
}
```

**B3. Gate Wave B → Wave C** : 0 P0 + 0 P1 sur happy-paths × 5 systèmes. P2/P3 = backlog, non-bloquant.

---

### Wave C — Per-system adversarial probes (abuse-e2e, 6 angles parallèles)

**Objectif** : casser le système intentionnellement. Default `real=false` sauf reproduction file:line ; cross-validation 2+ agents pour escalate à P0.

**C1. Six lentilles adversariales (workflow, JS-synth pas de LLM judge)**

| Lens | Description | Schema findings |
|---|---|---|
| **C-L1. state-machine** | Forbidden transitions sur chaque endpoint changeStatus (forward illégal, backward, zombie-revive, garbage code 999, terminal reason non-whitelist) | `transition`, `expected_response`, `actual` |
| **C-L2. idempotency** | Replay même `X-Idempotency-Key` sur POST mutating (kiosk order, POS confirm, change-status, refund) | `endpoint`, `replay_response`, `state_diverged` |
| **C-L3. fraude prix/remise** | Forge subtotal/total/discount client → server DOIT recalculer | `field_forged`, `client_sent`, `server_persisted` |
| **C-L4. IDOR + authz** | Guest token w/ `kiosk:order` → redeem foreign code (cf. SEC-P1 LOYALTY-IDOR), cross-branch order access | `auth_token`, `target_resource`, `accessible` |
| **C-L5. burst concurrent** | ×5/10 simultaneous PENDING→ACCEPT, ×3 simultaneous bump, ×3 confirm-counter → race-safe attendu | `burst_size`, `state_after`, `duplicates` |
| **C-L6. F1 kill-switch** | `Config::set('pos.manual_discount_enabled', false)` runtime → coupon + loyalty + pre-redeem refusés ; flip back → live | `switch_state`, `gate_engaged` |

**C2. Verify pass (each finding ×3 skeptics, default refuted=true)**
- Pour chaque P0/P1 candidat : 3 sub-agents adversaires hostiles, framing "REFUTE this", majority vote ≥2 refute → REJECTED.

**C3. Gate Wave C → Wave D** : 0 nouveau P0 confirmé (cross-validated). P1 = healing wave inline OR doc + owner escalate.

---

### Wave D — Cross-surface synchronization + rush-hour (multi-context, parallèle 4)

**Objectif** : prouver que sous charge concurrente, les surfaces restent cohérentes.

**D1. Sync latency budget (mesure empirique)**

| Action | Source | Target | Budget p95 |
|---|---|---|---|
| Kiosk paid order created | Kiosk | KDS new card | ≤ 1s |
| Kiosk paid order created | Kiosk | OSS "En préparation" | ≤ 1s |
| KDS bump PREPARED | KDS | OSS "Prêt" | ≤ 1s |
| POS create paid | POS | KDS | ≤ 1s |
| POS counter-collect cash | POS | Historique status update | ≤ 2s |
| Stock 86 toggle | Admin | KDS/Kiosk visibility | ≤ 2s |
| Refund counter-entry | Admin | Historique negative row | ≤ 2s |

Mesure : Playwright multi-context (2 navigateurs en parallèle) + `Date.now()` au moment de l'action source vs apparition target.

**D2. Rush-hour profile (3 niveaux progressifs)**

| Niveau | Charge | Outil | Critères |
|---|---|---|---|
| **R1 calme** | 10 orders/min × 5 min | `kiosk:simulate-orders --rate=10/min --duration=5min` | 0 dup, 0 stale, 0 leak, fiscal_sequence gap-free |
| **R2 rush** | 50 orders/min × 10 min | idem `--rate=50` | idem + WS no-drop, polling-fallback OK |
| **R3 stress** | 100-200 orders/min × 5 min | idem `--rate=200` | KDS overflow banner appears at threshold, OSS still updates, audit_chain still gap-free |

Invariants pendant rush (assertions PHPUnit + Playwright) :
- ✓ `audit_logs` chain gap-free post-burst
- ✓ Zero `fiscal_sequence_no` gap (verify-chain)
- ✓ Zero duplicate idempotency key
- ✓ Zero order in undefined state
- ✓ `lockForUpdate` paths atomic (no double-bump, no double-pay)
- ✓ Pricing SSOT : every order's `total` = recomputed from items (no client trust)

**D3. WS degradation (simulate Pusher down)**
- D3.1 Disable Pusher (env trick) → polling fallback kicks in.
- D3.2 KDS poll interval = `degradedBaseMs` (5s) when WS down.
- D3.3 OSS poll = `intervalMsWhenDisconnected` (2s).
- D3.4 Confirm KDS/OSS still updates ≤ poll-interval + 1s.

**D4. Sub-agent dispatch (4 parallèles)**
- `gstack-rush-r1`, `gstack-rush-r2`, `gstack-rush-r3`, `gstack-ws-degrade`
- Each measures empirical latencies + emits JSON `{action, source_ts, target_ts, delta_ms, breaches}`.

**D5. Gate Wave D → Wave E** : p95 ≤ budget × 7 measures + R3 stress passed + WS-degrade passed + chain gap-free post-burst.

---

### Wave E — Historique + data integrity + NF525 chain (parallèle 5)

**Objectif** : prouver que ce qui s'est passé est gravé correctement et reste auditable.

**E1. Domaines (5 parallèles)**

| Agent | Surface | Assertions |
|---|---|---|
| `gstack-audit-chain` | `audit_logs` + `z_reports` chain | HMAC chain gap-free post-rush ; DB trigger BEFORE DELETE works (MySQL prod sim) ; GRANT REVOKE in Ansible task ; 6y retention column present |
| `gstack-fiscal-z` | NF525 Z aggregation | Z close after rush : `total_tva == Σ total_by_tax_rate` EXACT (post-F1 fix invariant) ; `total_ttc == total_ht + total_tva` EXACT ; refund mirrors net ; counter-deferred orders included; orphaned paid alarm |
| `gstack-history` | Historique unifié `/admin/historique` | All origins (Borne/Caisse/walk-in/livraison/online) badged ; fiscal_sequence_no column ; refund link ; parent_order_id chain ; filter combinations ; 403 cross-branch enforced |
| `gstack-composition-snapshot` | OrderItem `composition_snapshot` | Every paid order has snapshot ; immutable (UPDATE forbidden test) ; allergen snapshot present ; reorder uses snapshot pricing not current |
| `gstack-branch-isolation` | BranchScope sentinel | 20 models scoped ; baseline-locked ; exempted models documented ; admin (branch_id=0) bypass ; staff scoped ; pre-auth lookups use `withoutGlobalScope` explicitly |

**E2. Assertion patterns**

For each `gstack-*` agent, output schema:
```json
{
  "domain": "audit-chain|fiscal-z|history|...",
  "invariants_verified": [{"id":"INV-01","name":"chain gap-free","ok":true,"evidence":"php artisan fiscal:verify-chain output line N"}],
  "violations": [{"severity":"P0|P1","invariant":"INV-XX","file_line":"...","repro":"..."}]
}
```

**E3. Gate Wave E → Wave F** : 0 P0 violations + invariants × 5 all GREEN + chain still OK after Wave D rush.

---

### Wave F — Cross-cutting non-functional (parallèle 4)

**Objectif** : sécurité, accessibilité, performance, observabilité.

**F1. Quatre dimensions**

| Agent | Focus | Tools |
|---|---|---|
| `gstack-security` | Authz matrix, secrets hygiene, CORS, CSRF, XSS, SQL injection, rate-limit | Sentinel tests + `grep` for `Auth::user()` `tokenCan` patterns + `/security-review` skill |
| `gstack-a11y` | WCAG 2.1 AA on kiosk surfaces : contrast ≥ 4.5:1, keyboard nav, ARIA labels, focus traps | Playwright + axe-core injection + visual contrast checks |
| `gstack-perf` | Page load p95 < 3s, API p95 < 500ms, KDS first-paint < 2s, query N+1 hunt | DB query log + Lighthouse + Laravel Debugbar diff |
| `gstack-observability` | KDS overflow flag UI, observability dashboard, fiscal log channel, broadcast_completed_at | Verify each event hits observability dashboard ; fiscal log has structured fields |

**F2. Gate Wave F → Wave G** : 0 P0 security + 0 P0 a11y blocker + perf budget met + observability dashboard shows expected events.

---

### Wave G — Final convergence + sign-off (série)

**G1. Round 2 convergence (mandatory)**

Re-run waves B+C+E (the high-finding-density waves) once more. Convergence = **0 new P0/P1** across all 5 systems. If new finding → heal inline (cycle ≤ 3 healing rounds, per CLAUDE.md §10) or escalate owner.

**G2. Adversarial supervisor pass (independent)**

Launch ONE final adversarial supervisor agent (read-only) whose job is to dispute the entire convergence: re-grep cited file:lines, re-run the 6 abuse lenses, attempt one targeted bypass per system, output `{ disputed_findings: [...], confirmed_findings: [...], verdict: GO|NO-GO }`.

**G3. Convergence book**

Write `reports/test-e2e/$GOAL/CONVERGENCE_FINAL.md` (≥ 200 lines) with:
- Verdict (GO / NO-GO / GO-CONDITIONAL with explicit conditions)
- Per-system summary (15 systems × verdict)
- All confirmed P0/P1 healed (file:line + commit hash)
- All refuted findings (with refutation evidence)
- Empirical numbers (latencies, suite counts, chain hashes, rush results)
- Owner decisions if any
- Rollback plan if anything goes south

**G4. BRAIN update + Graphiti episode**

- `PROJECT_BRAIN.md` §2 : new entry with verdict + commit chain.
- Graphiti episode group=`foodking` : "GOAL_PRODUCTION_CONVERGENCE converged at HEAD $X, verdict $V, ..."

**G5. Owner gate**

Surface to owner via AskUserQuestion:
- Verdict displayed prominently
- Any GO-CONDITIONAL conditions enumerated
- Push/no-push decision (default: no push)
- Activation of any new feature flag (default: stay as committed)

---

## §3 — Per-system test specs (detailed)

> Pour chaque système : surfaces + fixtures + assertions + adversarial angles + rollback.

### 3.1 KIOSK BORNE

**Surfaces** : 8 (idle, categories, wizard, cart, upsell, loyalty, payment, confirmation).

**Happy paths** (D1)
- HP-K-01 : idle → categories → item simple → cart → payment CASH simulation → confirmation receipt + queue number generated + NF525 fiscal_sequence allocated.
- HP-K-02 : idle → categories → item composé (sandwich avec viande+crudité+sauce) → cart → payment CARD simulation → counter-deferred PENDING_COUNTER + COUNTER_DEFERRED.
- HP-K-03 : idle → loyalty (entrer code) → check returns discount_value > 0 → cart shows loyalty row → payment → discount persisted = pre-validated amount.
- HP-K-04 : idle → categories → bol composé (3 steps) → cart → payment → composition_snapshot persisted with all 3 steps.
- HP-K-05 : Q2 UI gate : `Config::set('pos.manual_discount_enabled', false)` → reload kiosk → loyalty button + promo form **hidden** (vitest both-states + Playwright visual capture).

**Edge cases** (D2)
- EC-K-01 : empty cart → "Payer" disabled.
- EC-K-02 : max-qty (20) per item enforced.
- EC-K-03 : stock-out item → not in catalog (or shown disabled).
- EC-K-04 : payment fails (simulate TPE decline) → order stays PENDING + payment_status UNPAID + retry path.
- EC-K-05 : kiosk locale FR-locked (ADR-007) ; no UI switcher.
- EC-K-06 : confirmation auto-return after 30s (config).
- EC-K-07 : counter-deferred order : finalize via cashier POS, fiscal_sequence allocated at counter-confirm not at create.

**Adversarial** (D3)
- ADV-K-01 : POST `/api/frontend/order` with forged `subtotal`, `total`, `discount` → server recalculates from items, persists DB values not client.
- ADV-K-02 : POST with invalid `coupon_id` → 422.
- ADV-K-03 : POST with invalid `loyalty_code` → loyalty not applied silently.
- ADV-K-04 : Replay POST with same `X-Idempotency-Key` → returns existing order, no double-create.
- ADV-K-05 : Burst ×10 POST → all unique orders, fiscal_sequence gap-free.
- ADV-K-06 : Guest token `kiosk:order` redeem foreign code → 403 (LOYALTY-IDOR fix).
- ADV-K-07 : Pre-redeem with kill-switch OFF → 422 before debit (Q3 gate).

**Sync** (D4)
- SY-K-01 : Kiosk paid CASH → KDS new card visible ≤ 1s (WS), ≤ 6s (degraded poll).
- SY-K-02 : Kiosk paid CASH → OSS "En préparation" ≤ 1s.
- SY-K-03 : Kiosk counter-deferred CARD → POS encaissement queue updated ≤ 2s.

**Visual** (D7)
- Capture each surface × 3 viewports → `Read` tool each PNG → assert no raw labels (`Label.X`, `kiosk.foo`, `0undefined`), no broken layout, no missing image (no `<span>` placeholder where `<img>` expected).

**Rollback** : N/A (kiosk = customer-facing, no DB writes outside the order; any P0 → heal + re-run HP).

---

### 3.2 POS CAISSE (V4 — frozen wizard)

**⚠️ Frozen** : `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` — **READ-ONLY**. Tests only ; no edit.

**Happy paths** (D1)
- HP-P-01 : login admin → POS → catalogue → ajout item simple → encaissement CASH → drawer open simulation → receipt + fiscal_sequence allocated immediately.
- HP-P-02 : POS → catalogue → ajout item composé via wizard → encaissement CARD simulation → PENDING_COUNTER (Plan B route-to-counter active).
- HP-P-03 : POS → catalogue → ajout discount manuel 5% → encaissement → discount persisted, gate not engaged because POS Operator has `pos-discount-up-to-10`.
- HP-P-04 : POS → catalogue → ajout coupon valide → encaissement → coupon applied SERVER-side (forged client value ignored).
- HP-P-05 : POS → encaissement borne (PENDING_COUNTER from kiosk) → confirm cash → fiscal_sequence allocated at counter-confirm not at kiosk create.
- HP-P-06 : POS → tranche split-payment (PaymentComponent FROZEN test only) → cash + card mix → multiple PaymentRecord rows, summed correctly.

**Edge cases** (D2)
- EC-P-01 : discount > 10% → Branch Manager approval required → 403 without approval.
- EC-P-02 : discount > 50% → Owner only.
- EC-P-03 : Parked order (PosParkedOrder) → resume → status preserved.
- EC-P-04 : Cash drawer session closed → encaissement CASH blocked.
- EC-P-05 : refund counter-entry → mirror order created with parent_order_id + RETURNED status + negative total.

**Adversarial** (D3)
- ADV-P-01 : POST `/api/admin/pos` with forged `discount` > permission → 422.
- ADV-P-02 : POS Operator try discount > 10% directly → 403.
- ADV-P-03 : POS create then forge order_id → cross-branch → 403 BranchScope.
- ADV-P-04 : Idempotency replay confirm-counter-payment → no double fiscal_sequence alloc (the GOAL_LOCK_FISCAL_WGS_Z6_P1 invariant).
- ADV-P-05 : POST with `pos_received_amount` < total → 422.
- ADV-P-06 : Kill-switch OFF + discount > 0 → 422.

**Sync** (D4)
- SY-P-01 : POS create paid → KDS ≤ 1s.
- SY-P-02 : POS counter-confirm cash → Historique status update ≤ 2s + Encaissement queue removed.

**Historique** (D5)
- HIS-P-01 : Every POS order appears in Historique unifié with origin badge "Caisse" or "Walk-in".

**Data integrity** (D6)
- DI-P-01 : POS order discount via coupon → `OrderCoupon` row created with server-validated discount.
- DI-P-02 : POS order PRICE = SSOT (PricingService) ; forged client price ignored.
- DI-P-03 : POS order composition_snapshot frozen at create.

**Visual** (D7)
- POS V4 wizard popup : FROZEN ; capture only for regression.

---

### 3.3 KDS CUISINE

**Happy paths** (D1)
- HP-KDS-01 : New paid order → card appears in PENDING column ≤ 1s.
- HP-KDS-02 : Bump "Accept" → ACCEPTED column ; ≤ 500ms perceptual.
- HP-KDS-03 : Bump "Prêt" → PREPARED ; OSS reflects "Prêt" ≤ 1s.
- HP-KDS-04 : Chef branch_id=1 sees only branch_id=1 orders (BranchScope).
- HP-KDS-05 : KDS overflow banner appears at threshold (configurable).

**Edge cases** (D2)
- EC-KDS-01 : double-click bump → idempotency replay → 409 Conflict on 2nd, single transition (the abuse-e2e proven case).
- EC-KDS-02 : Kiosk counter-deferred CARD does NOT appear in KDS until cashier confirms (gate `shouldDispatchNewOrderSignals`).
- EC-KDS-03 : Recall PREPARED → ACCEPTED (recall button) → audit log entry.
- EC-KDS-04 : Cancel ACCEPTED → CANCELED with whitelist reason (`customer_request`, `kitchen_reject`).

**Adversarial** (D3)
- ADV-KDS-01 : Forward illegal transition (PENDING → DELIVERED skip) → 422.
- ADV-KDS-02 : Backward transition (DELIVERED → ACCEPTED) → 422.
- ADV-KDS-03 : Garbage status code 999 → 422.
- ADV-KDS-04 : Zombie-revive (CANCELED → ACCEPTED) → 422.
- ADV-KDS-05 : Terminal reason free-text (non-whitelist) → 422 "reason not whitelisted".
- ADV-KDS-06 : Burst ×5 same change-status → race-safe, single state, 200×5 (idempotent).
- ADV-KDS-07 : Chef branch_id=1 try access branch_id=2 order → 404 BranchScope.

**Sync** (D4)
- SY-KDS-01 : KDS bump → OSS update ≤ 1s.
- SY-KDS-02 : KDS sees Echo WS event `OrderStatusChanged` ; polling fallback if WS down.

**Visual** (D7)
- Card layout : item compact, sticky CTAs, overflow banner honest.

---

### 3.4 OSS ÉCRAN CLIENT

**Happy paths** (D1)
- HP-OSS-01 : "En préparation" + "Prêt" columns ; mon ordre passe de l'une à l'autre selon status.
- HP-OSS-02 : Brand intact (Le Cayenne couleurs).
- HP-OSS-03 : Filter : KIOSK + TAKEAWAY only (DELIVERY excluded — fail-closed allowlist).

**Edge cases** (D2)
- EC-OSS-01 : empty state honest.
- EC-OSS-02 : long queue → scroll/pagination.

**Adversarial** (D3)
- ADV-OSS-01 : DELIVERY type → must NOT appear (fail-closed `whereIn(KIOSK, TAKEAWAY)`).
- ADV-OSS-02 : CANCELED/REJECTED → must NOT appear on customer wall.

**Sync** (D4)
- SY-OSS-01 : KDS bump → OSS reflect ≤ 1s.
- SY-OSS-02 : Polling fallback `intervalMsWhenDisconnected=2000` when WS down.

---

### 3.5 ADMIN (dashboard, items, settings, observability, stock)

**Happy paths** (D1)
- HP-A-01 : login admin → dashboard → KPIs visible (orders today, revenue, avg ticket, rush hour).
- HP-A-02 : `/admin/items` → catalogue 45 items Le Cayenne canonical visible.
- HP-A-03 : `/admin/observability` → events stream healthy.
- HP-A-04 : `/admin/stock-rupture-dashboard` → 86 toggle works.
- HP-A-05 : EOD PDF recap export OK.

**Edge cases** (D2)
- EC-A-01 : `permission:settings` enforced on admin-only routes.
- EC-A-02 : Branch Manager sees only branch_id=1.

**Adversarial** (D3)
- ADV-A-01 : Customer-role token → `/admin/*` 403.
- ADV-A-02 : Stale form submit → CSRF.
- ADV-A-03 : Admin tries cross-branch resource → BranchScope bypass via withoutGlobalScope only in admin contexts (audit-controlled).

**Data integrity** (D6)
- DI-A-01 : Dashboard avg_ticket dénominateur = paid orders only (W-D1 H-03 owner decision).
- DI-A-02 : Sales-report payés-seulement (H-03).

---

### 3.6 HISTORIQUE UNIFIÉ

**Happy paths** (D1)
- HP-H-01 : `/admin/historique` → table unified, all origins badged (Borne, Caisse, Walk-in, Livraison, Online).
- HP-H-02 : Filter by origin → only that origin shown.
- HP-H-03 : Filter by date range.
- HP-H-04 : Click order → details with composition snapshot + payment record + fiscal_sequence_no.

**Edge cases** (D2)
- EC-H-01 : Refund order shown with `parent_order_id` link + RETURNED status.
- EC-H-02 : 403 cross-branch (Branch Manager → branch_id=2 hidden).

**Adversarial** (D3)
- ADV-H-01 : Customer → `/admin/historique` 403.
- ADV-H-02 : Filter SQL injection → escaped properly.

**Historique** (D5)
- HIS-H-01 : Every order appears once (no dup, no leak).
- HIS-H-02 : Origin source_surface column always populated.

**Data integrity** (D6)
- DI-H-01 : OrderResource includes `fiscal_sequence_no`, `parent_order_id`, `source_surface`.

---

### 3.7 ENCAISSEMENT UNIFIÉ

**Happy paths** (D1)
- HP-E-01 : `/admin/encaissement` → queue de PENDING_COUNTER orders (kiosk + walk-in if delta-B enabled).
- HP-E-02 : Click order → `PosCounterCollectModal` → confirm cash → fiscal_sequence allocated + PAID.
- HP-E-03 : Confirm card → fiscal_sequence allocated + PAID.
- HP-E-04 : poll 20s + WS event for queue update.

**Edge cases** (D2)
- EC-E-01 : delta-B disabled (default) → walk-in NOT in queue.
- EC-E-02 : delta-B enabled (owner gate) → walk-in IN queue.

**Adversarial** (D3)
- ADV-E-01 : Idempotency replay confirm-counter → no double alloc.
- ADV-E-02 : Tamper `pos_received_amount` < total → 422.
- ADV-E-03 : Confirm already-PAID order → 409/422.

**Sync** (D4)
- SY-E-01 : Confirm-counter → Historique update ≤ 2s + Dashboard KPIs refresh.

---

### 3.8 STOCK + 86

**Happy paths** (D1)
- HP-S-01 : Toggle item 86 → visible KDS + Kiosk catalog disable.
- HP-S-02 : Stock decrement on paid order.
- HP-S-03 : Refund → stock increment.

**Edge cases** (D2)
- EC-S-01 : Concurrent decrement → race-safe via lockForUpdate.
- EC-S-02 : Auto-86 on stock=0 (dedup, the F4 case).

**Adversarial** (D3)
- ADV-S-01 : Forged stock value via API → 422.
- ADV-S-02 : Stock < 0 prevented.

**Sync** (D4)
- SY-S-01 : 86 toggle → all surfaces ≤ 2s.

---

### 3.9 LIVREUR (DeliveryBoy)

**Happy paths** (D1)
- HP-L-01 : Livreur login → cash session open.
- HP-L-02 : Order delivered → cash collected.
- HP-L-03 : End of shift → cash session close + reconciliation.

**Edge cases** (D2)
- EC-L-01 : Multi-order delivery → batch confirm.
- EC-L-02 : Order returned → refund flow.

**Adversarial** (D3)
- ADV-L-01 : Cross-livreur access → 403 BranchScope.

**Data integrity** (D6)
- DI-L-01 : DeliveryBoyCashSession + Movement ledger gap-free.

---

### 3.10 SYNC CROSS-SURFACE

**Happy paths** (D1)
- HP-SY-01 : Pusher WS connected.
- HP-SY-02 : Echo subscriptions per branch.
- HP-SY-03 : Polling fallback per surface (KDS, OSS, Encaissement).

**Edge cases** (D2)
- EC-SY-01 : WS reconnect after disconnect.
- EC-SY-02 : Token refresh proactive (2h before TTL expiry).

**Adversarial** (D3)
- ADV-SY-01 : Stale token used → 401 → refresh.
- ADV-SY-02 : Burst events → no event loss (outbox + listener dedupe).
- ADV-SY-03 : Pusher down 60s → polling kicks in.

**Sync** (D4)
- SY-SY-01 : End-to-end p95 ≤ 1s (cf. D1 budget table).

**Data integrity** (D6)
- DI-SY-01 : OutboxEvent listener replay dedupe.
- DI-SY-02 : broadcast_completed_at populated post-send.

---

### 3.11–3.15 INFRA SYSTEMS

**NF525 fiscal chain**
- HMAC chain gap-free (verify-chain `all`).
- Z aggregation post-rush : EXACT identity `total_tva == Σ total_by_tax_rate` (post-F1 invariant).
- DB trigger BEFORE DELETE on audit_logs + z_reports (MySQL prod sim).
- 6y retention column present.
- Refund counter-entry mirrors in breakdown (round-2 advisor refactor).

**Branch multi-tenant**
- 20 models with BranchScope baseline-locked sentinel.
- 12 exempted models documented (V1.0.2 backlog).
- Admin bypass (branch_id=0).
- Pre-auth lookups use withoutGlobalScope explicit.

**Authz**
- Sanctum `kiosk:order` ability checks in 8 controllers.
- Spatie `permission:settings` on admin routes.
- FormRequest authz drift sentinel (baseline-locked).
- Customer token IDOR (loyalty redeem foreign code) refused.

**Pricing SSOT**
- 100% backend recalc via PricingService.
- Composition snapshot frozen at create.
- Forged client total/subtotal/discount ignored.
- Coupon resolved server-side, not client.

**Idempotency**
- HTTP X-Idempotency-Key middleware on POST mutating.
- Scope = (branch_id, user_id, hash(key)).
- Dual-layer : cache + DB UNIQUE.
- 2xx-only replay.
- Conflict 409 if payload diff.
- webhook_events table UNIQUE (provider, webhook_id).

---

## §4 — Cross-cutting (toujours-en-toile-de-fond)

### 4.1 NF525 fiscal chain — never broken

Throughout all waves, **between every wave gate** run:
- `php artisan fiscal:verify-chain --all` → must remain `CHAIN OK`.
- `php artisan fiscal:verify-z-membership` → no orphans.

If breaks anywhere → STOP + escalate owner (this is the prison-time invariant).

### 4.2 Multi-rate VAT (NEW invariant post-F1)

Every Z aggregation must satisfy **EXACT** (no delta):
- `total_tva === array_sum(total_by_tax_rate)`
- `total_ttc === total_ht + total_tva`

Add periodic assertion in rush waves (after every R-burst, re-check).

### 4.3 Discount paths (now LIVE)

Each Wave (B/C/D/E) must include scenarios with:
- Non-zero discount via coupon (web/kiosk/POS/table)
- Non-zero discount via loyalty (kiosk + admin POS)
- Refund of discounted order (counter-entry mirror)
- Discount + multi-rate VAT combination

### 4.4 Kill-switch resilience

In Wave C, lens C-L6 must prove:
- Flag flip → all 5 dormancy paths refuse (gate at every chokepoint).
- Flag flip back → live again.
- Production .env override is the canonical kill-switch.

### 4.5 Frozen-zone protection

Between every wave gate :
- `git diff --stat HEAD -- <13 §7 files>` → empty.
- If non-empty without LOCK → STOP + escalate.

### 4.6 Visual mandate

Per CLAUDE.md §6 :
- Every UI change captured via Playwright.
- Screenshot read via Read tool (not just captured).
- No raw labels, no missing assets, no broken layouts.

---

## §5 — Rush-hour simulation détaillée

### 5.1 Profile R1 (calme) — détail

```
Duration: 5 min
Rate: 10 orders/min
Surface mix: 80% kiosk (8/min), 20% POS (2/min)
Item mix: 60% simple, 25% composé sandwich, 15% bol 3-step
Payment mix: 50% CASH simulation, 30% CARD simulation, 20% loyalty redeem
Discount mix: 70% no discount, 20% coupon, 10% loyalty
Order types: 70% KIOSK, 20% TAKEAWAY, 10% DINING_TABLE (table-order route)
```

Invariants asserted post-R1 :
- 50 orders created total ±2.
- 0 fiscal_sequence_no gap.
- 0 duplicate idempotency.
- 0 KDS dropped event.
- p95 sync latency ≤ 1s.
- Chain HMAC OK.

### 5.2 Profile R2 (rush) — détail

```
Duration: 10 min
Rate: 50 orders/min
Surface mix: 70% kiosk, 25% POS, 5% table-order QR
Item mix: same as R1
Payment mix: 60% CARD (more pressure on TPE simulation + counter-defer), 30% CASH, 10% loyalty
Discount mix: 60% none, 25% coupon, 15% loyalty
```

Additional invariants R2 :
- KDS no card dropped (manual check via outbox replay count).
- OSS reflects all PREPARED transitions.
- Encaissement queue drains as cashier confirms (manual confirm 1/3 of CARD orders).

### 5.3 Profile R3 (stress) — détail

```
Duration: 5 min (short, intentionally extreme)
Rate: 100→200 orders/min (ramp)
Surface mix: 90% kiosk, 10% POS
Payment mix: 70% CARD (max counter-defer pressure)
Discount mix: 50% none, 30% coupon, 20% loyalty
```

Additional invariants R3 :
- KDS overflow banner DOES appear at threshold.
- Polling-fallback engages if WS overloaded.
- No DB lock timeout > 10s.
- audit_chain still gap-free (the prison-time invariant under load).

### 5.4 Concurrent operations tested during rush

- ×5 simultaneous `change-status` on same order → idempotent.
- ×3 simultaneous `confirm-counter-payment` same order → single fiscal alloc.
- ×10 simultaneous kiosk `place order` same idempotency key → single create.
- Stock decrement race : 2 customers same last item → 1 wins, 1 422.

---

## §6 — Abuse-e2e angles détaillés (6 lentilles)

### 6.1 C-L1 state-machine

```
For each order-touching endpoint:
  - changeStatus PENDING → DELIVERED (skip ACCEPTED/PREPARED) → 422
  - changeStatus DELIVERED → ACCEPTED (backward) → 422
  - changeStatus CANCELED → ACCEPTED (zombie-revive) → 422
  - changeStatus status=999 (garbage) → 422
  - changeStatus terminal w/ reason="random text" (not whitelist) → 422
  - changeStatus terminal w/ reason="customer_request" → 200 CANCELED
```

### 6.2 C-L2 idempotency

```
For each POST mutating endpoint:
  - kiosk POST /api/frontend/order × 2 same X-Idempotency-Key → 1 order (same id)
  - POS POST /api/admin/pos × 2 same key → idem
  - confirm-counter-payment × 2 same key → 1 alloc
  - change-status × 2 same key (and same target status) → 200 idempotent, single transition
```

### 6.3 C-L3 fraude prix/remise

```
Each order endpoint with payload tampering:
  - subtotal: 999.00 (real items total 20.00) → server persists 20.00
  - total: 999.00 → server persists 20.00 (or 18.00 if coupon)
  - discount: 900.00 (real coupon yields 2.00) → server persists 2.00
  - coupon_id: invalid → 422
  - loyalty_code: invalid → applied=false, discount=0
  - item price tampering → server reads from items table, ignores client
```

### 6.4 C-L4 IDOR + authz

```
- Guest token (auth_token w/ kiosk:order, no KioskMachine row) tries redeem foreign loyalty code → 403
- Customer A token tries GET Customer B order → 404 BranchScope
- Branch Manager branch_id=1 tries any endpoint on branch_id=2 → 404
- POS Operator tries discount > 10% → 403
- Stale Sanctum token (TTL expired) → 401 + refresh flow
```

### 6.5 C-L5 burst concurrent

```
For each race-sensitive endpoint:
  - PENDING→ACCEPT ×5 simultaneous → final = ACCEPT (single transition), 5×200 OK
  - bump ×3 same order → single PREPARED, others 409
  - confirm-counter ×3 same order → single fiscal_sequence_no, others 409
  - kiosk create same idempotency ×10 → single order, others return cached
```

### 6.6 C-L6 F1 kill-switch

```
- Config::set('pos.manual_discount_enabled', false)
- POST kiosk order with coupon → 422 (gate engages)
- POST kiosk order with loyalty discount → 422 (gate engages)
- POST POS order with manual discount > 0 → 422
- POST table order with coupon → 422 (round-4 P0 path)
- POST /api/frontend/loyalty/redeem → 422 before debit (Q3 gate)
- Config::set('pos.manual_discount_enabled', true)
- All paths above → 200/201 with correct discount
```

---

## §7 — Visual + technical evidence per surface

### 7.1 Capture matrix

```
18 surfaces × 3 viewports = 54 baseline screenshots
Per surface, captured states:
  - empty / pristine
  - happy-path (mid-flow)
  - error/edge state
  - kill-switch-off (discount entries hidden)
  - kill-switch-on (discount entries visible)
```

Total : ~270 PNGs in `reports/test-e2e/$GOAL/screenshots/`.

### 7.2 Screenshot READ discipline

For EVERY PNG captured :
- Read via `Read` tool (Claude sees the image).
- Assert in convergence report :
  - No raw label (`Label.X`, `kiosk.foo`, `0undefined`)
  - No broken layout (responsive intact)
  - No missing asset (no `<span>` placeholder where `<img>` expected, no `naturalWidth==0`)
  - i18n resolved correctly (FR canonical)
  - Brand intact (Cayenne palette on backend surfaces, mobile palette on mobile)

### 7.3 Console + network cleanliness

For every Playwright capture session:
- `mcp__playwright__browser_console_messages level=error` → must be empty.
- `mcp__playwright__browser_network_requests` filter 4xx/5xx → only acceptable error responses (the 422s the abuse tests trigger).

---

## §8 — Agent dispatch table

### 8.1 Roster (15 sub-agents)

| Tier | Agent | Mandate | Schema |
|---|---|---|---|
| Wave B | gstack-kiosk | Kiosk D1+D7 | Schema-B |
| Wave B | gstack-pos | POS D1+D7 | Schema-B |
| Wave B | gstack-kds-oss | KDS+OSS D1+D7 | Schema-B |
| Wave B | gstack-admin-hist | Admin+Historique D1+D7 | Schema-B |
| Wave B | gstack-sync-stock | Sync+Stock+Livreur D1+D7 | Schema-B |
| Wave C | adv-statemachine | C-L1 | Schema-Adv |
| Wave C | adv-idempotency | C-L2 | Schema-Adv |
| Wave C | adv-fraude | C-L3 | Schema-Adv |
| Wave C | adv-idor-authz | C-L4 | Schema-Adv |
| Wave C | adv-burst | C-L5 | Schema-Adv |
| Wave C | adv-killswitch | C-L6 | Schema-Adv |
| Wave C | adv-verify | ×3 skeptics per finding | Schema-Verdict |
| Wave D | rush-r1+r2+r3 | Latency + invariants | Schema-Rush |
| Wave D | ws-degrade | Polling fallback | Schema-Rush |
| Wave E | E1-E5 (5 agents) | Audit/Z/Hist/Snapshot/Branch | Schema-Inv |
| Wave F | F1-F4 (4 agents) | Sec/A11y/Perf/Obs | Schema-Cross |
| Wave G | sup-adversaire | Final dispute | Schema-Verdict |

### 8.2 Verification gates (file:line discipline)

For EVERY sub-agent finding:
- `file:line` required.
- Re-grep mandatory (verify before report skill).
- `repro` step (curl trace, DB query, DOM extract) required.
- Without these → finding REJECTED.

### 8.3 Token / concurrency budget

- Max 16 concurrent agents per workflow phase (Workflow tool default).
- Per-agent token cap : ~50k output.
- Total wave token budget : ~500k–1M (ultracode allowed).
- Healing rule : 3 cycles max same finding → escalate.

---

## §9 — Convergence criteria + verdict matrix

### 9.1 Must-be-GREEN list

| Item | Threshold | Source |
|---|---|---|
| PHP suite | ≥ 2755 passed, 0 failed | `php artisan test` |
| Vitest | ≥ 1879 passed, 0 failed | `npx vitest run` |
| Frozen-zone diff | empty | `git diff --stat` |
| NF525 chain | CHAIN OK all branches | `fiscal:verify-chain --all` |
| Z-membership | no orphans | `fiscal:verify-z-membership` |
| Z identity post-rush | `total_tva == Σ total_by_tax_rate` EXACT | aggregate() output |
| Sync p95 | ≤ 1s | measured |
| Rush invariants | 0 dup, 0 leak, 0 gap | post-R3 |
| Console errors | 0 | per Playwright session |
| Visual capture | 0 raw label, 0 broken layout | screenshot reads |
| Adversarial findings | 0 confirmed P0+P1 | cross-validated |
| Round 2 convergence | 0 new finding | re-run |

### 9.2 Verdict matrix (per system)

```
GO              all D1-D7 green, all invariants green, 0 P0+P1 cross-validated
GO-CONDITIONAL  D1-D6 green, D7 has 1 P2 (visual cosmetic), conditions documented
HEAL            P1 found, can heal inline ≤ 3 cycles
BLOCK           P0 found, requires owner gate or frozen-zone touch
ESCALATE        Healing cycle exhausted OR architecture decision needed
```

### 9.3 Owner gate triggers (escalate immediately)

- Frozen-zone change needed → LOCK + AskUserQuestion.
- NF525 chain integrity violated → STOP, do not heal autonomously.
- Production data deletion proposed → STOP.
- Push to `origin/*` → STOP, only on explicit owner GO.
- Activation of any new env flag → STOP, AskUserQuestion.

---

## §10 — Reporting + memory

### 10.1 Convergence book template

`reports/test-e2e/$GOAL/CONVERGENCE_FINAL.md` (≥ 200 lines) with sections :

- §1 Mission + scope recap
- §2 Verdict (GO/NO-GO/CONDITIONAL)
- §3 Per-system verdict (15 systems × ★/✗ × evidence)
- §4 Per-wave summary (A-G, each with findings + heals + gates)
- §5 Adversarial findings (confirmed vs refuted)
- §6 Empirical numbers (suite counts, latencies, chain hashes, rush metrics)
- §7 Owner decisions if any
- §8 Rollback plan (per-wave reverts)
- §9 Reactivation/kill-switch state at convergence
- §10 Next-step backlog (P2/P3 deferred, V1.x items)

### 10.2 Per-wave intermediate reports

`reports/test-e2e/$GOAL/wave-X/` with:
- agent JSON outputs (raw)
- screenshots
- before/after measures
- gate log

### 10.3 BRAIN update

`PROJECT_BRAIN.md` §2 : new top entry summarizing verdict + commit chain.

### 10.4 Memory rules

- New `feedback_*.md` if a new pattern discovered.
- New `project_*.md` if this cycle warrants a project memory.
- Graphiti episode group=`foodking` for cross-session retrieval.
- MEMORY.md index update (≤ 200 chars per line).

---

## §11 — Rollback + safety per wave

### 11.1 Wave-level rollback

| Wave | Rollback action | Trigger |
|---|---|---|
| A | none (read-only) | N/A |
| B | revert test additions | New test reveals broken intent |
| C | revert any heal commits | Healed wrong path |
| D | restart dev server + clear queue | Rush leaves stale state |
| E | restore DB backup | Audit chain corrupted |
| F | revert security/a11y fixes | Heal regressed elsewhere |
| G | revert all of cycle | Final adversarial overrides verdict |

### 11.2 Kill-switch sanity per wave

Between every wave gate, re-verify the kill-switch path :
- `Config::set('pos.manual_discount_enabled', false)` test (the C-L6 lens) re-runs.
- If breaks → escalate (the protection rollback channel is gone).

### 11.3 Disk + browser hygiene

After every wave:
- `du -sh .playwright-mcp` → if > 100 MB, prune oldest YAMLs.
- Browser session lock check (the `mcp-chrome-9b44929` profile conflict).

---

## §12 — Budget + scheduling

### 12.1 Wall-clock estimates

| Wave | Wall-clock | Token est. |
|---|---|---|
| A pre-flight | 30 min | 20k |
| B happy-path | 1h | 100k (5 agents) |
| C abuse (6 lenses + verify) | 2h | 250k |
| D rush (R1+R2+R3+WS-deg) | 2h | 150k |
| E history/data | 1h | 100k (5 agents) |
| F cross-cutting | 1h | 80k (4 agents) |
| G convergence | 1h | 50k |
| **TOTAL** | **~8h** | **~750k** |

Plus 30% buffer for healing cycles : ~10h, ~1M tokens.

### 12.2 Concurrent-session coordination

- Pre-cycle : confirm with owner if other sessions running.
- Browser profile : use `--isolated` if needed.
- DB : single test DB ; no concurrent test process.
- Dev server : single instance on :8000.

### 12.3 Disk pre-allocation

- Reserve 5 Gi free before starting.
- Clean `.playwright-mcp` at start.
- Clean transient `/tmp/fk_*.log` between waves.

---

## §13 — Risk register

| Risk ID | Description | Mitigation |
|---|---|---|
| R-01 | `.playwright-mcp` YAML accretion fills disk | Prune at start + after each wave |
| R-02 | Concurrent session holds browser lock | Pre-cycle check + `--isolated` fallback |
| R-03 | Adversarial agent payload too large for StructuredOutput | Use lean schemas + JS-synth (no LLM judge) per round-4 lesson |
| R-04 | Mix build cache staleness after Vue edits | Rebuild bundle after every source change in same commit |
| R-05 | Test DB locked between concurrent runs | Single test process discipline |
| R-06 | Grep false-negative on whitespace (advisor 2026-05-31) | Always `grep -E "->discount\s*="` not literal-space |
| R-07 | Sentinel-baseline drift after frozen edit (must same-commit) | LOCK + update baseline in same commit |
| R-08 | Z aggregation divergence on multi-rate discount (advisor caught) | `total_tva = array_sum(byTaxRate)` SSOT pattern |
| R-09 | Browser ENOSPC mid-capture | Disk pre-allocation + intermediate prune |
| R-10 | Healing cycle infinite loop | Max 3 cycles same finding, then escalate |
| R-11 | Frozen-zone touch silently slips in | Frozen-diff check between every wave gate |
| R-12 | Rush leaves DB in stale state | Wave-D rollback = `RefreshDatabase` between R1/R2/R3 |
| R-13 | NF525 chain break during rush (worst case) | STOP immediately + escalate, never heal autonomously |
| R-14 | Activation regresses on tests written pre-flip | Kill-switch sentinel coverage (Wave C-L6) |
| R-15 | Customer-facing 422 dead-end on UI surfaces if Q2 regresses | Visual capture each wave at kill-switch ON+OFF states |

---

## §14 — Open questions for owner (pre-launch)

> Before executing this plan, surface these via AskUserQuestion.

**Q-LAUNCH-1 : Browser arbitration**
- Option A : Stop concurrent sessions before launch.
- Option B : Run with `--isolated` (separate browser profile).
- Option C : Use Playwright in parallel via test-runner only (no MCP).

**Q-LAUNCH-2 : Push policy at convergence**
- Option A : No push (default, safest).
- Option B : Push to `origin/heal/cms-pr1-quickwins-2026-05-18` only on owner explicit.
- Option C : Open PR to main with full convergence report.

**Q-LAUNCH-3 : Token + wall-clock budget**
- Option A : Strict 8h / 750k.
- Option B : Extended 12h / 1.5M (allow 3 healing rounds without time pressure).
- Option C : No cap (Auto / Ultracode), but daily checkpoint commits required.

**Q-LAUNCH-4 : Rush intensity ceiling**
- Option A : R1+R2 only (50 orders/min max).
- Option B : Full R1+R2+R3 (up to 200 orders/min).
- Option C : Extended R3 (300+ orders/min, stress beyond plan).

**Q-LAUNCH-5 : Scope of frozen-zone touches during cycle**
- Option A : Zero frozen touches (default). Any frozen finding → BLOCK + escalate.
- Option B : Zero unless P0 fiscal. Owner pre-authorizes one LOCK if found.
- Option C : Pre-authorize a small LOCK budget for ZReportService / FiscalSequence patches.

**Q-LAUNCH-6 : Visual capture depth**
- Option A : 18 surfaces × 1 viewport (desktop 1920×1080) — fast.
- Option B : 18 × 3 viewports (desktop / tablet / mobile) — default in this plan.
- Option C : 18 × 3 × 4 states (pristine / mid / error / kill-switch) — exhaustive, ~270 PNGs.

**Q-LAUNCH-7 : Reactivation kill-switch live drill**
- Option A : Skip drill — sentinel-tested is sufficient.
- Option B : Real drill : flip `.env` mid-cycle, watch surfaces hide, flip back, prove no data loss.
- Option C : Drill + measure end-to-end recovery time when flipping back.

**Q-LAUNCH-8 : Mobile/Web standalone inclusion**
- Option A : Out of scope (per CLAUDE.md §3bis — standalone, no API wire).
- Option B : Visual parity only (mobile shows canonical menu, palette intact) — no backend touch.
- Option C : Full parity + price sync verification (F-PRICE-01 backlog).

---

## §15 — Success exit criteria (summary)

The goal is **DONE** when ALL of the following are true:

1. **PHP suite** ≥ `2755 passed / 0 failed` post-cycle (target: improved by new sentinels added).
2. **Vitest** ≥ `1879 passed / 0 failed`.
3. **NF525 chain** : `CHAIN OK` on every active branch ; `fiscal:verify-z-membership` no orphans.
4. **Identity** (post-rush) : `total_tva == Σ total_by_tax_rate` EXACT on every closed Z.
5. **Frozen-zone diff** = empty across whole cycle OR documented LOCK with owner countersign.
6. **Sync p95** ≤ 1s on the 7 critical edges (cf. D1 budget).
7. **Rush invariants** : 0 dup, 0 leak, 0 gap across R1+R2+R3.
8. **Adversarial** : 0 confirmed P0+P1 across 6 lenses × all systems × ×3 skeptic verify.
9. **Round 2** : re-run B+C+E → 0 new P0/P1.
10. **Final supervisor adversaire** : verdict GO.
11. **Visual** : every captured surface read + clean (no raw labels, no broken layouts).
12. **Convergence book** written ≥ 200 lines + BRAIN updated + Graphiti episode pushed.
13. **All 14 open questions** answered + plan adapted accordingly.
14. **Owner sign-off** explicit (final AskUserQuestion).

---

## §16 — Anti-drift commitments (orchestrator self-discipline)

- I will **NOT** invent products / prices / endpoints / file paths.
- I will **NOT** trust single-agent claims without file:line cross-validation.
- I will **NOT** declare convergence on a narrow filter ; always full suite.
- I will **NOT** auto-flip production flags ; always AskUserQuestion.
- I will **NOT** push to remote ; always owner-explicit.
- I will **NOT** skip frozen-zone diff between waves.
- I will **NOT** silence a finding ; surface or refute with evidence.
- I will **NOT** loop healing > 3 cycles on same finding ; escalate.
- I will **NOT** trust grep with literal single space on fiscal/security enum (the advisor lesson).
- I will **NOT** delete `.claude/worktrees/*` (other sessions) ; only my own project's accretion.
- I will **ALWAYS** capture + read screenshots, not just capture.
- I will **ALWAYS** verify NF525 chain between waves.
- I will **ALWAYS** check kill-switch sanity between waves.
- I will **ALWAYS** stop + escalate on prison-time invariant breach (chain).

---

## §17 — Pre-launch checklist (owner-facing)

Before saying "GO" on this plan, the owner should confirm:

- [ ] No concurrent Claude session holds `mcp-chrome-9b44929` profile (or `--isolated` accepted).
- [ ] At least 10 Gi free disk space.
- [ ] Dev server :8000 running and healthy.
- [ ] DB in known good state (post-`a928ee88d`, post-reactivation).
- [ ] All §14 open questions answered.
- [ ] Owner accepts ~10h wall-clock + ~1M token budget.
- [ ] Owner confirms scope (15 systems × 7 dimensions).
- [ ] Owner accepts the verdict path (GO / CONDITIONAL / NO-GO).
- [ ] No push to remote unless owner explicit after convergence.

---

## §18 — Final note (orchestrator)

Ce plan est **conçu pour converger en un cycle**, mais respecte la discipline **healing 3 cycles max**. Si un système ne converge pas après round 2, j'escalate plutôt que de boucler indéfiniment.

Le plan **réutilise tous les patterns prouvés** :
- Multi-agent parallèle (Workflow tool).
- Schema-enforced JSON (anti-hallucination).
- Adversarial cross-validation ≥ 2 (per round-4 lesson).
- JS-synth final judge (per round-4 schema-failure lesson).
- File:line citation discipline (per CLAUDE.md §3ter).
- LOCK + frozen-baseline-same-commit (per round-1 LOCK convention).
- EXACT identity by construction (per round-2 advisor lesson).
- E2E close+sign+verifyChain (per round-2 advisor lesson).

Le plan **prévient les pièges connus** :
- `.playwright-mcp` accretion (R-01).
- Browser profile lock (R-02).
- StructuredOutput payload overflow (R-03).
- Grep whitespace false-negative (R-06).
- Cycle de healing infini (R-10).

Le plan **respecte les invariants V1 LOCAL Le Cayenne** :
- Pas de cloud / pas de push / pas de SaaS.
- Pas de scope creep.
- Pas de feature nouvelle.
- Pas d'auto-flip fiscal.
- Frozen zones intactes sauf LOCK.

Quand l'owner dit "GO" (après avoir répondu aux 14 questions §14), je lance Wave A. Pas avant.

**FIN DU PLAN.**

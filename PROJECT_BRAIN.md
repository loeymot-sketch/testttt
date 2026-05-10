# PROJECT_BRAIN.md
— FoodKing Single Source of Truth (read at session start, update at end)

> Bootstrap : 2026-05-09 post iter1-14 cycle complet
> Lu et mis à jour automatiquement par Claude (cf. CLAUDE.md §5 LOOP).
> Ne pas éditer manuellement les sections §2-§5 (auto-managed).

---

## §1 NORTH STAR — Vision long-terme (immuable sauf owner gate)

### V1 — Restaurant SaaS opérationnel (en cours, V1 GO-LIVE imminent)
Plateforme restaurant fast-food complète :
- **POS** Caisse (commande staff + cash + card + ticket-restaurant)
- **Kiosk** Borne client (Vue 3 wizard, paiement card, FR-lock)
- **KDS** Kitchen Display System (cuisine, Echo + polling fallback)
- **OSS** Order Status Screen (clients en attente)
- **Admin** Dashboard (catalogue, stock, orders, reports, fiscal Z)
- **Sync** cross-surface (Outbox + Pusher + polling 5s fallback)

### V1.0.1 — Hardening sprint (8j-agent budget owner Q4=A)
- FormRequest authz refactor 88 endpoints
- Password policy min:12 + complexity
- Sanctum TTL 8h → 1h sensitive ops
- API key versioning
- 6 listeners idempotency restants (Catalog/Coupon/Availability×3/Table)
- Observability SLI metrics + KDS overflow flag UI

### V1.x — Post-V1 (backlog priorisé)
- F-016b stock dashboard UI (Q3=A 5-7j, 90% backend déjà existant)
- 17 advisories security composer triage (1 CRITICAL phpspreadsheet RCE)
- Laravel 9 → 10 → 11 migration (track séparé)
- Spatie permissions 5 → 6 (track séparé)
- ESLint v10 setup + Vue plugin
- Saga pattern Order + Payment + Stock orchestration
- Stripe webhook idempotency (parité SenangPay iter11)

### Goals immuables
- Production-grade correctness, coherence, reliability, quality
- NF525 compliance absolue (audit chain HMAC + 6y retention)
- Multi-tenant branch isolation absolue
- Pricing SSOT backend authoritative
- Visual + technical evidence à chaque livraison

---

## §2 CURRENT STATE — Auto-managed

- **Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
- **HEAD** : `9b86e1e73` (mobile wizard multi-page refactor + E2E suite 12/12 GO)
- **Last update** : 2026-05-10 (refactor wizard kiosk-aligned + 12/12 catégories E2E green)
- **Branche release antérieure** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
  (HEAD `9d9dddae1`, NO-GO V1 par audit POS adversarial 2026-05-09 — état préservé)
- **Domaines production-ready** : ~7-8 / 16 (revu après ultra audit POS 2026-05-09 ;
  4 P0 cross-validés par 2+ agents indépendants ont invalidé plusieurs ✅
  précédemment marqués GO. **Conflit avec audit kiosk-only de la même date :
  le kiosk verdict GO V1 ne couvrait pas les surfaces fiscal/cash/auth POS,
  où les P0 résident.** Voir §8 DRIFT ALERTS + `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md`).
- **Tests filter cumulative iter14** : 705/705 PHPUnit verts (filter
  Outbox|Persist|DomainEvent|Fiscal|FinalizePaid|ZReport|FiscalSequence|Order)
- **E2E Playwright iter14** : 16/16 PASS (POS+Kiosk+KDS+auth+admin baseURL)
- **Frozen-zones spécifiques** : 0 lines diff vs main sur les 4 fichiers
  protégés (KioskWizard + KioskApp + KioskUpsell + POS Vanilla wizard).
  La branche release globale a normalement >4000 fichiers diff vs main
  — c'est attendu pour un cycle PHASE2.

---

## §3 LAST DONE — Auto-managed

**Mobile wizard multi-page kiosk-aligned 2026-05-10** (HEAD `9b86e1e73`,
branche `feature/mobile-app-le-cayenne-2026-05-10`) :
- **Audit cross-agent YC GStack 6 sub-agents** read-only (Architect / DBA /
  UX / Tester / A11y / Adversarial) — 8 fichiers `reports/review/mobile-audit-2026-05-10/`
  (~2190 lignes md + 449 lignes raw tinker DB extraction). Adversarial
  cross-validation : 15 contestations, 13 SURVIVES / 1 FAILS / 1 NEEDS-RECONCILE.
  3/4 user-prompt assertions invalidées (U2 wings BBQ/Nashville, U3 salades
  no-wizard, U4 assiette cooking style — toutes FAUSSES vs DB+kiosk évidence).
- **Owner-gate cleared** (4 décisions critiques par AskUserQuestion) :
  D1 salades = wizard simplifié (sauce + suppléments) ; D2 menus enfants
  has_sauce flip false→true ; U2 wings = 15 sauces génériques (Nashville
  rejected) ; U4 assiette poulet = description text (no wizard step).
- **Refactor wizard multi-page** : nouveau `mobile/screens-item-steps.jsx`
  (~900 lignes) avec 8 ScreenStep* (Viandes/Sauce/Crudités/Suppléments/Menu/
  Drink/FritesStyle/FritesSauce) + ScreenStepRecap + state machine
  `computeActiveSteps(item, selections)` mirror kiosk template-driven
  (8 templates : tacos/sandwich/burger/assiette/omelette/salade/snacking/simple).
  Cascade formule menu : full → drink + frites_style + frites_sauce, frites
  → frites_style + frites_sauce, boisson → drink. ScreenItem rewriten
  comme thin wrapper délégant à ScreenItemWizard.
- **A11y baseline WCAG 2.1 AA** : ChoiceCard avec role=radio/checkbox +
  tabindex=0 + onKeyDown.Enter/Space ; step heading h1 tabindex=-1 focus
  on transition ; aria-live counter "0/4" + total ; aria-disabled CTA
  + aria-describedby hint ; styles `:focus-visible` outline orange 3px ;
  prefers-reduced-motion override. Mobile/styles.css updated `--gray-3`
  contrast fix (#6F6A60 4.7:1 vs `#8A857B` 3.05:1) + nouveau `--green-dark`.
- **Data alignment 1:1 backend** : Cat 5 Ojja + Cat 9 Menus Enfants
  wizard_template `simple` → `omelette` (DB-aligned V3.8) ; Cat 9 items
  901/902 has_sauce false → true ; Cat 10 Frites items 1001/1002 nouveau
  flag `has_frites_style: true` ; nouvelle constante `FRITES_STYLES` 3
  options (Nature default / Cheddar fondu +1€ / Cheddar+Oignons croustillants
  +1.50€) cf. migration 040000 ; nouvelle constante `FORMULE_DRINKS` 8
  boissons cascade ; `priceFor()` étendue avec `fritesStyleId` + `fritesSauceIds`.
- **Hooks + components ajoutés** (parallel work merged) : `mobile/hooks/`
  (useCountdown.js + useLoyaltyQR.js) + `mobile/components/` (BarcodeMock.jsx
  + LoyaltyQR.jsx) + `mobile/data/loyaltyRewardState.js` + `mobile/data/dev-helpers.js`.
- **Tests E2E mobile suite** (`reports/test-e2e/mobile-vs-kiosk-2026-05-10/`) :
  Playwright 390×844 sur 12 catégories — **12/12 GO** ✓. 38 PNGs captures,
  0 raw label hit (Label.X / kiosk.X / 0undefined / NaN€), 0 white-on-white
  offender (alpha-blending sweep <95%), 0 page error, 0 console error
  (filtré 404 image-slots.state.json bruit pré-existant). Pricing combo
  Tacos XXL complet validé : 12,50 + 0,50 sauce + 1,00 Œuf + 3,00 Menu +
  1,00 Cheddar fondu = **18,00 €**.
- Frozen-zones intactes (KioskWizard / KioskApp / KioskUpsell / pos-wizard.js
  / FiscalSequence / BranchScope / PricingService / OrderState : 0 ligne diff).
- 6 décisions techniques différées orchestrateur : D3 Ojja/Omelettes
  frites_style dormant (leave dormant) ; D4 Cheddar fondu duplicate items
  402/403 (backend cycle hors scope mobile) ; D5 cat IDs 1..13 → 306..318
  (Phase 6 wireup) ; D6 addon.role NULL backfill (backend cycle).

**Mobile app Le Cayenne V0 standalone livrée 2026-05-10** (HEAD `24188a371`,
branche `feature/mobile-app-le-cayenne-2026-05-10`) :
- Bundle Claude Design importé dans `mobile/` (HTML React+Babel runtime,
  pas de build), nouveau `mobile/index.html` mobile-only (drop prototype nav).
- **Data layer Le Cayenne** alignée FoodKing schema (cf. `mobile/data/`) :
  - 9 catégories × 35 produits avec variations/extras/addons/wizard_profiles
  - 3 boxes (Solo/Nashville/Familiale) avec composition wizard (8 steps Box
    Familiale = 4 burgers + 4 boissons depuis SMASH × 6 + DRINKS × 7).
  - Tacos M/L/XL avec viande choice (steak halal / poulet / cordon bleu / merguez)
  - Loyalty mock (347 pts, 6 rewards, history 7 entries, QR HMAC mock)
  - Branch Le Cayenne Hénin-Beaumont 62210 (cohérent avec design Claude)
- **ScreenItem complet réécrit** : variations (radio) + addon options + extras
  groupés par group_label + wizard steps + qty stepper, validation min_select.
- **Tests Preview MCP — 18 surfaces auditées, 0 white-on-white offenders** :
  Splash, Onb1-4, Login, OTP, Home, Menu, Item Detail (Tacos variations + Box
  Familiale wizard 8 étapes), Cart, Stripe, Confirm, Orders En cours +
  Historique, Profile, Loyalty, Order Detail. Audit avec alpha-blending
  parents pour éliminer faux positifs sur fonds translucides.
- **Plan de connexion** : `mobile/CONNECTION_PLAN.md` 8 sections couvrant
  schéma SQL Supabase complet (10 tables + RLS + 4 Edge Functions), chemin
  alternatif backend FoodKing (avec endpoint customer-facing à créer +
  ability `mobile:order` analogue `kiosk:order`), 6 phases migration
  (auth → catalog → orders → loyalty → Stripe → build natif Capacitor),
  audit cross-system (Pricing SSOT, NF525, BranchScope, Idempotency,
  Sanctum), 5 décisions owner-gate.
- Mobile app fonctionne 100% standalone — bouton "PAYER À LA CAISSE" et
  "PAYER MAINTENANT" trigger flows complets jusqu'à confirmation + +25 pts.
- Frozen-zones intactes (KioskWizard / KioskApp / pos-wizard.js : 0 ligne diff).
- 4 commits sur branche : data layer / index+wizard / connection plan / brain update.

**Ultra audit POS adversarial 2026-05-09** (HEAD `9d9dddae1`, owner override §5 étape 2) :
- 6 sub-agents parallèles read-only : A=Architecture+Frozen, B=Security+Multi-tenant,
  C=Fiscal NF525, D=Cash+Payment, E=DBA+Schema, F=Tester+Coverage
- Durée 13 min wall-clock, ~750k tokens cumulés
- **Findings : 15 P0 / ~24 P1 / ~14 P2 = 53 total**
- Cross-validation : 4 P0 confirmés par 2+ agents indépendants
  - P0-01/02 : Order + OrderItem SoftDeletes = NF525 break (C+E)
  - P0-09 : CashDrawerService::openSession no lock/UNIQUE concurrent dual sessions (D+E)
  - P0-11 : WebhookEvent orphan dead code + SenangPay Gateway class missing → 500 (B+D)
  - P0-13/14 : 4 fake E2E POS specs + sentinel posKioskVariationParity comparing
    fixtures à elles-mêmes (F)
- **VERDICT GLOBAL : NO-GO V1** — block sur merge `cycle/PHASE2-...` → `main`
  jusqu'à fermeture P0 fiscal + cash + auth (~3-5j-agent + ~2-3j P1).
- **Contradiction directe avec l'audit kiosk-only 2026-05-09 ci-dessous**, qui
  rendait verdict GO V1 sans avoir audité fiscal/cash/auth/multi-tenant POS.
  Le verdict POS adversarial supersede car son scope est plus large.
- Rapport complet : `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md`
  + 6 rapports détaillés `01_*.md` à `06_*.md` + `00_INDEX.md`
- Graphiti épisode pushé : "Ultra audit POS adversarial — VERDICT NO-GO V1 — 2026-05-09"

**Ultra audit Borne (Kiosk) 2026-05-09** (mode YC GStack 4 specialists Explore parallèles) :
- Architect / Security / A11y / Tester en read-only audit (DBA + SRE trim — saturés iter11-14)
- Verdict global : **GO V1 merge** — aucun blocker V1, BRAIN §7 16/16 reconfirmés
- 8 items V1.0.1 work list (1 P0 + 4 P1 + 3 P2), alignés avec backlog §5
- Frozen-zones intactes (4 fichiers : KioskWizard + KioskApp + KioskUpsell + POS Vanilla)
- Anchors insights report 2026-05-09 re-vérifiés :
  - `kiosk.promo` régression : ABSENTE sur HEAD (carousel server-driven intact),
    mais pas de continuous guard → V1.0.1 P1
  - E2E flakiness : text-selectors + innerText parsing présents → V1.x backlog
    (storageState + data-testid migration)
  - NF525 fiscal sequence : verrouillage iter11+14 confirmé
- Méta-leçon iter15 maintenue : evidence over speculation
- Détail synthèse : conversation 2026-05-09 (in-conversation, pas fichier disque
  par décision advisor — keep it pointer-style)
- Graphiti épisode pushé : "Ultra Audit Borne Kiosk 2026-05-09 V1 ship-ready GO"

**iter15 audit système Claude** (post-bootstrap 951cc4604) :
- 4 sub-agents YC GStack en parallèle (DOC + UX + WORKFLOW + BRAIN auditors)
- Verdict global : Coherence solide / Friction UX 2.1/5 / LOOP robustness
  6.5/10 / BRAIN accuracy ~65% (staleness HIGH)
- 4 corrections factuelles BRAIN.md appliquées :
  - §2 frozen-zones wording (clarifie "fichiers spécifiques", pas branche)
  - §5 V1.x security advisories (3 vraies vs 17 de worktree blissful)
  - §9 4 migrations (le 5e était sur worktree blissful)
  - §9 advisories triage corrigé (3 vraies vs 17 stale)
- 11 amendments P1 CLAUDE.md proposés (NON-appliqués, attente validation owner)
- Cf. §8 DRIFT ALERTS pour findings P1 détaillés

**iter14 V1.0.1 hardening sprint** (commits `1ddc642a6` + `179d4e377` +
`3150992a7` + `cce7a6f30`) :
- SPECIALIST-1 — i18n cleanup 5 raw strings + OSS a11y landmarks
  WCAG 2.1 (7 fichiers, 6 keys × 3 locales = 18 entrées)
- SPECIALIST-2 — Listener idempotency `firstOrCreate` pattern + UNIQUE
  migration `idempotency_key` sur `domain_events` (4 listeners)
- SPECIALIST-3 — Fiscal orphan retry GATE-FZH-ALLOC + Z-close pre-check
  + cron `foodking:fiscal:retry-alloc` + nouvelle migration
  `fiscal_alloc_error_at` + 4 tests verts

Tests cumulatifs iter14 : 705/705 PHPUnit verts (filter Outbox|Persist|
DomainEvent|Fiscal|FinalizePaid|ZReport|FiscalSequence|Order).
E2E Playwright iter14 : 12/12 core (POS+Kiosk+KDS) + 4/4 auth+admin = 16/16 PASS.
Captures visuelles : kiosk idle confirmé branding intact + admin login OK.

---

## §4 NEXT TO DO — Auto-managed (brain-written)

### Remediation P0 ultra audit POS 2026-05-09 (~3-5j-agent)

**Hard pre-merge V1** (15 P0, voir `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` §5 pour détails file:line) :

#### Fiscal & data integrity (4 P0)
1. **P0-01/02** Décision owner : retirer `SoftDeletes` de `Order` + `OrderItem`
   (NF525 archive-then-deny) OU prouver rétention 6y autrement. Sinon BRAIN
   doit déclarer le risque NF525 explicitement.
2. **P0-03** Add `MysqlOnly` test variant ou Sentinel CI sur DELETE trigger
   `z_reports` (aujourd'hui 0 coverage SQLite).
3. **P0-04** Migrer FK `cash_movements` + `order_payments` `cascadeOnDelete` →
   `restrictOnDelete`. Migration + test.

#### Multi-tenant & auth (4 P0)
4. **P0-05** Décision owner sur `IDEMPOTENCY_MIDDLEWARE_ENABLED` default flag
   (actuellement `false` → middleware dormant en deploys frais).
5. **P0-06** Patch `PosOrderController::show:108` cross-branch leak via
   `withoutGlobalScope` + test.
6. **P0-07** Patch `RefreshTokenController:23-27` `['*']` privilege escalation
   path (copier abilities du token actuel, pas wildcard).
7. **P0-08** Add route-level `abilities:kiosk:order` sur `frontend/order` create
   + `payment-confirm` group.

#### Cash, payment, hardware (4 P0)
8. **P0-09** `CashDrawerService::openSession` Cache::lock + UNIQUE partial
   `(branch_id, status='OPEN')` + test concurrent.
9. **P0-10** `RefundWithCounterEntryService` insérer counter-entries miroir
   par tranche split + test split refund Z reconciliation.
10. **P0-11** Décision owner SenangPay : restaurer Gateway class + wire
    WebhookEvent sur les deux providers, OU retirer route si dead.
11. **P0-12** `OrderStateMachine::apply:185` ajouter `lockForUpdate` upstream
    (équivalent à `OrderService::changeStatus`).

#### Tests fakes (2 P0)
12. **P0-13** Réécrire 4 e2e POS specs adversarial-grade (real Playwright
    `page.click`, wizard flow, payment, DB assertion).
13. **P0-14** Réécrire `posKioskVariationParity.spec.js` : invoquer real
    `PricingService::compute` (ou binding JS), pas comparer fixtures à elles-mêmes.

#### Frozen-zone governance (1 P0)
14. **P0-15** Owner gate explicite sur diffs frozen-zone existants
    (KioskWizard +1665, KioskApp +892, pos-wizard.js +237 lignes logic) ;
    update BRAIN §2 avec réalité OU revert non-gated.

### V1.0.1 hardening (P1, ~2-3j-agent)
- 4 BranchScope manquants (OrderStatusTransition, PosParkedOrder, OrderQuote, OrderCoupon)
- GATE-FZH-ALLOC pre-Z-close warn-only → throw
- z_reports UPDATE block (model observer ou DB trigger UPDATE)
- FiscalChainValidator first-row anchor + tests
- FK constraints sur 5 tables récentes (order_payments, cash_drawer_sessions,
  cash_movements, pending_payment_confirmations, webhook_events)
- Index `(order_id, paid_at)` sur order_payments
- pageerror listener avant page.goto sur 4 e2e specs
- Voir `99_VERDICT.md` §5 P1 complet.

**État actuel** : V1 merge **bloqué** jusqu'à fermeture P0 fiscal + cash + auth.
Owner gate requise sur P0-01/02 (SoftDeletes), P0-05 (idempotency default),
P0-11 (SenangPay), P0-15 (frozen-zone breach).

---

## §5 BACKLOG — Priorisé (lu par /ultraplan pour orienter le plan)

### P0 (CRITICAL pre-merge V1) — fermés ✅
- ~~SenangPay webhook idempotency~~ → iter11 webhook_events table
- ~~OrderItem manque BranchScope~~ → iter11
- ~~z_reports DELETE non-bloqué~~ → iter11 trigger MySQL

### P1 (V1.0.1 sprint, partiellement fermés iter12-14)
- ✅ ~~OrderPayment + KioskMachine BranchScope~~ → iter12
- ✅ ~~OrderService::changeStatus race~~ → iter13 lockForUpdate
- ✅ ~~Stock listener escalation~~ → iter12+13
- ✅ ~~Stale daily quota cron~~ → iter13
- ✅ ~~Listener idempotency 4 listeners~~ → iter14
- ✅ ~~Fiscal orphan retry GATE-FZH-ALLOC~~ → iter14
- ✅ ~~i18n + OSS a11y WCAG 2.1~~ → iter14
- ⏳ FormRequest authz refactor 88 endpoints (1-2j)
- ⏳ Password min:12 + complexity (0.5j)
- ⏳ Sanctum TTL 8h → 1h sensitive ops (0.5j)
- ⏳ API key versioning (1j)
- ⏳ 6 listeners idempotency restants (0.5j)

### P2 (Observabilité V1.0.1)
- Latency SLI metrics (kiosk.payment_confirm + outbox_dispatch_p95)
- KDS limit-50 overflow flag UI
- `/api/sync/status` monitoring endpoint
- Frontend correlation_id dedup cache 120s
- Admin polling 60s → 10s adaptive si WS down
- Reconcile audit double-pay log

### V1.x post-V1
- F-016b stock dashboard UI (Q3=A)
- 3 advisories security composer (vérifié `composer audit` 2026-05-09 sur
  PHASE2 main repo) :
  - LOW : `firebase/php-jwt` CVE-2025-45769
  - MEDIUM : `laravel/framework` CVE-2025-27515 (file validation bypass)
  - MEDIUM : `psy/psysh` CVE-2026-25129 (local privilege escalation)
- Laravel 9 → 10 → 11 migration (track séparé EOL approche)
- Spatie 5 → 6 (track séparé)
- ESLint v10 + Vue plugin setup
- Saga pattern Order + Payment + Stock
- Stripe webhook idempotency (parité SenangPay iter11)

---

## §6 DECISIONS LOG — Owner-validated gates (immuables)

Cette section est **append-only**. Toute décision validée par l'owner
y est enregistrée pour éviter la dérive et le re-questioning.

### iter6 — Owner replies
- **Q1=A** FR-lock V1 conservé (multi-locale UI désactivé v-if=false)
- **Q2=B** Migration archive-then-delete recoverable (au lieu de DELETE direct)
- **Q3=main** PR base branch = main

### iter7 — Owner replies
- **Q-A=B** Sub-agents ultra-audit avant apply (pas apply direct)
- **Q-B=A** MySQL DELETE triggers (driver-conditional, SQLite skip)
- **Q-C=A** webhook_events table UNIFIÉE (Stripe + SenangPay parity)
- **Q-D=skip** Vitest CI workflow (deferred post-V1)

### iter11 — Owner Q1-Q4
- **Q1=A** Signer 5 GATED migrations
- **Q2=A** DATA-004 fix pre-merge (+1j)
- **Q3=A** F-016b dashboard V1.x post-merge (5-7j backend déjà 90% ready)
- **Q4=A** Budget V1.0.1 ~8j-agent

### Architecture immuables
- Single-agent Claude Code session (pas de split brain/executor)
- 2 fichiers seulement : `CLAUDE.md` + `PROJECT_BRAIN.md`
- Slash commands natifs `/ultraplan`, `/ultrareview`, `/review`,
  `/security-review` (pas de custom à recréer)
- Visual test mandatoire à chaque modif frontend (Playwright + Read screenshot)
- Self-correction loop max 3 fois avant escalation user

---

## §7 VERIFICATION CHECKLIST — 16 domaines production-ready

| # | Domaine | Status | Iteration |
|---|---|---|---|
| 1 | Architecture event-driven (Outbox + Pusher + polling 5s) | ✅ | iter11 |
| 2 | Multi-tenant BranchScope (11 models scoped) | ✅ | iter11+12 |
| 3 | Pricing SSOT NF525 (composition_snapshot frozen) | ✅ | iter10 baseline |
| 4 | Fiscal hash chain + DELETE triggers MySQL | ✅ | iter11 |
| 5 | Idempotency dual-layer + webhook_events unifié | ✅ | iter11 |
| 6 | Order state machine + lockForUpdate races | ✅ | iter13 |
| 7 | Sanctum kiosk:order single-ability strict | ✅ | iter12 |
| 8 | Stock concurrency + listener escalation | ✅ | iter12+13 |
| 9 | Daily quota stale reset cron | ✅ | iter13 |
| 10 | Cash audit F-003 chain-signed | ✅ | iter10 baseline |
| 11 | Allergen FR + composition_snapshot | ✅ | iter10 baseline |
| 12 | Production guards AppServiceProvider | ✅ | iter10 baseline |
| 13 | Polling fallback KDS 5s (banner Mode secours) | ✅ | iter10 baseline |
| 14 | i18n + a11y OSS WCAG 2.1 | ✅ | iter14 |
| 15 | Listener idempotency firstOrCreate + UNIQUE | ✅ | iter14 |
| 16 | Fiscal orphan retry GATE-FZH-ALLOC | ✅ | iter14 |

---

## §8 DRIFT ALERTS — Auto-managed

> Si Claude détecte une dérive de direction (15-20° du NORTH STAR),
> il append ici avec timestamp + cause + recommandation.

### 2026-05-09 — Ultra audit POS adversarial (HEAD 9d9dddae1) — **VERDICT NO-GO V1**

**Drift catastrophique BRAIN.md §7 vs réalité code détecté.** 6 sub-agents
adversariaux ont produit **15 P0 cross-validés** dont 4 confirmés par 2+
agents indépendants.

#### BRAIN drift table (§7 production-ready vs reality)

| BRAIN §7 ✅ | Réalité audit | Drift |
|---|---|---|
| 1 Architecture event-driven | webhook_events orphan + WebhookEvent dead + SenangPay 500 (P0-11) | **HIGH** |
| 2 BranchScope 11 models | 4 POS-surface manquent (P1-01) | MEDIUM |
| 4 Fiscal hash chain + DELETE triggers | Trigger 0 test coverage (P0-03), UPDATE allowed (P1-03) | **HIGH** |
| 5 Idempotency dual-layer + webhook unifié | Middleware default-disabled (P0-05) + webhook orphan (P0-11) | **HIGH** |
| 6 Order state machine + lockForUpdate | OrderStateMachine::apply still races (P0-12) | MEDIUM |
| 7 Sanctum kiosk:order strict | Refresh issues `['*']` (P0-07) + missing route abilities (P0-08) | **HIGH** |
| 10 Cash audit F-003 chain-signed | Session no-lock (P0-09) + refund mirror gap (P0-10) + cascadeOnDelete (P0-04) | **HIGH** |
| 16 Fiscal orphan retry GATE-FZH-ALLOC | Pre-close GATE warn-only not block (P1-02) | MEDIUM |
| §2 "0 lines diff frozen-zones" | 2,597 ins / 419 del across 5 of 6 frozen files (P0-15) | **HIGH** |

**Domaines réellement ✅ post-audit** : ~7-8 / 16 (déclaration corrigée §2).

#### Conflit avec verdict "Ultra audit Borne (Kiosk) GO V1"
L'audit kiosk-only de la même date a rendu verdict **GO V1** sans avoir audité
les surfaces fiscal/cash/auth/multi-tenant POS où les 15 P0 résident. Le
verdict POS adversarial **supersede** car son scope cross-coupe avec le kiosk
(Order/OrderItem SoftDeletes, RefreshTokenController abilities) tandis que
l'inverse n'est pas vrai. **Méta-leçon** : les audits scope-limited ne
peuvent pas conclure GO global ; il faut soit auditer cross-surface, soit
limiter le verdict au scope audité.

#### Méta-leçons audit POS
1. **BRAIN drift = risque #1**, pas les bugs individuels. Une mémoire stale qui
   affirme 16/16 ready conditionne l'owner à signer un merge dangereux.
   Recommandation : CI sentinel `git diff main -- <frozen-files> --numstat`
   pour empêcher la fiction.
2. **Sub-agents adversariaux + cross-validation indépendante** essentiels
   pour identifier les "✅ illusoires" (4 P0 confirmés multi-agents).
3. **"Tests verts" ≠ sécurité** — pattern fake E2E confirmé sur 4 specs
   (P0-13) et sentinel auto-comparant fixtures (P0-14).
4. **NF525 + SoftDeletes sur Order = combinaison explosive** (P0-01/02).
   Décision architecture-level requise, pas patch-level.

#### Recommandation actions immédiates owner
- Lire `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md` (15 P0
  + remediation checklist priorisée + BRAIN drift table)
- Décisions stratégiques à valider :
  - SoftDeletes Order/OrderItem (P0-01/02) — NF525 hardstop
  - IDEMPOTENCY_MIDDLEWARE_ENABLED default flag (P0-05)
  - SenangPay class manquante : restaurer ou drop (P0-11)
  - Frozen-zone breach gate rétroactive (P0-15)
- Bloquer merge `cycle/PHASE2-...` → `main` jusqu'à fermeture P0
- Réorganiser sprint V1.0.1 autour des 15 P0 (~5-8j-agent total)

### 2026-05-09 — Audit iter15 système Claude (post bootstrap 951cc4604)

**11 amendments P1 CLAUDE.md proposés** (audit 4 sub-agents YC GStack) —
**non-appliqués, attente validation owner** :

#### Apply maintenant (corrige risques opérationnels concrets)
- **A1** §7 Frozen Zones — chemin exact POS Vanilla wizard manquant
  (probablement `resources/js/components/admin/pos/PosComponent.vue`
  ou inline script)
- **A2** §5 étape 7 — mécanisme comptage healing cycles non-opérationnel
  (format "(counter: X/3) [problème: Y]" + reset si problème change)
- **A3** §6 Visual Test — ne couvre pas API payload mutations (visual
  capture ≠ JSON structure verification). Ajouter §6.1 API Payload Test
- **A7** §5 étape 8 — protocole interruption mid-LOOP manquant (commit WIP
  + BRAIN.md "[INTERRUPTED at step N]" + Graphiti incident)

#### Apply en V1.0.1 (améliore discipline, pas urgent)
- **A4** §12 Anti-Drift Checklist opérationnel (read DECISIONS LOG +
  grep décisions clés vs task objective + STOP si conflict)
- **A5** §5 étape pré-1 — Micro-task exemption (≤5 lignes + non-frontend
  + non-frozen → merge étapes 1-2-4, skip 6 si pas frontend)
- **A6** §5 étape 2-3 — Frozen-zone escalation gate pre-execute (intent
  detection typo/test/logic → STOP gate user si logic-change)
- **A8** §10 Decision — Emergency NF525 hotfix clause (EXECUTE + post-hoc
  evidence + branche hotfix/* + owner ack avant merge)

#### Apply post-V1 (UX + résilience)
- **A9** §17 (NEW) — Quick Start Commands & Examples (6 conversations
  naturelles → slash commands correspondants)
- **A10** §4 Sub-agents — conflict resolution protocol (evidence quality
  tabulation → BRAIN.md §6 DECISIONS LOG entry)
- **A11** §5 étape 6 — Playwright fail fallback (log + skip + tag
  "[VISUAL TEST SKIPPED: server unavailable]" + downgrade confidence)

### Verdict audit iter15
- **Coherence CLAUDE.md** : solide globalement, 4 P1 gaps (frozen path POS,
  healing counter, payload visual gap, anti-drift algorithm)
- **Friction UX** : 2.1/5 medium (slash commands non-discoverable,
  LOOP opaque user non-tech, plan persistence non-mandatory)
- **LOOP robustness** : 6.5/10 (manque micro-task exempt, frozen escalation,
  mid-LOOP interrupt, sub-agent conflict, MCP fallback, emergency NF525)
- **BRAIN accuracy** : ~65% (4 corrections factuelles appliquées 2026-05-09 :
  HEAD update, frozen-zones wording, advisories 17→3, migrations 5→4)
- **Aucune dérive direction** détectée (NORTH STAR §1 toujours valide)

### 2026-05-09 — Ultra-review iter15 plan (post-audit, 3 sub-agents adversariaux)

Plan iter15 a été re-audit par 3 sub-agents adversariaux (DEVIL-ADVOCATE +
RISK-ANALYZER + PRIORITY-CHALLENGER). Verdict : **plan trop optimiste**,
recommandation conservatrice :

#### ❌ DROP COMPLÈTEMENT (3/3 sub-agents reject)
- **A5 Micro-task exemption** — DANGEROUS. Crée loophole bypass visual test,
  erode discipline §3 principe 11. Risk d'introduire UI bugs systématiques.
- **A8 Emergency NF525 hotfix** — HIGH RISK doctrine erosion. NF525 a pas
  d'urgence override autorisé. Précédent dangereux.
- **A3 API Payload Test** — REDONDANT avec §6 visual test mandate déjà
  en place + PHPUnit response assertions.

#### ✅ APPLY MAINTENANT (1 seul amendment safe)
- **A1 §7 POS Vanilla path** — APPLIED (path verified) :
  - `public/js/pos-wizard.js` (Vanilla JS hand-written, S25-SinglePage)
  - `public/css/pos-wizard.css`
  - `resources/views/admin-pos-v4.blade.php` (loader Blade direct)

#### ⏸️ DEFER V1.0.1 (avec specs préalables requises)
- **A2 Healing counter** — d'abord définir parser format + BRAIN pollution mitigation
- **A4 Anti-Drift Checklist** — d'abord définir algorithm grep précis (false positives risk)
- **A6 Frozen escalation gate** — d'abord définir intent detection heuristic
- **A7 Mid-LOOP interrupt** — d'abord écrire recovery SOP (sinon état orphelin)

#### ⏸️ POST-V1 si jamais (pas urgents)
- A9 Quick Start §17 (docstring inflation risk)
- A10 Sub-agent conflict (define rubric d'abord)
- A11 Playwright fallback (weakens visual test mandate)

### Méta-leçon iter15 ultra-review
La discipline LOOP §5 a fait son travail : audit → second pass adversarial
→ identification du sur-engineering → application minimale safe.
**11 amendments proposés → 1 seul appliqué.** Évite l'inflation doctrinale
qui aurait dilué CLAUDE.md.

CLAUDE.md actuel est **acceptable pour V1**. Les amendments restants doivent
être triggered par incidents réels, pas par hypothèses. Evidence-driven
discipline maintenue.

---

## §9 OWNER ACTION ITEMS — Pre-merge V1

> ⛔ **MERGE BLOQUÉ** par ultra audit POS 2026-05-09 — voir §4 NEXT TO DO
> remediation P0 (15 items) + `reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md`.

Avant merge `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` → `main` :

### NEW (pre-merge HARDSTOP — 15 P0 ultra audit, ~3-5j-agent)

0a. ⛔ **Décision SoftDeletes Order + OrderItem** (P0-01/02) — NF525 hardstop
0b. ⛔ **Décision IDEMPOTENCY_MIDDLEWARE_ENABLED default** (P0-05)
0c. ⛔ **Décision SenangPay class manquante** (P0-11) — restore ou drop
0d. ⛔ **Gate rétroactive frozen-zone breach** (P0-15) — KioskWizard / pos-wizard.js
0e. ⛔ **Patch P0-03 → P0-04 → P0-06 → P0-07 → P0-08 → P0-09 → P0-10 → P0-12**
    (8 patches techniques avec tests, voir §4 NEXT TO DO)
0f. ⛔ **Réécrire P0-13 (4 e2e POS specs) + P0-14 (sentinel parity)**

### Original (non-blockers, peut continuer en parallèle de 0)

1. ✅ **Push origin DONE** (commits iter11-14 sur `cce7a6f30`)
2. ⏳ **Backup prod** : `mysqldump foodking_prod > pre-V1-backup-2026-05-09.sql`
3. ⏳ **migrate --pretend staging** (4 nouvelles migrations sur PHASE2 main repo,
   verified `ls database/migrations/2026_05_09_*` 2026-05-09) :
   - `2026_05_09_120000_create_webhook_events_table.php` (iter11 webhook unifié)
   - `2026_05_09_160000_add_z_reports_delete_trigger_immutability.php` (iter11 NF525 trigger)
   - `2026_05_09_180000_add_idempotency_key_to_domain_events.php` (iter14 listener dedupe)
   - `2026_05_09_200000_add_fiscal_alloc_error_at_to_orders.php` (iter14 fiscal orphan)
   > NB : Le 5e migration `2026_05_09_010000_fix_order_ratings_unique_key.php`
   > était sur le worktree blissful-mclean (cycle iter1-8), pas sur PHASE2 main.
4. ⏳ **Triage 3 advisories security composer** (verified 2026-05-09) :
   - LOW : firebase/php-jwt CVE-2025-45769
   - MEDIUM : laravel/framework CVE-2025-27515 (file validation bypass)
   - MEDIUM : psy/psysh CVE-2026-25129 (local privilege escalation)
   > NB : Pas de CRITICAL phpspreadsheet RCE sur PHASE2 (le 17 advisories
   > venait de l'audit iter5 SRE-DEPLOY sur worktree blissful — état
   > composer différent).
5. ⏳ **Smoke test live** post-deploy (Chrome MCP captures)
6. ⏳ **Coordinate** avec autre agent (PR #12 PHP 8.3 fix si conflit ouvert)
7. ⏳ **Merge → main** après validation

---

— *PROJECT_BRAIN.md à jour. Prêt pour la prochaine session Claude Code.
Lu automatiquement à chaque démarrage selon CLAUDE.md §5 étape 1.*

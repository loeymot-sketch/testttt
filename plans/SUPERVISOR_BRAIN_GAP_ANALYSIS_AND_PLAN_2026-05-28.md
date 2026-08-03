# SUPERVISOR BRAIN — Gap Analysis & Maximum Test Coverage Plan
## V1 LOCAL Le Cayenne — Production-Perfect Validation Strategy
2026-05-28 — HEAD `fcfef17fd` (post MAX TEST 16 agents + 8 heals)

---

## §0 — Mission du Brain

J'agis ici comme **cerveau orchestrateur** du projet. Mon rôle :
1. **Auditer** ce qui a été réellement testé empiriquement vs ce qui est code-attested
2. **Identifier les lacunes** par dimension fonctionnelle / technique / interface / sync / archi / sécu / observabilité
3. **Prioriser par risque V1 ship** (legal NF525 > opérationnel > UX > confort)
4. **Décomposer** chaque lacune en sous-tests précis exécutables
5. **Orchestrer** la stratégie d'exécution agents parallèles + manuel

---

## §1 — AUDIT — Ce qu'on a fait (state snapshot)

### Cycles complétés

| Date | Cycle | Couverture | État |
|---|---|---|---|
| Cumulative (50+ sessions) | V1 cumulative | 504+ sentinels, NF525 chain, frozen-zone discipline | LOCKED |
| 2026-05-28 GOAL E2E | 7 agents validation initiale | POS+Kiosk+KDS+OSS+Admin+Livreur+Cross | 8 heals committed `e7ae1c8ea` |
| 2026-05-28 MAX TEST | 16 agents Wave A+B | Foundation+Surfaces+Intersections+Adversarial+i18n+Arch | Committed `fcfef17fd` |

### Empirique vs Code-attested distinction (CRITIQUE)

| Surface | Capture visuelle réelle | Code-attesté seulement | Live workflow E2E |
|---|---|---|---|
| POS Caisse | ✅ login + caisse loaded | ⚠️ wizard data DB-layer only | ❌ payment cycles SKIP |
| Kiosk | ✅ idle + catalog + cart | ⚠️ wizard sauce INCONCLUSIVE | ❌ paid order LIVE non-validé |
| KDS | ✅ board + bump 1 case | ✅ recall 404 reverse | ⚠️ workflow chef rush jamais simulé |
| OSS | ✅ wall display + alias | ✅ wakelock code | ❌ live KDS→OSS flip latence non-mesurée |
| Admin | ✅ dashboard + 6 surfaces | ✅ permissions gate | ❌ Z PDF download jamais déclenché |
| Livreur | ✅ list + show post-heal | ✅ DELIVERED hook code | ❌ shift open→pickup→deliver→close cycle complet |
| Cross-sync | ✅ historical data baseline | ✅ code-trace 7 chains | ❌ latence live N=10 jamais mesurée |

**Verdict audit** : on a une **excellente couverture visuelle statique** + **excellente vérification code-layer** des invariants. Ce qu'on a PEU/PAS validé : les **workflows complets bout-en-bout en mode live** avec capture latence + intégrité numerique multi-acteurs.

---

## §2 — GAP MATRIX — 8 dimensions × niveaux profondeur

| Dimension | Surface | Code | Intégration | Adversarial | Personas | Stress | Latence | Recovery |
|---|---|---|---|---|---|---|---|---|
| **Fonctionnel POS** | ⚠️ | ✅ | ⚠️ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Fonctionnel Kiosk** | ⚠️ | ✅ | ⚠️ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Fonctionnel KDS** | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **Fonctionnel OSS** | ✅ | ✅ | ⚠️ | ✅ | ❌ | ❌ | ❌ | ✅ |
| **Fonctionnel Admin** | ✅ | ✅ | ⚠️ | ⚠️ | ❌ | ❌ | n/a | ❌ |
| **Fonctionnel Livreur** | ✅ | ✅ | ⚠️ | ⚠️ | ❌ | ❌ | n/a | ❌ |
| **Sync Outbox+Pusher** | n/a | ✅ | ⚠️ | ✅ | n/a | ❌ | ❌ | ❌ |
| **Sync polling fallback** | n/a | ✅ | ⚠️ | ✅ | n/a | ❌ | ⚠️ | ❌ |
| **NF525 Fiscal** | n/a | ✅ | ⚠️ | ✅ | ❌ | ❌ | n/a | ❌ |
| **Pricing SSOT** | n/a | ✅ | ⚠️ | ✅ | n/a | ❌ | n/a | n/a |
| **Multi-tenant BranchScope** | n/a | ✅ | ✅ | ✅ | n/a | ❌ | n/a | n/a |
| **Auth Sanctum+Spatie** | ✅ | ✅ | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ |
| **i18n FR** | ✅ | ✅ | ✅ | ✅ | n/a | n/a | n/a | n/a |
| **i18n EN+AR** | ❌ | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **a11y WCAG** | ⚠️ | ⚠️ | n/a | ⚠️ | ❌ | n/a | n/a | n/a |
| **Performance bundle** | ✅ | ✅ | n/a | n/a | n/a | ❌ | ✅ | n/a |
| **DB schema+indexes** | n/a | ✅ | ⚠️ | ✅ | n/a | ❌ | ❌ | ❌ |
| **External payment Stripe** | n/a | ✅ | ❌ | ⚠️ | n/a | n/a | ❌ | ❌ |
| **External payment SenangPay** | n/a | ⚠️ | ❌ | ❌ | n/a | n/a | ❌ | ❌ |
| **Email transactionnel** | n/a | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **SMS transactionnel** | n/a | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **Push notifications** | n/a | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **PDF generation** | ❌ | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **Cron jobs (outbox/webhook/Z)** | n/a | ✅ | ❌ | ⚠️ | n/a | ❌ | n/a | n/a |
| **Backup + Recovery** | n/a | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | ❌ |
| **Loyalty earn+redeem** | ❌ | ✅ | ❌ | ⚠️ | n/a | n/a | n/a | n/a |
| **Customer signup+OTP** | ❌ | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **Mobile RN standalone** | ❌ | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **Web standalone** | ❌ | ⚠️ | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **Cross-browser FF/Safari** | ❌ | n/a | ❌ | ❌ | n/a | n/a | n/a | n/a |
| **Observabilité metrics** | n/a | ⚠️ | ❌ | n/a | n/a | n/a | ❌ | n/a |

Légende : ✅ couvert empiriquement | ⚠️ partial/code-only | ❌ non couvert | n/a non applicable

**Lacunes structurelles identifiées** : 47 cellules ❌ + 23 cellules ⚠️ = ~70 dimensions à approfondir.

---

## §3 — TIER 1 — V1 SHIP CRITICAL GAPS (MUST close)

Ces gaps représentent un **risque V1 ship réel** : legal NF525, financier, ou opérationnel direct.

### GAP-T1-01 — POS Payment 4 scenarios LIVE end-to-end

**Pourquoi V1 ship-critical** : NF525 fiscal_sequence_no allocation + audit chain extension + composition_snapshot frozen sur 4 modes paiement DIFFÉRENTS jamais validés empiriquement.

**Sous-tests précis** :
- **GAP-T1-01a** Cash : open drawer → wizard Sandwich Cayenne → cash 7.50 → close → Z partial verify
- **GAP-T1-01b** Card simulation : same → CARD → TPE simulation approval → audit_log `payment.card.approved`
- **GAP-T1-01c** SPLIT cash+card : panier 20€ → 10 cash + 10 card → 2 rows order_payments → order.total=20
- **GAP-T1-01d** Ticket restaurant : same → TICKET_RESTAURANT → audit trail

**Critères PASS** :
- Chaque cycle écrit 1 row audit_logs (chain extends, hash monotonic)
- composition_snapshot non-NULL post-creation
- order.payment_status = PAID
- order_payments table grows (currently EMPTY = test artifact)
- fiscal_sequence_no allocated monotonically

**Exécution** : Manuel browser POS direct OR dispatch 1 agent Playwright headed avec auth + idempotency-key fix.

### GAP-T1-02 — Refund counter-entry NF525 LIVE

**Pourquoi critical** : refund est une obligation légale NF525. Si la chaîne mirror n'est pas testée empiriquement, on risque la non-conformité.

**Sous-tests** :
- **GAP-T1-02a** Refund partiel (qty -1 sur item) → mirror order créé
- **GAP-T1-02b** Mirror order has `parent_order_id` = original.id
- **GAP-T1-02c** Mirror has fresh `fiscal_sequence_no` (not duplicate)
- **GAP-T1-02d** AuditLog `refund.created` row added with HMAC chain
- **GAP-T1-02e** Z report enrichment includes refund (RefundWithCounterEntryService verified)

**Critères PASS** : tous vérifiés via tinker queries + capture browser.

### GAP-T1-03 — Z report close + PDF + chain extend LIVE

**Pourquoi critical** : NF525 obligation = clôture fiscale quotidienne. Si jamais exécuté E2E live, risque latent.

**Sous-tests** :
- **GAP-T1-03a** Open Z report (création row z_reports)
- **GAP-T1-03b** Close Z (audit `z_report.closed` + HMAC chain extension)
- **GAP-T1-03c** PDF download fonctionnel (binary content non-corrompu)
- **GAP-T1-03d** PDF content fiscal compliance (SIRET / TVA / cumul / dates)
- **GAP-T1-03e** Post-Z : nouveau cycle commence avec fiscal_sequence_no continuant

**Critères PASS** : count(z_reports) +1, audit count +1, PDF readable Adobe.

### GAP-T1-04 — Wizard kiosk 5 templates VISUAL LIVE

**Pourquoi critical** : owner a identifié wizard comme zone critique 3x (Cayenne sauce, Bowl 2 sauces, Gratiné). Wave A agent T2-Kiosk a flaggé wizard sauce comme INCONCLUSIVE (selector clicked category not Personnaliser).

**Sous-tests** :
- **GAP-T1-04a** Template sandwich (Sandwich Cayenne) visuel complet
- **GAP-T1-04b** Template burger (Big Cayenne) visuel complet
- **GAP-T1-04c** Template bowl (Bowl Frites Poulet mariné) visuel complet — gratiné cocher → total +2€
- **GAP-T1-04d** Template menu_formule (si existe en V1)
- **GAP-T1-04e** Template tacos (Tacos + Big Tacos)

**Critères PASS** : screenshot par étape × 5 templates × bonne sauce list verified DOM.

**Exécution** : data-test-id selectors à ajouter (Wave B RED-FN-01 P1 test-infra) OR brute force via screenshot manuel.

### GAP-T1-05 — Cross-surface LATENCE LIVE measured

**Pourquoi critical** : owner exige latence visible <3s Kiosk→KDS + <5s KDS→OSS. Wave B agent latency = NOT_MEASURED (auth blocker). Plan a échoué.

**Sous-tests** :
- **GAP-T1-05a** Kiosk → KDS : N=10 samples avg + p95 + max
- **GAP-T1-05b** POS → KDS : N=10 samples
- **GAP-T1-05c** KDS → OSS flip : N=10 samples
- **GAP-T1-05d** Stock cascade Admin→POS+Kiosk : N=10 samples

**Exécution** : Need to fix auth in Playwright spec FIRST (Node-side login with X-API-KEY header), then dispatch dedicated latency agent with proper idempotency-key headers.

### GAP-T1-06 — Cash drawer reconciliation E2E

**Pourquoi critical** : cash flow = legal + audit. Reconcile EOD est la signature de fin de journée caissier.

**Sous-tests** :
- **GAP-T1-06a** Open drawer (initial 100€)
- **GAP-T1-06b** N cash orders ajoutent au drawer
- **GAP-T1-06c** Close drawer : montant compté = montant attendu (ou écart documenté)
- **GAP-T1-06d** AuditLog cash_drawer.closed
- **GAP-T1-06e** Si écart : reason required (UX gate)

### GAP-T1-07 — Persona Cashier RUSH 15 orders / 5 min

**Pourquoi critical** : V1 doit tenir charge réelle restaurant midi/soir. Jamais simulé empiriquement.

**Sous-tests** : 15 orders enchaînés cash/card/wizard alternance — mesurer :
- Aucun timeout / freeze UI
- fiscal_sequence_no monotonic gap-free (15 séquences successives)
- Drawer balance correct end of rush
- Aucune erreur console
- KDS reçoit les 15 sans drop

### GAP-T1-08 — Persona Owner LATE-NIGHT Z + reconcile

**Sous-tests** : workflow end-of-day complet
- Review /admin/cash-overview totaux
- Reconcile écart drawer
- Cloture Z
- PDF Z download
- Backup déclenché (cron daily)
- audit chain extends 1 row

---

## §4 — TIER 2 — V1 TECHNICAL CONFIDENCE GAPS (SHOULD close)

Ces gaps représentent un **risque V1 ship moyen** : pas legal, mais confidence operationnelle / UX.

### GAP-T2-01 — WebSocket disconnect + reconnect storm

**Test** : Echo channel `private-branch.1` — kill Soketi, observe :
- KDS fallback polling 5-60s
- Reconnect storm protection
- Echo subscription restore

### GAP-T2-02 — Concurrent multi-cashier same drawer

**Test** : 2 sessions admin simultanées sur same POS → conflict expected

### GAP-T2-03 — Network drop mid-payment LIVE (DevTools throttle)

**Test** : kiosk wizard → payment → DevTools offline → restore → verify pas de double-charge

### GAP-T2-04 — DB N+1 + index coverage hot paths

**Test** : Activate query log around index + show + dashboard endpoints. Mesure count queries. Cible <10 per request.

### GAP-T2-05 — Idempotency cross-user race

**Test** : 2 cashiers concurrent same X-Idempotency-Key (different scope) — expect distinct orders

### GAP-T2-06 — KDS overflow stress 50+ orders

**Test** : pre-seed 50 orders status NEW → ouvrir KDS → vérifier "+N en attente" + scroll + bump

### GAP-T2-07 — Receipt printer offline simulation

**Test** : `print_failed` flag handling — order reste PAID mais flag visible

### GAP-T2-08 — TPE timeout simulation

**Test** : simulation TPE déclare timeout après 30s — order revert PENDING + audit log

### GAP-T2-09 — Cron jobs déclenchés artisan-side

**Tests** :
- `php artisan schedule:run` à minuit simulé → Outbox prune fires
- `php artisan foodking:outbox:retry-failed --since=24h` → DLQ replay
- `php artisan foodking:outbox:prune --older-than-days=90` → old events gone
- `php artisan foodking:z-loop-safety-net` → Z close si oublié

### GAP-T2-10 — Stock cascade DEEPER avec items composables

**Test** : item composé d'ingrédients (ItemIngredient) → ingrédient stock 0 → item devient unavailable cascade

---

## §5 — TIER 3 — DEEP COVERAGE GAPS (production-perfect target)

### GAP-T3-01 — i18n EN + AR full sweep

**Tests** : navigation complète /admin en EN puis AR (RTL). Vérifier :
- 0 raw labels
- Diacritiques préservés
- RTL layout OK (Sidebar/cards/grilles ne se cassent pas)

### GAP-T3-02 — A11y keyboard-only navigation

**Test** : Naviguer POS+Kiosk+KDS+Admin uniquement au clavier (Tab/Shift+Tab/Enter/Esc). Focus visible. Pas de piège focus.

### GAP-T3-03 — Screen reader (VoiceOver Mac OR NVDA)

**Test** : OSS wall display + KDS + POS lisibles à l'oreille. ARIA-labels présents sur icon buttons.

### GAP-T3-04 — Browser compatibility

**Tests** : Firefox + Safari + Edge sur :
- POS caisse (compatibilité Vanilla JS event listeners)
- Kiosk (CSS animation + flex layout)
- KDS (Echo websockets compatibilité)

### GAP-T3-05 — Mobile responsive POS / Admin

**Tests** : viewport 390×844 + 768×1024 sur POS / Admin / Items. Document P2 attendus.

### GAP-T3-06 — Performance bundle deep split

**Test** : Lighthouse mode prod sur 6 surfaces. Cible LCP <2.5s.

### GAP-T3-07 — Migration up/down testing

**Tests** :
- `php artisan migrate:fresh --seed --pretend` (dry-run)
- `php artisan migrate:rollback --step=1` puis re-apply (idempotency)
- DB schema integrity post-rollback

### GAP-T3-08 — Decimal precision boundaries production

**Tests** : 
- order avec quantité 99 × prix 9.99 = 989.01
- discount 100% (free order)
- Stripe Webhook event amount conversion

### GAP-T3-09 — French linguistic correctness

**Test** : sweep tous labels FR — vérifier orthographe + accords + ponctuation + apostrophes typographiques.

### GAP-T3-10 — Email transactional preview

**Tests** : 
- Order confirmation email render template
- Z report email (si existe)
- Password reset email

---

## §6 — TIER 4 — MOBILE + WEB STANDALONE

### GAP-T4-01 — Mobile RN /mobile/ surfaces NEVER tested this cycle

**Tests** : 18 pages × parity canonical menu :
- Onboarding 3 screens
- Catalog 9 categories
- Wizard 4 templates (sandwich/burger/bowl/menu_formule)
- Cart
- Loyalty
- Profile
- Owner photo paths 746KB Chicken + 733KB Big Burger + Nuggets + 1.4MB Cayenne hero

### GAP-T4-02 — Web standalone /Users/1millnonstop/Downloads/web/

**Tests** : 23 pages × 4 viewports (390 / 768 / 1280 / 1920) :
- Hero / Menu 11 categories
- Wizard 4 templates × 4 viewports
- Cart / Account
- 190 photos verify (no 404)

### GAP-T4-03 — Parity drift detection

**Test** : grep canonical menu data structure mobile vs web vs DB. Should be 100% identical or wire-up future-ready.

---

## §7 — TIER 5 — OBSERVABILITY + OPS

### GAP-T5-01 — Health endpoint deep `/healthz`

**Test** : récent heal `86c1efeba` ajouté `/healthz`. Vérifier :
- HTTP 200 quand sain
- DB connection check
- Redis connection check (si activé)
- Queue worker check

### GAP-T5-02 — Outbox failed jobs visibility

**Test** : Inject failed job → /admin/observability/outbox shows it → retry-failed clears

### GAP-T5-03 — Backup daily + restore drill

**Tests** :
- `php artisan db:backup` fire (or cron `* * * * *`)
- `storage/backups/db-daily/daily-YYYY-MM-DD.sql.gz` exists + non-zero
- DR drill : restore on side DB instance, verify schema match

### GAP-T5-04 — Logs structured

**Test** : tail `storage/logs/laravel.log` after several actions → vérifier structured (key=value), filterable.

### GAP-T5-05 — Production boot guards

**Test** : sim `APP_ENV=production` + `POS_SIMULATION_HARDWARE=true` → expect RuntimeException at boot (CLAUDE.md §8 protection).

---

## §8 — EXECUTION STRATEGY — Parallel Agents Matrix

### Recommandé : 3-Wave Execution

**WAVE C — Tier 1 Critical (10 agents parallèle, ~90 min)**

| # | Agent | Mission |
|---|---|---|
| 1 | T1-Live-POS-Payment-Cash | GAP-T1-01a cycle complet cash drawer→order→payment→close→Z partial |
| 2 | T1-Live-POS-Payment-Card | GAP-T1-01b cycle card simulation TPE |
| 3 | T1-Live-POS-Payment-SPLIT | GAP-T1-01c SPLIT cash+card |
| 4 | T1-Live-POS-Refund-Chain | GAP-T1-02 refund counter-entry NF525 mirror |
| 5 | T1-Live-Z-Close-PDF | GAP-T1-03 Z close + PDF + chain extension |
| 6 | T1-Visual-Wizard-5Templates | GAP-T1-04 5 templates wizard kiosk capture |
| 7 | T1-Latency-Fixed-Auth | GAP-T1-05 latency measurement avec auth+idempotency fix |
| 8 | T1-Cash-Reconcile-EOD | GAP-T1-06 drawer reconciliation E2E |
| 9 | T1-Persona-Rush-Cashier | GAP-T1-07 15 orders/5 min stress |
| 10 | T1-Persona-Owner-Night | GAP-T1-08 EOD workflow Z + reconcile |

**WAVE D — Tier 2 Technical Confidence (10 agents parallèle, ~75 min)**

| # | Agent | Mission |
|---|---|---|
| 11 | T2-WebSocket-Disconnect | GAP-T2-01 + GAP-T2-02 |
| 12 | T2-Network-Drop-Payment | GAP-T2-03 mid-payment recovery |
| 13 | T2-DB-Performance-Audit | GAP-T2-04 N+1 + index hot paths |
| 14 | T2-Idempotency-Race | GAP-T2-05 cross-user |
| 15 | T2-KDS-Overflow-50 | GAP-T2-06 stress |
| 16 | T2-Hardware-Failure-Sim | GAP-T2-07 + GAP-T2-08 receipt + TPE |
| 17 | T2-Cron-Schedule-Run | GAP-T2-09 outbox + Z safety-net cron |
| 18 | T2-Stock-Cascade-Ingredient | GAP-T2-10 ItemIngredient cascade |
| 19 | T2-Persona-Chef-Pressure | KDS 20 orders chef workflow |
| 20 | T2-Persona-Client-Impatient | Kiosk timeout + recovery |

**WAVE E — Tier 3+4+5 Deep (8 agents parallèle, ~60 min)**

| # | Agent | Mission |
|---|---|---|
| 21 | T3-i18n-EN-AR-Sweep | GAP-T3-01 |
| 22 | T3-A11y-Keyboard-Screen | GAP-T3-02 + GAP-T3-03 |
| 23 | T3-Performance-Lighthouse | GAP-T3-06 |
| 24 | T3-Migration-UpDown | GAP-T3-07 |
| 25 | T4-Mobile-Standalone | GAP-T4-01 18 pages mobile |
| 26 | T4-Web-Standalone | GAP-T4-02 23 pages × 4 viewports |
| 27 | T5-Observability-Health | GAP-T5-01 + GAP-T5-02 + GAP-T5-04 |
| 28 | T5-Backup-Restore-Drill | GAP-T5-03 |

**Total** : 28 agents en 3 vagues = ~3-4h wall-clock cumulé avec parallélisation max.

### Pré-requis avant Wave C

Avant de dispatcher Wave C, fix les blockers identifiés Wave B :
1. **Auth fix Playwright** : agents Wave C ont besoin auth working. Pre-build helper `tests/e2e/helpers/admin-auth.js` qui fait POST `/api/auth/login` avec `X-API-KEY` header + stocke token Bearer.
2. **Idempotency helper** : `tests/e2e/helpers/idempotency-key.js` qui génère UUID frais à chaque request mutation.
3. **DB clean state** : `php artisan migrate:fresh --seed --force` avant Wave C (re-establish chain from scratch — accepted post-T4-NF525 incident).

---

## §9 — CONVERGENCE CRITERIA per TIER

### Tier 1 — V1 SHIP-CRITICAL convergence

✅ **GREEN si TOUS** :
- 4 POS payment cycles cash+card+SPLIT+ticket-resto = audit chain growth 4 rows
- Refund = mirror order with parent_order_id + fresh fiscal_seq + chain extend
- Z close + PDF = z_reports +1 + audit +1 + PDF readable
- Wizard 5 templates capture visuelle confirme sauce list correct (DOM-validated)
- Latence Kiosk→KDS p95 <3s + KDS→OSS p95 <5s mesurée N=10
- Cash reconciliation EOD audit trail complete
- Persona Rush 15/5min sans timeout/erreur
- Persona Owner-Night Z complete cycle

### Tier 2 — V1 CONFIDENCE convergence

✅ **GREEN si TOUS** :
- WebSocket reconnect + polling fallback observé
- N+1 audit : <10 queries per index endpoint
- KDS stress 50 orders affichage cohérent
- Hardware failure simulation gracefully degraded
- Cron jobs fire + write to expected DB rows

### Tier 3+4+5 — DEEP/PRODUCTION-PERFECT

✅ **GREEN si** :
- EN+AR sweep : 0 raw labels + RTL layout OK
- Keyboard navigation full sans piège
- Lighthouse LCP <2.5s sur 3+ surfaces  
- Mobile + Web standalone canonical-parity verified
- Health endpoint comprehensive
- Backup restore drill success

---

## §10 — SEQUENCING RECOMMENDATION

```
HOUR 0   ──── PRE-WAVE-C SETUP ────
         ├─ Fix Playwright auth helper (15 min)
         ├─ Fix idempotency helper (10 min)
         ├─ DB clean state migrate:fresh --seed (5 min)
         └─ Bundle rebuild verification (10 min)

HOUR 0.5 ──── WAVE C : Tier 1 dispatch (10 agents parallel) ────
         ├─ Agents 1-10 work simultaneously
         ├─ Wall-clock: ~60-90 min for slowest agent
         └─ Aggregate findings + heal critical P0/P1 inline

HOUR 2   ──── REVIEW + HEAL Tier 1 ────
         ├─ Tier 1 convergence verdict
         ├─ Heal cycle (1-3 commits)
         └─ Re-run failed agents if needed

HOUR 3   ──── WAVE D : Tier 2 dispatch (10 agents parallel) ────
         └─ Agents 11-20

HOUR 4.5 ──── WAVE E : Tier 3+4+5 dispatch (8 agents parallel) ────
         └─ Agents 21-28

HOUR 6   ──── FINAL CONVERGENCE ────
         ├─ All-tier aggregation
         ├─ V1 SHIP verdict
         └─ BRAIN update + commit
```

**Total budget** : ~6-7h pour couverture maximale 3 tiers.

---

## §11 — ANTI-PATTERN CATALOG (what NOT to do)

| Anti-pattern | Pourquoi mauvais | Correct |
|---|---|---|
| Dispatch agents sans fix auth helper | 4 approches Wave B latency échoué | Fix helper FIRST |
| Lancer tous les 28 agents en 1 seule vague | API rate-limit + résultats confus | 3 vagues séquentielles |
| Skip Tier 1 pour aller direct Tier 3 | Couverture profonde sur fondations cassées | Tier 1 FIRST |
| Ignorer NUM-P0-01..04 comme "false positive" | Faux positifs sont des FAUX positifs (vérifier source) | Investiguer chaque P0 |
| Frozen-zone touch même 1 ligne | Discipline absolue CLAUDE.md §7 | Toujours 0 LOC |
| Push to remote sans owner approval | Human gate violation | NEVER auto-push |
| Visual "ressemble OK" sans grep DOM | Confirmation bias visuelle | Toujours DOM-validated |
| Latence "ça paraît rapide" sans measure | Mensurel pas perceptif | N=10 samples avec avg+p95+max |
| Persona "bien sûr ça passe" sans run | Optimisme infondé | Vrai workflow live |
| Cross-browser "Chrome only" | Firefox/Safari ont quirks | At least Firefox sanity check |
| EN/AR "FR only matter V1" | Risk silent bugs cross-locale | Quick sweep même 15 min |

---

## §12 — RISQUE PAR LACUNE (priorité absolue brain)

### TOP 5 risques V1 ship si Tier 1 NON exécuté

1. **🔴 NF525 chain réel jamais étendue empiriquement après seed** — risque audit France
2. **🔴 Refund mirror jamais validé en live** — risque rejet contrôle fiscal
3. **🔴 4 modes paiement jamais exercés cumulativement** — risque cash discrepancy production
4. **🟠 Latence Kiosk→KDS jamais mesurée** — risque UX client + chef KDS overflow
5. **🟠 Wizard 5 templates visuel non-DOM-validated** — risque drift Cayenne/Bowl post-déploiement

### TOP 5 risques V1.0.X si Tier 2+ NON exécuté

6. **🟡 WebSocket reconnect storm jamais simulé** — risque sync degradation prolongée
7. **🟡 N+1 jamais audité empiriquement** — risque DB slow on growth
8. **🟡 KDS overflow 50+ orders jamais stress-tested** — risque chef-rush UX collapse
9. **🟡 Mobile+Web standalone hors scope cycle** — risque parity drift detection late
10. **🟢 Cron jobs jamais déclenchés artisan-side** — risque silent fail prod

---

## §13 — RECOMMANDATION FINALE DU BRAIN

**Décision recommandée** : exécuter **Wave C (Tier 1, 10 agents)** **AUJOURD'HUI** avant tout autre travail. Convergence verdict après Tier 1 :
- ✅ GREEN → V1 SHIP-CONFIDENT (Tier 2+3 deviennent V1.0.X polish)
- ⚠️ AMBER → heal blockers + re-run Wave C avant Tier 2
- 🔴 RED → STOP, escalade owner pour décisions architecturales

Tier 2 + Tier 3 peuvent être étalés sur 2-3 jours selon disponibilité owner.

**Si tu veux je dispatch Wave C maintenant** : dis-moi GO et je :
1. Fix auth helper Playwright (~15 min)
2. Fix idempotency helper (~10 min)
3. DB clean state si tu acceptes le reset (~5 min)
4. Dispatch les 10 agents Wave C en 1 message parallèle (~60-90 min wall-clock)
5. Aggregate findings + heal P0/P1 inline
6. Verdict V1 ship GREEN/AMBER/RED

**Alternative** : si tu préfères faire le trial-test manuel d'abord, le plan ci-dessus reste valide pour exécution future.

---

## Fichiers référence

- `plans/MAXIMUM_TEST_PLAN_V1_LECAYENNE_2026-05-28.md` — playbook manuel owner
- `plans/SUPERVISOR_BRAIN_GAP_ANALYSIS_AND_PLAN_2026-05-28.md` — ce doc (gap analysis brain)
- `reports/test-e2e/owner-trial-test-max-2026-05-28/CONVERGENCE_FINAL.md` — état post-16-agents
- `PROJECT_BRAIN.md` §2 — state continuity cross-session
- `CLAUDE.md` §6-§10 — frozen-zones + NF525 + visual mandate + decision framework

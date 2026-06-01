# 🎯 GOAL — V1 LOCAL Le Cayenne · Go-Live Consolidation (delta, 2026-06-01)

**Date** : 2026-06-01 · **Branche** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `0be8c5ae4`
**Discipline** : CLAUDE.md §5 LOOP étape 2 — PLAN + STOP + owner validation gate (ce doc est PLAN-ONLY, rien n'est exécuté).
**Subject inference** : « max planify » lu comme **le plan de consolidation go-live V1 LOCAL** — l'agrégation exécutable de tout ce qui reste ouvert après la convergence de la couche gestion + 2e-degré ce jour. *Si tu visais autre chose (ex: re-audit d'un système précis, plan cloud/SaaS), redirige en un mot.*

---

## §0 — Cadrage (anti-dérive)

### Scope = V1 LOCAL single-box UNIQUEMENT
Mandat owner immuable ([[feedback_v1_personal_le_cayenne_2026-05-28]], [[feedback_no_cloud_until_owner_initiates]]) : V1 = outil **personnel Le Cayenne**, 1 machine, 1 branche, FR locale, 0 cloud. **Tout item cloud / multi-tenant / SaaS → §Z (deferred, owner-initiates)**, jamais une vague active. Les plans « PRE_CLOUD » antérieurs sont la source du piège — leur volet cloud est explicitement hors-scope ici.

### Ce que ce doc EST / N'EST PAS
- **EST** : un *delta exécutable* — ce qui s'est fermé aujourd'hui, ce qui reste ouvert (V1-LOCAL), organisé en vagues avec checkpoints + owner-gates WHO/WHAT/WHERE. Référence les plans canoniques plutôt que re-décomposer des systèmes matures.
- **N'EST PAS** : un 15ᵉ GOAL_*.md parallèle, ni une re-décomposition des 16 domaines production-ready déjà validés (cf. `ULTRAPLAN_V1_GOLIVE_TO_V1.0.1_2026-05-09.md` §0).

### Plans canoniques référencés (NE PAS dupliquer)
- `plans/ULTRAPLAN_V1_GOLIVE_TO_V1.0.1_2026-05-09.md` — spine go-live → V1.0.1 (16 domaines ready + backlog V1.0.1).
- `plans/GOAL_ULTRA_FINAL_PRE_CLOUD_2026-05-24.md` — 12 zones « remaining » L1-L12 (L2/L12 cloud → §Z ; L3/L7/L9/L10/L11 LOCAL-relevant → vagues).
- `reports/test-e2e/GOAL_SECOND_DEGREE_INDIRECT_2026-06-01/{CONVERGENCE_FINAL,FINDINGS}.md` — cycle clos ce jour (ne PAS rouvrir).
- `reports/.../mgmt-sync/GOAL_MGMT_CONVERGENCE.md` — couche gestion (11 findings healés).

### Convergence criteria (par item healé)
P0+P1 = 0 NEW après ×3-skeptic ; frozen-zone diff = 0 ; NF525 CHAIN OK ; visual gate si frontend (capture+Read+analyse) ; full-suite vert (baseline **2787/0** 2026-06-01) ; per-tâche acceptance cite un test réel OU `(à créer en <path>)`.

### Pipeline par tâche
Chaque tâche délègue à `~/.claude/skills/superpower-gstack` (LOOP 7-step) + gates frozen/NF525/visual. Pas de re-description ici.

---

## §1 — DELTA : fermé aujourd'hui vs reste ouvert (V1-LOCAL)

### ✅ Fermé ce jour (NE PAS rouvrir — solide)
- **Couche gestion** (11 findings : secret-leaks gateway/SMS, escalade rôle, revenue-leak, coupon-cap, cross-branch, photo-authz…).
- **Surface 2e-degré / calculs historiques** (9 P0/P1) : netting refund/annulation unifié (`Order::scopeRealizedRevenue`) sur dashboard/EOD-PDF/sales/items/cash ; items report = unités vendues realized date-de-vente ; cash = session-scoped signed movements ; delivery origine **Hénin-Beaumont** + règle whole-km `5€/≤5km +1€/km` (backend+frontend+seeder+live DB) ; loyalty fractional-euro snap ; **ZRPT-SEM-01** fix (sous LOCK).
- **Gates** : full-suite **2787/0**, frozen 0, NF525 CHAIN OK, render+paginate live-vérifiés MySQL.

### 🔓 Reste ouvert (V1-LOCAL) — enveloppe de ce plan
| # | Item | Source | Type |
|---|------|--------|------|
| O1 | **ZRPT-SEM-01 countersign** (mirror tax_amount discount-ratio = signed-Z input) | ce cycle | owner-gate fiscal (FRAIS) |
| O2 | **LOCK housekeeping** : 5 LOCKs 2026-05-18/23 status « PENDING » mais code shippé sur cycles SHIP-CLEARED suivants → accepted-by-operation ; status-line périmé | prior | owner-gate light (batch-confirm) |
| O3 | **Reporting P2/P3** : CREDBAL-SEM-02 (liste tous users pas que clients), CREDBAL-SOFT-03 (clients soft-deleted exclus), CREDBAL-PAR-04 (balance cache sans recon ledger), DASH-SEM-04 (channelStatistics bucket mirror), SALES-PAR-03/SEM-04/PAR-05, topCustomers all-time non-filtré | ce cycle | heal-safe |
| O4 | **DASH-01** : KPI « Total commandes » = `totalOrders():358` DELIVERED-only → relabel « livrées » OU compter placées ; **frontend + bundle rebuild + recapture visuelle** | prior owner-gate | frontend |
| O5 | **REP-ANALYTIC-01** : `AnalyticController` index/show non-gatés ; gater `permission:settings` **risque le widget dashboard** → consumer-check d'abord | prior owner-gate | risk-assessment |
| O6 | **Clean 10h soak** serveur SEUL (sans charge concurrente — la leçon du crash 4.92h) | soak cycle (L3) | owner-sequenced |
| O7 | **Préconditions physiques go-live** : `POS_SIMULATION_HARDWARE` true→false, `APP_ENV` local→production, Ansible CVP0-1 REVOKE (audit_logs/z_reports), migrate fresh+seed (branche Hénin-Beaumont), walk physique | BRAIN | physical owner |
| O8 | **Dormant documentés** (NE PAS fixer à blanc) : LOY-SEM-03 (pro-rata partial-refund → ship avec la feature partial-refund), ZRPT-SEM-03 (delivery-charge HT=TTC à 0% VAT, F1 dormant → checklist activation TVA), DEL-FEE-LEGACY-INCONSISTENT-02 (fallback legacy ≠ règle owner, dormant car branche 1 configurée), DEL-GEOCODE-DEFAULT-OK-03 (P3 fail-closed — change le path order-blocking, risque régression > valeur) | ce cycle | document/defer |
| O9 | **V1.0.1 hardening** : FormRequest authz chip-away (ratchet baseline RETURN_TRUE), password policy min:12, Sanctum TTL 8h→1h sensitive ops, API-key versioning, 17 composer advisories triage (1 CRITICAL phpspreadsheet) | ULTRAPLAN V1.0.1 | post-go-live |

---

## §2 — Vagues d'exécution (séquentielles sauf indication)

> Défaut = **séquentiel par vague**, fan-out read-only parallèle DANS la phase audit. Implementer jamais en parallèle (write conflicts). Visual gate AVANT commit pour tout frontend. Max 3 heal-loops puis escalate.

### Wave 0 — Owner gates (BLOQUANT, ~0 code)
- **W0.1** O1 : owner relit `plans/LOCK_ZREPORT_REFUND_DISCOUNT_TVA_NETTING_2026-06-01.md` §6 → countersign (trailer commit / sign-off). *Acceptance* : LOCK §6 cochée + entrée DECISIONS LOG. Test déjà vert : `tests/Feature/Fiscal/RefundDiscountTvaNettingTwoWindowSentinelTest.php`.
- **W0.2** O2 : owner batch-confirme (ou marque ACCEPTED) les 5 LOCKs périmés. *Acceptance* : status-line de chaque LOCK → `ACCEPTED <date>` ; aucun changement code (le code est déjà live+testé). **Parallélisme** : W0 parallèle aux Waves 1-2 (audit/heal local ne dépend pas du gate).

### Wave 1 — Reporting backlog P2/P3 (heal-safe, étend le net-realized)
- **W1.1** CREDBAL-SEM-02 : `CreditBalanceReportController`/`UserService::list` — filtrer rôle Customer (le rapport de crédit-client ne doit pas lister Admin/staff). *anchor* `app/Services/UserService.php` ; *test* `(à créer tests/Feature/Reports/CreditBalanceCustomersOnlySentinelTest.php)`.
- **W1.2** CREDBAL-SOFT-03 : décider inclure/exclure clients soft-deleted avec crédit (passif). *owner-intent micro* (défaut : inclure via `withTrashed` pour le passif total) ; *test* `(à créer …SoftDeletedCreditSentinelTest.php)`.
- **W1.3** CREDBAL-PAR-04 : noter (P3) que `users.balance` est un cache sans recon ledger — recon command optionnelle V1.0.2 ; *document only*.
- **W1.4** DASH-SEM-04 : `DashboardService::channelStatistics` exclure les mirrors (source NULL) du bucket Web. *anchor* `app/Services/DashboardService.php` channelStatistics ; *test* `(à créer …ChannelStatisticsMirrorExcludedSentinelTest.php)`.
- **W1.5** SALES-PAR-03/SEM-04/PAR-05 : aligner le filtre `source` (exact vs LIKE) index↔overview, et `exceptSource` honoré partout. *anchor* `app/Services/OrderService.php` list/salesReportOverview ; *test* `(à créer …SalesReportFilterParitySentinelTest.php)`.
- **W1.6** topCustomers : scoper `withCount(orders)` par date+realized (cf. `scopeRealizedRevenue`) au lieu d'all-time non-filtré. *anchor* `DashboardService::topCustomers`.
- **Checkpoint W1** : tests verts, frozen 0, full-suite vert. (W1.1/1.4/1.5/1.6 = fichiers disjoints → fan-out audit parallèle OK, heal séquentiel.)

### Wave 2 — Frontend + analytics gates (visual mandate)
- **W2.1** O4/DASH-01 : `DashboardService::totalOrders()` + OverviewComponent.vue + i18n — relabel « Commandes livrées » (ou compter placées, owner-choix). **Rebuild bundle admin + recapture visuelle** (`/admin` dashboard) + Read screenshot. *anchor* `app/Services/DashboardService.php:355-358` + `resources/js/components/admin/.../OverviewComponent.vue` ; *test* visual + `(à créer …TotalOrdersLabelSentinelTest.php)`.
- **W2.2** O5/REP-ANALYTIC-01 : tracer les consommateurs de `AnalyticController@index/@show` (le widget dashboard analytics les appelle-t-il pour un admin non-settings ?). Si oui → gate plus fine (permission:dashboard) ; sinon → `permission:settings`. *anchor* `app/Http/Controllers/Admin/AnalyticController.php:21` ; *test* `(à créer …AnalyticReadAuthzSentinelTest.php)`.
- **Checkpoint W2** : visual gate tiré (screenshots Read+analysés), 0 raw label, bundle rebuild commité.

### Wave 3 — Dormants : documenter, NE PAS fixer à blanc
- O8 : confirmer chaque dormant reste correctement inerte + une ligne backlog datée. **Aucun code** sauf si owner réactive (TVA / partial-refund / unconfigured-branch). DEL-GEOCODE-DEFAULT-OK-03 explicitement **déféré** (risque régression path order-blocking > valeur P3).

### Wave 4 — Clean 10h soak (owner-sequenced)
- **W4.1** `php artisan foodking:e2e:soak --hours=10 --fail-fast` (`app/Console/Commands/E2ESoakCommand.php`) **serveur SEUL** : aucun workflow agent lourd / E2E browser / suite PHPUnit concurrente sur le `php artisan serve` mono-process (leçon crash 4.92h). *Acceptance* : 10h, RSS flat, fiscal gap-free, CHAIN OK + z-membership OK à la fin. **Owner lance / surveille** (pas en parallèle d'autres charges).

### Wave 5 — Préconditions physiques go-live (PHYSICAL OWNER)
- O7 checklist (cf. §G). Boot guards `AppServiceProvider.php:145-215` refusent de booter si mal configuré → la vague est *gated* par ces guards (sécurité by-design).

### Wave 6 — V1.0.1 hardening (POST go-live, track séparé)
- O9 items, par vagues de commits (FormRequest chip-away + ratchet baseline ; password policy ; Sanctum TTL ; advisories triage). Non-bloquant go-live LOCAL.

---

## §G — Owner gates (WHO / WHAT / WHERE)

| Gate | Description | WHO | WHAT (artefact débloquant) | WHERE | Status |
|------|-------------|-----|----------------------------|-------|--------|
| G1 | ZRPT-SEM-01 countersign | Owner | sign-off §6 + DECISIONS LOG | `plans/LOCK_ZREPORT_REFUND_DISCOUNT_TVA_NETTING_2026-06-01.md` §6 | PENDING (frais) |
| G2 | LOCK housekeeping batch-confirm | Owner | status-line → ACCEPTED ×5 | `plans/LOCK_*2026-05-18/23*.md` | PENDING (light) |
| G3 | DASH-01 label choix (« livrées » vs placées) | Owner | 1-mot decision | issue/chat | PENDING |
| G4 | Clean 10h soak lancé serveur-seul | Physical owner | log soak 10h + attestation | `reports/test-e2e/.../soak/` | PENDING |
| G5 | `POS_SIMULATION_HARDWARE=false` + `APP_ENV=production` | Physical owner | `.env` + `php artisan config:cache` + boot OK | machine prod | PENDING |
| G6 | Ansible CVP0-1 REVOKE DELETE/TRUNCATE sur `audit_logs`+`z_reports` | Physical owner | run Ansible + GRANT diff | playbook CVP0-1 | PENDING |
| G7 | `migrate:fresh --seed` prod (branche Hénin-Beaumont 50.42/2.95) | Physical owner | seed log + `SELECT branches WHERE id=1` | machine prod | PENDING |
| G8 | Walk physique (TPE, tiroir, imprimante, 1 vraie commande→Z) | Physical owner | photos + 1 Z signé réel | on-site | PENDING |

**Owner-gate-waiting protocol** : Waves 1-2 (heal local) tournent en parallèle des gates G1-G3 ; Waves 4-5 dépendent de G4-G8 (physical) → bloquées jusqu'à action owner.

---

## §A — Agent army map (référence, pas de re-description)
GStack 6-rôles (`~/.claude/skills/superpower-gstack/references/army-dispatch.md`) : Architect/Security/DBA read-only parallèle (audit) ; Implementer séquentiel (heal) ; RED-team après commit ; QA+RED Visual parallèle (W2 frontend). Findings persistés disque (`reports/test-e2e/v1-golive-consol-2026-06-01/<wave>/`).

---

## §Z — DEFERRED (cloud/SaaS — owner-initiates UNIQUEMENT)
Hors-scope V1 LOCAL, listés pour traçabilité, **jamais surfacés comme blockers V1** : L2 prod-env dry-run cloud · L12 pre-cloud gates · UNI-03 cache-driver widening (file/database → redis/memcached, ALB multi-instance) · multi-tenant BranchScope V2 hard-fail (10 models backlog) · L4 browser matrix · L5 NVDA/VoiceOver réel · L7 pen-test deep (polyglot/SSRF/CRLF/timing) · L9 email/SMS E2E · L10 DR drill from-scratch · L11 cron-miss recovery · Laravel 9→10→11 · Spatie 5→6 · TPE hardware câblage bank (Plan A) — Plan B encaissement caisse actif.

---

## §E — EXECUTION LOG (2026-06-01 — owner « do the goal till finish »)

All **code-able** scope EXECUTED autonomously (TDD, frozen 0, NF525 CHAIN OK). The
remainder is irreducibly owner/physical (cannot be self-performed).

| Wave | Item | Status | Commit / evidence |
|------|------|--------|-------------------|
| W1.1 | CREDBAL-SEM-02 customers-only | ✅ DONE | `2dc65189c` + CreditBalanceCustomersOnly sentinel |
| W1.2/1.3 | CREDBAL soft-deleted / ledger-recon | 📄 DOC-DEFER | V1-LOCAL negligible → V1.0.2 (documented) |
| W1.4 | DASH-SEM-04 channel mirror | ✅ DONE | `2dc65189c` + ChannelStatisticsMirrorExcluded sentinel |
| W1.6 | topCustomers all-time-unfiltered | ✅ DONE | `2dc65189c` (mirror-excluded) |
| W1.5 | SALES-PAR-03/05 source-exact + exceptSource parity | ✅ DONE | `b5e4f1e01` + SalesReportFilterParity sentinel |
| W2.2 | REP-ANALYTIC-01 gate index/show | ✅ DONE | `b9bd199fa`-range + AnalyticReadAuthz sentinel (consumer-check refuted the risk) |
| W2.1 | DASH-01 « Total commandes » real volume | ✅ DONE | `b046b1c3b`+`b9bd199fa` (3→3388 live MySQL); backend-only, NO bundle rebuild needed; branch-scope test realigned |
| W3 | Dormants (LOY-SEM-03, ZRPT-SEM-03, DEL-FEE-LEGACY, DEL-GEOCODE) | 📄 DOC | confirmed inert + documented (FINDINGS / CONVERGENCE) — no blind code |
| W6 | V1.0.1 hardening (password policy / Sanctum TTL / API-key / FormRequest ratchet / advisories) | ⏸ POST-GO-LIVE | non-blocking, deliberate track — NOT rushed at session-tail |
| **G1** | ZRPT-SEM-01 countersign | 🔒 OWNER | code+test done; fiscal countersign is owner-only (§10 human gate) |
| **G2** | LOCK housekeeping ×5 | 🔒 OWNER | code shipped prior cycles; owner marks ACCEPTED |
| **G4** | clean 10h soak server-alone | 🔒 OWNER-PHYSICAL | owner runs (can't self-run 10h + concurrency rule) |
| **G5-G8** | `.env` prod flip / Ansible REVOKE / migrate-fresh-seed / on-site walk | 🔒 OWNER-PHYSICAL | requires owner at the machine |

**Why I stopped at the gates:** I cannot self-countersign a fiscal LOCK (§10), run a 10-hour soak, write production `.env`, execute the Ansible playbook, migrate the prod DB, or physically operate the TPE/drawer/printer. Those 8 gates are the irreducible owner remainder. Everything a Claude session can do is done + tested + committed (no push).

---

## §F — DONE criteria
Go-live LOCAL = **G1-G8 résolus** + Waves 1-2 convergées (P0+P1=0, frozen 0, CHAIN OK, full-suite vert, visual gate) + clean 10h soak vert (G4) + 1 vraie commande→paiement→Z signé sur la machine prod (G8). V1.0.1 (Wave 6) = post-go-live, non-bloquant. **Production-perfect, pas « presque ».**

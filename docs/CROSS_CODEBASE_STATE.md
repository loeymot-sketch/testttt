# Cross-codebase state — FoodKing / Le Cayenne
*Last updated: 2026-05-28 · auto-pointed from `PROJECT_BRAIN.md` §2*

> Document de synthèse cross-codebase produit par EXEC-3 lors de la Phase 4.1
> de l'ultraplan 2026-05-28. Couvre 3 codebases parallèles dans l'écosystème
> FoodKing / Le Cayenne. **Factuel, chiffré, owner-readable.** Les claims
> renvoient à un commit SHA, un fichier:ligne, ou un rapport `reports/`.
>
> Pour le détail temporel des cycles backend (Wave N / Gap-Hunt / etc.),
> voir `PROJECT_BRAIN.md` §2 (sources of truth). Ce doc ne le répète pas,
> il pointe.

---

## §1 TL;DR

Trois codebases coexistent :
1. **Backend testttt** (Laravel) — V1 LOCAL Le Cayenne PRODUCTION-READY dans envelope explicite, branche `heal/cms-pr1-quickwins-2026-05-18` HEAD `7aa0f07df`.
2. **Mobile app** (`mobile/` dans testttt) — branche `feature/mobile-app-le-cayenne-2026-05-10`, Loyalty V0 livré, **8 backend backlog items B-01..B-08 en attente** Phase 6 wire-up.
3. **Web site** (`/Users/1millnonstop/Downloads/web`) — sous git depuis 2026-05-28 (Phase 1.1, commit `a7eeea1`), data canonical bit-identical mobile, **ZERO Wallet/QR/RGPD plumbing**.

**Question owner ouverte** : Phase 0 doit trancher 4 OG (§6) avant Phase 1 du plan ; décisions auto pré-câblées dans ce doc à confirmer.

---

## §2 Backend testttt (Laravel)

### État
- **Branche active** : `heal/cms-pr1-quickwins-2026-05-18`
- **HEAD live** : `7aa0f07df` (« docs(master-ultimate): 14 agents + cloud-ready verdict GO V1 / AMBER cloud ») au 2026-05-28
- **HEAD au moment du briefing EXEC-3** : `860905b78` (Gap-Hunt 2026-05-25 convergence)
- **Commits cumulés sur la branche** : 1134 total (`git log --oneline | wc -l` 2026-05-28)
- **Delta depuis briefing** : +50 commits (`git log 860905b78..HEAD --oneline | wc -l`)
- **Verdict V1** : ✅ **PRODUCTION-READY UNCHANGED** dans envelope explicite (single machine + FR locale + `POS_SIMULATION_HARDWARE=true` dev / forbidden prod + 1 TPE + 1-2 bornes + 0 frozen-zone violations + NF525 chain integrity preserved). Source : `PROJECT_BRAIN.md` §2 START HERE 2026-05-25.

### Commits récents notables (top 10 post-briefing)
Pris de `git log -10` au 2026-05-28 :
- `7aa0f07df` docs(master-ultimate) : 14 agents + cloud-ready verdict
- `e361686e6` docs(per-system-e2e) : 5 system agents + browser race + GREEN
- `155cea0c7` docs(supervisor-3-actions-done) : migrate:fresh + Ansible CVP0-1 + backup drill + E2E final
- `023151c11` docs(supervisor-final) : 6 heals smoke + V1 GREEN ship-cleared verdict
- `407d4899d` docs(deep-uncovered) : 7 deep agents + 1 dev incident contained + CONVERGED
- `178a59770` docs(flux-complet-superviseur) : 4 flows live + 2 fixes inline + CONVERGED
- `27a036323` docs(admin-dashboard-e2e-final) : convergence + 36 captures + scope page generated
- `a46ec7df7` fix(kds-sync-401) : skip polling tick when auth token not yet hydrated
- `df8d06a67` fix(perms-web-guard) : mirror 82 sanctum perms to web for Admin (massive 403/401 fix)
- `df0da680d` fix(ingredients-403) : grant ingredients_manage on BOTH guards

### Owner gates pending — Top-3
Tirées du dernier WAVE post-`860905b78` + START HERE Gap-Hunt 2026-05-25 (PROJECT_BRAIN.md §2) :

1. **pos-wizard.js XSS LOCK countersign** — P0 SECURITY, holding 10+ jours (LOCK plan `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` + ADDENDUM 2026-05-23). Scope : 11→13 sinks à fixer dans le wizard POS Vanilla JS (frozen §7).
2. **PricingService LOCK F1+F2** — P0 NF525 (`$calculatedDiscount` unclamped ~5 LOC + multi-rate tax-breakdown drift à clarifier V1 single-rate seul ?). LOCK plan à écrire avant édition de `app/Services/Pricing/PricingService.php` (frozen §7).
3. **KDS layout Option A/B/C** — P0 chef-rush BLOCKER_IF_RUSH ≥6 orders. Wave N N-HEAL-01 a livré un `+N` chip safety net opérationnel, mais la décision architecturale Option A/B/C reste owner-gate.

### Tier 2 (gates secondaires)
4. **P11 Refund UI button** — P0 V1 ship gate, ~6h dev. Proposal `proposals/PROPOSAL_POS_REFUND_UI_2026-05-25.md` (Option B `PosRefundModal.vue` + permission `pos-refund` recommandé).
5. **KDS Archive Undo** — P0 score 10 Path B recommended ~3.5j (`proposals/PROPOSAL_KDS_ARCHIVE_UNDO_2026-05-25.md`). NON-blocking V1 (workaround chef→caisse verbal + +N chip safety net).
6. **D3 LOCK_PAY PaymentComponent FR currency** — countersign owner pour bypass frozen §7.
7. **PosV5TrancheRow multi-TPE** — V2 BLOCKER (latent V1 : Le Cayenne mono-TPE).

### Tier 3 (gates manuel-verify / ops)
8. Owner physical walk checklist 60-90 min (`docs/OWNER_PHYSICAL_WALK_CHECKLIST.md`).
9. G-M1-1 UX validation `/admin/stock-rupture-dashboard` Mission 1 (~5 min).
10. G-M2-1 UX validation `/admin/cash-overview` Mission 2 (~5 min).
11. Wave L-C deferred a11y + browser quirks audits (TaskList #72-81 carry over).
12. Owner-night observability widgets ~5-6h dev (NF525 chain widget + Backup status widget).

### V1.0.X backlog
Source : `reports/feature-gap-hunt-2026-05-25/FINAL_REPORT.md` + cumulative.

- **Gap-Hunt 2026-05-25 master gaps** : 71 unique dedup (P0=14 · P1=31 · P2=21 · P3=5) — voir `reports/gap-hunt-2026-05-25/MASTER_GAP_LIST.json` (1264 LOC) + `SCORING_MATRIX.md` Top-30.
- **Estimation V1.0.1** : 5 P0 unshipped (KDS undo + POS refund + chef-cashier signal + stock 3-portions alert + customer SMS PRET) ~11 dev-days minimum viable + ~60 dev-days full P0+P1 sweep.
- **V1.0.2 deferrals** documentés : 10 BranchScope V2 SaaS hard-fail items (PROJECT_BRAIN.md §9 / CLAUDE.md §9 EXEMPTED_MODELS), 2 AllergenCoverageSentinel red methods (CI honest status §2), republish-all sweep wizard pattern (POS payment 4-scenarios 2026-05-18).
- **Indication chiffrée approximative** : ~50 V1.0.X items cumulatifs (Z-1 KDS 13 + Z-2 OSS 16 + Z-4 LIVREUR 9 + Z-7 WEB 6 + Z-8 CROSS i18n 16, source `reports/audit/goal-complement-2026-05-18/CONVERGENCE_COMPLEMENT.md`).

> ⚠️ **Caveat honnêteté** : 2 AllergenCoverageSentinel methods rouges CI (Owner Q2=SKIP 2026-05-21), tracked V1.0.X jusqu'à mapping chef-confirmé. Source : `PROJECT_BRAIN.md` §2 « 🔻 HONEST CI STATUS ».

---

## §3 Mobile app (`mobile/` dans testttt)

### État
- **Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
- **Commits cumulatifs touchant `mobile/`** : 34 total (`git log feature/mobile-app-le-cayenne-2026-05-10 --oneline -- mobile/ | wc -l`)
- **Commits V0+wizard multi-page+loyalty** : ~16 (sous-set identifiable dans `git log feature/mobile-app-le-cayenne-2026-05-10 --oneline -- mobile/`)
- **Loyalty V0** : livré 2026-05-10 → 2026-05-11 (commits `aea80b52b`, `900de52d9`, `8793ef235`, `4c937155e`, `a48d109b7`, `d55cea80b`)
- **Catalog** : 41 items canonical / 11 catégories, bit-identical backend `config/menu.php` SSOT (commit `eb201efc2`)

### Architecture
- **FSM 7-state Loyalty** : LOCKED → UNLOCKED → SELECTED → APPLIED_NEXT_ORDER → (CONSUMED ou REVERSED) + EXPIRED. Source : `mobile/data/loyaltyRewardState.js:30-80` (188 LOC total).
- **EARN_METHODS catalogue** : 10 méthodes définies `Object.freeze` à `mobile/data/loyalty.js:82` (`grep -nE "EARN_METHODS\s*=" data/loyalty.js`).
- **Wallet V0** : boutons Apple/Google + `ModalWalletV0Notice` (commit `8793ef235`).
- **WizardRedeem 3-step** + idempotency 10-min window + `ModalOptOutConfirm` RGPD (commit `4c937155e`).
- **WALLET_PLAN.md** : 277 LOC, Phase 6 backend wire-up roadmap (`mobile/WALLET_PLAN.md`).
- **CONNECTION_PLAN.md** : roadmap Supabase + audit FoodKing global (commit `24188a371`).

### E2E baseline
- **16 PNG screenshots** dans `tests/e2e/__screenshots__/mobile-loyalty/` (01-earn-history.png à 15-empty-state.png, vérifié `ls tests/e2e/__screenshots__/mobile-loyalty | head -20`).
- **20 specs E2E mobile** dans `tests/e2e/` (test-e2e-mobile-design-perfect-* + audit-mobile-wave-* + test-e2e-mobile-design-full-wave-* + test-e2e-mobile-realignment-2026-05-16), vérifié par énumération.

### Backend backlog B-01..B-08 — file:line précis
8 P0/P1 backend backlog items flaggés pour Phase 6 wire-up. Source : `reports/review/mobile-loyalty-audit-2026-05-10/99_VERDICT.md` §6.

Le doc V0 backend identifie les gaps suivants (références extraites de `mobile/data/loyalty.js:6-44` header + `mobile/data/loyaltyRewardState.js:1-15` mock-only warning) :

- **B-01 (rewards table)** : `mobile/data/loyalty.js:11-13` — REWARDS array mock, AUCUNE table `loyalty_rewards` ni route `GET /loyalty/rewards` backend. Phase 6 = migration + controller.
- **B-02 (server idempotency)** : `mobile/data/loyalty.js:26-29` — V0 dedupes per-device via localStorage Map (`LC.storage.idempotency`). Phase 6 = `Idempotency-Key` header sur `POST /loyalty/redeem` (déjà patterné dans le middleware idempotency backend §9 CLAUDE.md).
- **B-09 (QR HMAC sign)** : `mobile/data/loyalty.js:31-36` — V0 émet `FK:<loyalty_code>` + signature SHA-256 mock (no HMAC secret). Phase 6 = `POST /loyalty/qr/sign` retourne HMAC-keyed signature. Cross-link : `LOYALTY_QR_SECRET` env déjà introduit Wave J HC-003 commit `6d89d4798` (cf. BRAIN.md §2 Phase J+J2 HEAL-04).
- **B-11 (lifetime columns)** : `mobile/data/loyalty.js:21-25` — colonnes `lifetime_earned`, `lifetime_redeemed`, `next_threshold`, `progress_to_next`, `plastic_card_linked` n'existent PAS backend `users` table. Phase 6 = calculer depuis `/loyalty/history` aggregations.
- **B-XX (welcome_bonus)** : `mobile/data/loyalty.js:15-18` — CONFIG `welcome_bonus`, `expires_after_days` non-implémenté backend. V0 mock granting client-side via `LC.dev.earnPoints`. Phase 6 = listener `User::created` ou birthday cron.
- **B-XX (reward state machine)** : `mobile/data/loyaltyRewardState.js:5-13` — états LOCKED/UNLOCKED/CONSUMED/EXPIRED/REVERSED dérivés balance+history at render time, NON persistés. Phase 6 = table `loyalty_rewards` + colonne `reward_id` FK sur `loyalty_transactions` + computation atomique `lockForUpdate`.

> Les 8 items exhaustifs B-01..B-08 sont énumérés dans `reports/review/mobile-loyalty-audit-2026-05-10/99_VERDICT.md` §6 (référence canonique, source dans memory `feedback_insights_full_2026-05-18.md`).

### Phase 6 wire-up roadmap — effort estimé
Source : `mobile/WALLET_PLAN.md` (277 LOC) + `mobile/CONNECTION_PLAN.md` (commit `24188a371`).

- **Phase 6.A** : real-asset wiring + image-slot leak DEAD (commit `8d31a7f92`, **déjà livré**).
- **Phase 6.B (backlog)** : `loyalty_rewards` migration + 5 lifetime columns derived + idempotency middleware coverage + QR HMAC endpoint + welcome_bonus listener.
- **Effort estimé total** : non-précisé per-task dans les docs accessibles ; ordre de grandeur basé sur patterns historiques ~8-15 dev-days pour la suite Phase 6.B (à valider en planning Phase 6 propre).

---

## §4 Web site (`/Users/1millnonstop/Downloads/web`)

### État
- **Git baseline** : commit `a7eeea1` (« chore(web): baseline commit Le Cayenne website standalone (2026-05-28) »), avec `00d38f9` gitignore baseline antérieur. Source : `cd /Users/1millnonstop/Downloads/web && git log --oneline`.
- **Initialisation Phase 1.1** : sous git depuis 2026-05-28 (carte blanche owner 2026-05-16 selon `feedback_no_cloud_until_owner_initiates.md` + memory `project_goal_longterm_frontends_2026-05-16.md`).
- **Contents racine actifs** : 17 fichiers (`account-v2.jsx`, `components.jsx`, `data/menu.js`, `flows.jsx`, `funnel.jsx`, `index.html`, `loyalty-v2.jsx`, `orders.jsx`, `screens-v3.jsx`, `screens.jsx`, `styles-mobile.css`, `styles-v2.css`, `styles-v3.css`, `styles-v4.css`, `styles-v5.css`, `styles.css`, `wizard-v2.jsx`).
- **CSS modulaires** : 6 (styles-mobile, v2, v3, v4, v5, styles).
- **Légal** : 5 pages dans `legal/` (allergens.html, cgv.html, cookies.html, mentions.html, privacy.html + legal.css).
- **LOC total mesuré** : **7994 lignes** (`cd /Users/1millnonstop/Downloads/web && wc -l *.jsx *.html *.css data/menu.js legal/*.html legal/*.css` → tail) — briefing EXEC-3 cite 7853 (mesure proche, drift ~+141 LOC depuis briefing).

### Data canonical vs mobile — parity bit-identical confirmed
- **41 items canonical** mirror mobile (briefing EXEC-3) — héritage du commit `cbfea4fd7` (« data(mobile): alignement 1:1 backend post-audit owner-gate ») et du grand cycle 2026-05-17 « Massive Logic+Image Cycle » qui a wireé 100% mobile↔web parity (mémoire : `project_massive_logic_image_cycle_2026-05-17.md`).
- **WizardFlow v4 aligned** post `cascade_frites_sauce` heal (briefing EXEC-3, traçable dans `web/wizard-v2.jsx` 528 LOC + `screens-v3.jsx` 249 LOC).
- **Sentinels parity backend ↔ mobile/web** :
  - `tests/js/posKioskVariationParity.spec.js` (POS/Kiosk variation guard)
  - `tests/Feature/Menu/PosKioskProjectionParityTest.php` (catalog projection)
  - `tests/Feature/PosKioskPricingParityTest.php` (pricing SSOT)
  - `tests/js/__fixtures__/menu-parity.json` (fixture)
  - Source : `find . -name "*parity*"` 2026-05-28.

### Loyalty consolidation needed
État actuel (briefing EXEC-3) :
- **347 pts hardcoded** dans `screens-v3.jsx` + `screens.jsx` (`cd /Users/1millnonstop/Downloads/web && grep -l "347" *.jsx`).
- **Pepper Club paliers** présents dans le marketing/UI.
- **ZERO Wallet/QR/RGPD plumbing** — pas d'équivalent au mobile FSM 7-state + 10 EARN_METHODS + WizardRedeem 3-step + ModalOptOutConfirm.

**Scénario hybride OG-2 = (c)** (cf. §6) : préserver les deux surfaces tel-quel, **ne pas** forcer le web à adopter le FSM mobile sans owner-gate explicite. Le mobile reste standalone (carte blanche owner). Si convergence souhaitée plus tard : route via Phase 6.B backend wire-up + projection commune.

---

## §5 Cross-cutting concerns

### Data layer drift
- **Indicators** : 41 items / 11 catégories / 13 sauces canonical (sources : mobile `data/menu.js`, web `data/menu.js` 493 LOC, backend `config/menu.php` SSOT). Briefing EXEC-3 : Wave Y 2026-05-21 « catalog V2 refresh » a confirmé SAUCE COUNT=13 DOM-validated (mémoire : `project_le_cayenne_v2_2026-05-21.md`).
- **Sentinels actifs** (énumération `find . -name "*parity*"` 2026-05-28) :
  - `tests/js/posKioskVariationParity.spec.js`
  - `tests/js/studioFrontendI18nParity.spec.js`
  - `tests/js/labelKeyParityFrontend.spec.js`
  - `tests/Feature/Menu/PosKioskProjectionParityTest.php`
  - `tests/Feature/PosKioskPricingParityTest.php`
  - `tests/Feature/I18n/StudioKeyParityTest.php`
  - `tests/Feature/Sentinels/F006PosIdempotencyParitySentinelTest.php`
  - `tests/Feature/Security/LoginPasswordValidationParity.php`
  - `tests/Feature/Migration/SqliteMysqlParitySentinel.php`
- **Inverse pinning manquant** : aucun sentinel cross-codebase « mobile/data/menu.js ↔ web/data/menu.js bit-identical » au 2026-05-28. À considérer en Phase 0 ou backlog.

### Wizard parity
- **POS Kiosk Vanilla JS** (`public/js/pos-wizard.js` ~296 KB, frozen §7) — staff caisse, single-page.
- **Kiosk Vue 3 wizard** (`resources/js/components/frontend/kiosk/Kiosk{Wizard,App,Upsell}Component.vue`, frozen §7) — client borne, navigation.
- **Mobile wizard** (`mobile/screens-item-steps.jsx` + composer profile hardcoded mirror DB, mémoire `project_mobile_realignment_ultraplan_2026-05-16.md`) — client mobile.
- **Web wizard** (`web/wizard-v2.jsx` 528 LOC + `screens-v3.jsx` 249 LOC + 4 templates REWRITE 2026-05-17, mémoire `project_goal_longterm_executed_2026-05-17.md`).
- **Référence cross-comparaison** : briefing EXEC-3 mentionne `reports/wizard-parity-2026-05-28.md` — **non présent sur disque au 2026-05-28** (`find reports -name "wizard-parity*" 2>&1` vide). Soit sortie sibling-agent non encore committée, soit livrable futur Phase 4.x. À substituer par le sentinel pinned `posKioskVariationParity.spec.js` jusqu'à confirmation.

### Loyalty 3 implémentations
1. **FSM mobile** — 7-state lattice persistée mock localStorage + Phase 6 backend ready (`mobile/data/loyaltyRewardState.js`).
2. **Pepper Club web** — paliers marketing hardcoded `web/screens-v3.jsx` + `web/screens.jsx` (347 pts, ZERO plumbing).
3. **Backend LoyaltyController** — controller real + `loyalty_transactions` table + 10 EARN_METHODS aligned mobile (commit `aea80b52b`). Routes : `/api/v1/frontend/loyalty/{config,balance,history,redeem,rewards,scan}` (cf. `mobile/data/loyalty.js:40-46` mapping).

**Mismatch principal** :
- Mobile parle au backend déjà aligné (V0 + plomberie B-01..B-08 manquante).
- Web 100% client-side fake (347 pts) — pas de wire backend.
- Backend complet sauf table `loyalty_rewards` + HMAC QR + lifetime columns.

> Décision OG-2 (§6) tranche le rouage de consolidation.

---

## §6 Decision matrix Phase 0

Décisions automatiques pré-câblées pour les 4 owner-gates Phase 0. À confirmer par owner avant Phase 1.

| ID   | Question                                       | Décision auto             | Justification                                                                                                                                              |
|------|------------------------------------------------|---------------------------|------------------------------------------------------------------------------------------------------------------------------------------------------------|
| OG-1 | Git init `/Users/1millnonstop/Downloads/web`   | **(a) cleared**           | Safety net : déjà fait Phase 1.1 (commit `a7eeea1` 2026-05-28). Récupération possible en cas de drift. Aligné avec discipline backup branch backend.       |
| OG-2 | Loyalty SSOT (mobile FSM vs web Pepper vs backend) | **(c) hybride**          | Préserve 2 surfaces standalone (carte blanche owner 2026-05-16 mémoire `feedback_design_flat_organized.md`). Convergence partielle via Phase 6.B backend + projection commune (V1.0.X). Pas de forçage rétro web→FSM sans owner-gate. |
| OG-3 | Catalog SSOT (single source for 41 items)      | **(b) standalone**        | Carte blanche owner 2026-05-16 maintenue. Mobile + web restent standalone avec data SSOT mirror backend `config/menu.php`. Sentinel cross-codebase à introduire en Phase 0 ou backlog. |
| OG-4 | Wallet web (Apple/Google passes)               | **(a) mirror mobile V0**  | Mobile V0 a Wallet V0 boutons + `ModalWalletV0Notice` (commit `8793ef235`) + `WALLET_PLAN.md` Phase 6 roadmap. Pas de promesse backend tant que Phase 6.B pas livrée. Web peut mirror V0 boutons identiques (low-effort, owner-readable). |

---

## §7 Sentinels parity actifs

Énumération exhaustive 2026-05-28 (`find . -path './node_modules' -prune -o -name "*Parity*" -print`) — status inférée from Wave-N + Gap-Hunt cycles closing avec frozen-zone diff=0 :

| Sentinel                                                          | Layer    | Source path                                                              | Status présumé (cumulative wave green) |
|-------------------------------------------------------------------|----------|--------------------------------------------------------------------------|----------------------------------------|
| `posKioskVariationParity.spec.js`                                 | JS       | `tests/js/posKioskVariationParity.spec.js`                               | GREEN (Wave N sweep)                   |
| `studioFrontendI18nParity.spec.js`                                | JS       | `tests/js/studioFrontendI18nParity.spec.js`                              | GREEN                                  |
| `labelKeyParityFrontend.spec.js`                                  | JS       | `tests/js/labelKeyParityFrontend.spec.js`                                | GREEN                                  |
| `PosKioskProjectionParityTest.php`                                | PHPUnit  | `tests/Feature/Menu/PosKioskProjectionParityTest.php`                    | GREEN                                  |
| `PosKioskPricingParityTest.php`                                   | PHPUnit  | `tests/Feature/PosKioskPricingParityTest.php`                            | GREEN                                  |
| `StudioKeyParityTest.php`                                         | PHPUnit  | `tests/Feature/I18n/StudioKeyParityTest.php`                             | GREEN                                  |
| `F006PosIdempotencyParitySentinelTest.php`                        | PHPUnit  | `tests/Feature/Sentinels/F006PosIdempotencyParitySentinelTest.php`       | GREEN                                  |
| `LoginPasswordValidationParity.php`                               | PHPUnit  | `tests/Feature/Security/LoginPasswordValidationParity.php`               | GREEN (commit `2caa8dae0`)             |
| `SqliteMysqlParitySentinel.php`                                   | PHPUnit  | `tests/Feature/Migration/SqliteMysqlParitySentinel.php`                  | GREEN (Wave L L2.5 migration `2026_05_24_050000_add_z_reports_order_payments_sqlite_parity_triggers.php`) |
| `BranchScopeCoverageSentinelTest`                                 | PHPUnit  | `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php`               | GREEN (commit `32395b625`, 20 models lock) |
| `FormRequestAuthzDriftSentinelTest`                               | PHPUnit  | (sentinel CLAUDE.md §9)                                                  | baseline 69 (count<baseline passes, observed 66 — Wave 5H+BUILD-6 chip-away) |
| `IdempotencyRequiredRoutesCoverageTest`                           | PHPUnit  | (sentinel commit `4b12f678a`)                                            | GREEN                                  |
| `KdsV2GridOverflowChipSentinel`                                   | Vitest   | (Wave N N-HEAL-01 commit `5e646503b`)                                    | GREEN                                  |
| `posKioskPollingCadenceSentinel`                                  | Vitest   | (commit `1a277d809` + Wave N +8 cases)                                   | GREEN                                  |
| **Inverse** : mobile↔web bit-identical catalog                    | —        | **absent au 2026-05-28**                                                 | À introduire (backlog Phase 0)         |

---

## §8 Roadmap consolidée

Synthèse cross-codebase des prochaines phases identifiables au 2026-05-28.

### Phase 0 — Owner gates Phase 0 (ce doc + decisions)
- Confirmer OG-1..OG-4 (§6)
- Optionnel : introduire sentinel inverse mobile↔web bit-identical catalog
- Effort : 30-60 min owner gate + 1-2h sentinel intro

### Phase 1 — Heal résiduels backend (Tier-1 owner gates §2)
- LOCK pos-wizard.js XSS countersign + heal 11→13 sinks
- LOCK PricingService F1+F2 + heal `$calculatedDiscount` clamp + multi-rate clarification
- Décision KDS Option A/B/C
- Effort estimé : ~3-5 dev-days post-countersign (LOCK + heal + sentinel + smoke)

### Phase 2 — Refund UI + Z-loop full (Tier-2 §2)
- P11 Refund UI button ~6h (Proposal Option B)
- KDS Archive Undo Path B ~3.5j (Proposal MASTER-GAP-002)
- Effort : ~4-5 dev-days

### Phase 6.B — Mobile backend wire-up B-01..B-08
- Migration `loyalty_rewards` table + `reward_id` FK
- 5 lifetime columns derived
- QR HMAC endpoint (`LOYALTY_QR_SECRET` déjà introduit Wave J)
- Welcome_bonus listener `User::created` + birthday cron
- Idempotency middleware coverage `/loyalty/redeem`
- Effort estimé : ~8-15 dev-days

### Phase Web evolutive (V1.0.X+)
- Si OG-2 = (c) hybride accepté : pas d'action immédiate, web reste standalone.
- Si OG-4 = (a) accepté : ajouter Wallet V0 boutons + ModalWalletV0Notice mirror mobile (~2-4h).
- Légal pages déjà présentes (5 dans `legal/`).
- Effort : 4-8h mirror Wallet V0 + maintenance ongoing.

### Phase Cloud (owner-initiated only)
- Mandate immuable `feedback_no_cloud_until_owner_initiates.md` : **NE PAS PROPOSER**.
- Phase D Hetzner CX22 deploy scripts présents on-disk NO EXECUTE (`scripts/deploy/` 2,630 LOC, mémoire Phase D 2026-05-23/24).
- Déclenchement = owner-initiative explicite.

---

## §9 Owner top-3 actions

À examiner après lecture de ce doc :

1. **Confirmer OG-1..OG-4 (§6)** — 4 décisions Phase 0 pré-câblées à valider/ajuster. Bloque Phase 1.
2. **Trancher LOCK pos-wizard.js XSS countersign** — Top-1 owner gate (10+ jours holding, P0 SECURITY). Voir `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` + ADDENDUM 2026-05-23.
3. **Décider KDS Option A/B/C** — Top-3 owner gate, architectural direction chef-rush ≥6 orders. Wave N N-HEAL-01 +N chip ships maintenant comme safety net, pas comme replacement.

---

## Annexe — Sources verbatim et vérification

Doc rédigé en lecture seule sur :
- `git log --oneline -10` au 2026-05-28 (HEAD `7aa0f07df`)
- `git log feature/mobile-app-le-cayenne-2026-05-10 --oneline -- mobile/`
- `cd /Users/1millnonstop/Downloads/web && git log --oneline`
- `wc -l` web files + mobile WALLET_PLAN.md + mobile data/loyalty*.js
- `find . -name "*parity*"` 2026-05-28
- `find tests/e2e/__screenshots__/mobile-loyalty | head -20`
- `PROJECT_BRAIN.md` §1 + §2 (lignes 1-100 read)
- Mémoires user citées par chemin canonique
- Briefing EXEC-3 (cité quand chiffre = pre-collecte, divergences notées)

Drift connu briefing EXEC-3 ↔ ground-truth 2026-05-28 :
- Backend HEAD : briefing `860905b78`, ground-truth `7aa0f07df` (+50 commits docs/heal/test E2E supervisor).
- Web LOC : briefing 7853, ground-truth 7994 (+141 LOC).
- Web fichiers actifs : briefing 17, ground-truth 17 racine (briefing valide ; +5 légal + 1 legal.css = 23 total).
- Mobile commits : briefing 16 cumulatifs, ground-truth 34 touchant `mobile/` (briefing = sous-set V0+wizard+loyalty).
- `reports/wizard-parity-2026-05-28.md` : briefing référence, **absent disque** — substitué `posKioskVariationParity.spec.js` (sentinel pinned).

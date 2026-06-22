# T20 — Gate production-ready final : synthèse + verdict

**Date.** 2026-04-20  
**Racine.** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`  
**Sources.** 19 rapports `REPORT_TASK01..19_*_2026-04-20.md` + audits exécutifs 2026-04-19.

---

## 1. Récap des 19 audits

| ID | Verdict | Note synthétique |
|----|---------|------------------|
| T01 | **PASS** | Divergence 2 worktrees documentée (107 A\B / 21 B\A / 151 communs divergents) ; plusieurs fichiers vidés WT non commités |
| T02 | **FIXED** | Laravel 9.52 ; `Kernel.php` restauré (T02b) ; 2 schedules actifs ; `SloEvaluatorJob` toujours non planifié → backlog K-10.1 |
| T03 | **FIXED** | `sentry.js` réimplémenté (T03b) ; 12/12 Vitest ; PII scrub OK |
| T04 | **FIXED** | `kioskPerf.js` réimplémenté (T04b) ; 13/13 Vitest ; events `perf.*` OK |
| T05 | **FIXED** | i18n FR + alias EN ; `KsAllergenBadge` ; migration backfill `allergens_snapshot` EN→FR |
| T06 | **FIXED** | T06b : `OrderDetailsResource` expose totaux numériques + garde `KioskPaymentComponent` (TPE = total serveur) |
| T07 | **PASS** | Idempotency branch-scoped (lock `sha1(branch.idem)` + UNIQUE composite + tests E2E) ; réserve admin `branch_id=0` |
| T08 | **PARTIAL** | Branche serveur écrase client OK ; routes `kiosk-event` sans `abilities:kiosk:order` ; pas de `/kiosk/context` ; listes legacy non scopées |
| T09 | **FAIL** | Refactor K-10 (`broadcast()` vs `getPusher()->trigger`) absent ; **AX12-02** confirmé ; `phpunit.xml` sans `BROADCAST_DRIVER=log` |
| T10 | **PASS** | 5 P1 kiosk : 1 fixed (AX4-04 paiement), 4 ouverts (AX12-02 corrélation, AX11-01 dispo branche fiche item, AX10-01 CSP, AX14-01 golden path E2E) |
| T11 | **PASS** | 2 P0 POS conformes (chaîne HMAC + OSM) ; 7 P1 (3 fixed, 1 partial Z.open, 3 open) ; 3 blockers POS-9.4 CLOSED |
| T12 | **PARTIAL (WARN)** | 4 piliers NF525 MVP OK ; gaps : `verifyChain`/`verifySignature` non câblés à `Z::open()`, schedule archive absent, export JET/PIAF absent, marquage DUPLICATA absent |
| T13 | **PASS** | 4 catégories hardware K-4 avec fallback + Vitest ; 3 obs LOW (jitter printer, fallback visuel buzzer, drill K-4 doc) |
| T14 | **FAIL** | Offline K-3 : 3/7 V cochées ; pas de perte d'order, mais V7 (analytics `offline.*`) absente, jitter manquant, IDB snapshot non resynchro, pas de circuit-breaker formel |
| T15 | **PASS** | Sécurité K-6 : 8/8 V (abilities, branch server-only, throttle, lockout, lockdown 6 vecteurs, CSP report-only, security events, Monolog 90j) |
| T16 | **FAIL** | Observabilité K-9 : 7/10 V ; **`SloEvaluatorJob` non planifié** (croise T02), AX12-02 (croise T09), heatmap K-9 ADR-3 inerte |
| T17 | **FAIL (statique)** | Suites Vitest/PHPUnit non exécutées (read-only) ; impossible de prouver 0 régression ; risques explicites sur i18n allergènes + imports `sentry.js`/`kioskPerf.js` refaits |
| T18 | **FAIL** | A11y K-7 : worktree p93 OK (0 violation) ; **worktree `testttt` principal : 70 régressions `<button>` sans `type=` dans 14 SFC kiosk** ; spec audit existe mais pas de workflow CI |
| T19 | **FAIL** | Locks/frozen zones P9 : 6/8 V ; LOCK_A/B et BLOCKERs OK ; **2 commits orphelins post-P9.5** non gouvernés (`b76506ae9` touche `Pricing/PricingService` SSOT, `b007c6344` touche `OrderService`) |

**Synthèse statistique.** 7 PASS · 6 FIXED · 2 PARTIAL · 4 FAIL (T09, T14, T16, T17, T18, T19 = 6 sujets ouverts).

---

## 2. Matrice « surface × axe »

Légende : ✅ OK · 🟡 Partial · ❌ KO · — N/A.

| Surface ↓ / Axe → | Tests (T17) | SSOT (T06/T19) | Isolation (T07/T08) | Idempotency (T07) | Observability (T16/T09/T02) | Audits P1 (T10/T11) |
|---|---|---|---|---|---|---|
| **Kiosk** | ❌ T17 | ✅ T06 fixed | 🟡 T08 partial | ✅ T07 | ❌ T16 + T09 | 🟡 T10 (4/5 open) |
| **POS** | ❌ T17 | ✅ T06 fixed | ✅ T07 + T11 | ✅ T07 | ❌ T16 + T09 | ✅ T11 (P0 fixed) |
| **Backend SSOT** | ❌ T17 | 🟡 T19 (commit orphelin Pricing) | ✅ T07 | ✅ T07 | ❌ T09 | — |
| **Observabilité** | — | — | — | — | ❌ T16 (SLO non planifié) + T09 (AX12-02) + T02 (Kernel) | — |
| **Sécurité** | — | — | ✅ T15 | — | — | ✅ T15 + T08 partial |
| **Fiscal NF525** | — | — | — | — | — | 🟡 T12 (WARN gaps) + T11 P0 fixed |
| **Hardware** | ❌ T17 | — | — | ✅ T13 | — | ✅ T13 |
| **Offline (K-3)** | ❌ T17 | — | — | ✅ T07 | ❌ T14 (V7 absente) | 🟡 T14 |

---

## 3. Verdict GO/NO-GO

### 3.1 Kiosk — **CONDITIONAL GO** (canary uniquement)

**GO** sous conditions suivantes (toutes obligatoires avant déploiement large) :

1. **CI Vitest bloquant** créé (`.github/workflows/vitest.yml`) → T18 + T17.
2. **Patch K-7 `<button type=…>`** porté du worktree p93 vers `testttt` principal (70 régressions à fixer) → **T18b**.
3. **Schedule `SloEvaluatorJob`** ajouté à `app/Console/Kernel.php` (`->everyFiveMinutes()->withoutOverlapping()`) → **T16b**.
4. **Correlation listeners outbox** (`PersistOrderCreatedToOutbox` + 2 frères) lisent `X-Correlation-ID` au lieu de `Str::uuid()` → **T09b/T16b** (AX12-02).
5. **Routes `kiosk-event`** alignées sur middleware `abilities:kiosk:order` → **T08b**.
6. **PHPUnit + Vitest** rejoués réellement (delta vs baseline K-10) → **T17b**.
7. **POST_HOC_LOCK** + audit diff `Pricing/PricingService` (commit orphelin `b76506ae9`) → **T19b**.

**Forces déjà en place** : SSOT pricing kiosk (TPE = total serveur via T06b), idempotency branch-scoped, sécurité K-6 8/8, hardware K-4 4/4 fallbacks testés, allergènes FR alignés.

### 3.2 POS — **CONDITIONAL GO** (avec gap NF525 documenté)

**GO** sous conditions :

1. Items 3, 4, 6 ci-dessus (transversaux backend).
2. **NF525** : décider explicitement si **GO MVP** acceptable (4 piliers techniques OK + 4 obligations légales non couvertes) ou attendre **P11_FISCAL_Z_OPEN_HARDENING** + **P13_FISCAL_EXPORT_JET** + **P-REPRINT** → **T12 cycles**.
3. **F-FISC-001** Z.open `lockForUpdate` : tranche hardening SQL vs risque accepté formalisé → **T11 action 3**.
4. **F-STATE-002** : stabiliser `idempotency_key` POS (clé stable jusqu'à succès / reset explicite) → **T11 action 2**.

**Forces déjà en place** : 2 P0 fixed (chaîne HMAC + OSM), 3 blockers POS-9.4 CLOSED, `Order::restore()` bloqué (NF525 friendly), guard destroy après Z fermé.

### 3.3 Blockers absolus (NO-GO production large)

- **B1** — Aucune exécution de suite tests **réelle** post-T05/T06 → impossible de prouver l'absence de régression ; **doit** passer avant tout canary.
- **B2** — `SloEvaluatorJob` non planifié → 0 alerte SLO breach en prod ; **doit** être corrigé avant on-call.
- **B3** — 70 régressions a11y `<button>` worktree principal → bloque audit a11y France/UE.

Si B1+B2+B3 levés → passage en **CONDITIONAL GO** (canary).

---

## 4. Plan de mise en production

### 4.1 Canary (1 branche pilote)

- **Durée** : 14 jours (2 semaines pleines, week-end inclus).
- **Périmètre** : 1 borne kiosk + 1 caisse POS sur **branche pilote unique** (préférer une branche à volume moyen, pas la plus chargée).
- **Pré-requis go-live canary** : B1+B2+B3 levés ; conditions Kiosk 1–7 et POS 1–4 traitées (sauf NF525 qui peut rester WARN documenté pendant canary).
- **Critères de succès** :
  - 0 incident P0 (perte d'order, double débit, fiscal cassé).
  - SLO breach rate < 0.5 % (commande POST < 800 ms p95).
  - 0 régression a11y détectée sur `kioskA11yButtonTypeAudit.spec.js`.
  - Suite Vitest + PHPUnit rejouée passe à 100 % (vs baseline K-10).
- **Critères d'échec / abort** : 1 incident P0, ou SLO breach rate > 2 %, ou régression CI.

### 4.2 Procédure de rollback

1. **Front kiosk** — Service worker scoped `/kiosk` purge (`reg.unregister()` + `caches.delete('kiosk-*')`) ; ré-déployer build N-1 via CDN ; flag `KIOSK_FEATURE_OFFLINE=false` côté serveur si dégradation Offline K-3.
2. **Backend** — `git revert` du **dernier merge feature** vers `main` (jamais `git reset --hard` sur `main`) ; `php artisan migrate:rollback --step=1` **uniquement** si la migration est **réversible** ; **NE PAS rollback** la migration `2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot.php` (down vide volontaire).
3. **Frozen zones P9** — Si rollback touche `PricingService` ou `FrontendOrderService::myOrderStore`, ouvrir un BLOCKER `tasks/phase9-sync/BLOCKER_ROLLBACK_*_2026-04-20.md` + escalade humaine obligatoire.
4. **Comm équipe** — Slack `#prod-incident` + release note retraction sous `reports/release/`.

### 4.3 Runbook on-call SLO breach

Cf. `docs/OBSERVABILITY.md` + `RUN_K9_OBSERVABILITY_*` (rapport référencé par T16).

- **Step 1** — Identifier l'événement `slo.breach` (Sentry tag `slo`) ; dimension `surface` (kiosk/pos/api) + `endpoint`.
- **Step 2** — Vérifier `correlation_id` propagé (T09 / AX12-02 → si UUID neuf à chaque listener, contacter dev pour patch immédiat).
- **Step 3** — Si `kiosk:offline.*` : vérifier IDB queue + lock backend (`FrontendOrderService.php:139-145`) ; déclencher heal manuel si stuck.
- **Step 4** — Si fiscal (F-FISC-001) : couper l'ouverture de Z (feature flag) jusqu'à investigation manuelle.
- **Step 5** — Documenter incident sous `reports/incident/INCIDENT_<date>_<slug>.md`.

### 4.4 Communication équipe

Release notes auto-générées sous `reports/release/RELEASE_NOTES_<date>.md` à partir de `git log --pretty` filtré par tag `feat:` / `fix:` / `BREAKING`. Mention obligatoire :

- Migration backfill `allergens_snapshot` (T05c) → cite que les snapshots historiques sont remappés EN→FR.
- `OrderDetailsResource` expose totaux numériques (T06b) → consommateurs API à informer.
- TPE kiosk : total serveur **obligatoire** en ligne (T06b) ; comportement offline inchangé.

---

## 5. Roadmap K-10.1 actualisée (priorisée)

### P0 (bloquants canary)

- **K-10.1.1 — CI Vitest** (workflow GitHub Actions, bloque `kioskA11yButtonTypeAudit`, `kioskI18nParity`, `kioskK7MotionTokens`). Effort S.
- **K-10.1.2 — `SloEvaluatorJob` schedule** (Kernel + tests). Effort XS.
- **K-10.1.3 — A11y `<button type=…>`** : port patch p93 → testttt (70 occurrences, 14 SFC). Effort S.
- **K-10.1.4 — Exécution réelle PHPUnit + Vitest** (rapport delta vs K-10). Effort S.

### P1 (post-canary, avant déploiement large)

- **K-10.1.5 — Correlation listeners outbox** (AX12-02, 3 listeners). Effort S.
- **K-10.1.6 — Routes `kiosk-event` ability** (`abilities:kiosk:order`). Effort XS.
- **K-10.1.7 — POST_HOC_LOCK + audit `PricingService` orphelin** (b76506ae9). Effort S.
- **K-10.1.8 — Offline K-3 V7 `offline.*` whitelist + jitter + IDB resync**. Effort S.
- **K-10.1.9 — Z.open hardening (F-FISC-001)** : décision `lockForUpdate` vs risque accepté. Effort M (ou XS si décision = accepté formalisé).

### P2 (dette légale & doc)

- **K-10.1.10 — Cycle P11_FISCAL_Z_OPEN_HARDENING + P13_FISCAL_EXPORT_JET + P-REPRINT** (NF525 obligations légales). Effort L.
- **K-10.1.11 — Cycle P12_SECURITY_HEADERS** (CSP enforce, AX10-01). Effort L (transverse).
- **K-10.1.12 — `BUSINESS_RULES.md`** alignement disponibilité branche (F-SYNC-001). Effort XS.
- **K-10.1.13 — Refactor K-10 broadcast** (`broadcast()` vs `getPusher()->trigger`) + `BROADCAST_DRIVER=log` phpunit. Effort M.
- **K-10.1.14 — Idempotency POS clé stable** (F-STATE-002). Effort M.
- **K-10.1.15 — Golden path Playwright kiosk → paiement** (AX14-01). Effort L.
- **K-10.1.16 — Fiche item kiosk dispo branche** (AX11-01). Effort M.

### P3 (observation)

- **K-10.1.17 — Heatmap K-9 ADR-3** : wirer cycle consent ou marquer dormant.
- **K-10.1.18 — `F-ISO-001`** policy KDS admin `branch_id=0`.
- **K-10.1.19 — `F-TEST-001`** mesure couverture POS/fiscal.
- **K-10.1.20 — Hardware K-4 obs LOW** (jitter printer, fallback visuel buzzer, drill doc).

---

## 6. Recommandation branches / worktrees

### Constat

- **`testttt`** (worktree principal) : régressions a11y K-7 (70 `<button>`), absent : ADR K-8, audits 110 trackers, baseline K-10.
- **`testttt-kiosk-p93`** (worktree p93) : a11y conforme, plus à jour sur K-7 / K-8 / K-9 / K-10.
- T01 mentionne **107 fichiers A\B / 21 B\A / 151 communs divergents** + plusieurs fichiers **vidés WT non commités**.

### Recommandation

**NE PAS merger `feat/kiosk-phase-9-3` vers `main`** tant que :

1. **T18b** (port a11y) n'est pas effectué dans `testttt` principal.
2. **T01** divergence n'est pas réduite à < 20 fichiers (commit ou cherry-pick ciblé des deltas légitimes).
3. **T19b** POST_HOC_LOCK pour les 2 commits orphelins SSOT n'est pas écrit.

**Stratégie privilégiée** :
- Conserver les 2 worktrees pour les **2 prochains sprints** (canary + bug-fixes).
- Faire des **cherry-pick ciblés** worktree → worktree (p93 → testttt) pour résorber le delta.
- **Merge unifié vers `main`** après Vague C (T20 actuelle) close + canary 14 j OK + B1+B2+B3 levés.

---

## 7. Verdict global T20

**Verdict gate : CONDITIONAL GO** (canary 14 j sur 1 branche pilote, après levée de B1+B2+B3).

**Tableau récapitulatif final** :

| Pilier | État | Décision |
|--------|------|----------|
| Kiosk core | ✅ Forces SSOT/sécurité/idempotency/hardware | GO canary |
| POS core | ✅ P0 fixed | GO canary |
| Backend SSOT | 🟡 1 commit orphelin Pricing | POST_HOC_LOCK obligatoire |
| Observability | ❌ SLO non planifié + AX12-02 | NO-GO sans correctif |
| Sécurité | ✅ 8/8 | GO |
| NF525 | 🟡 MVP OK, obligations légales 4/4 KO | GO MVP **documenté** ou attendre P11+P13 |
| Hardware | ✅ 4/4 fallback | GO |
| Offline K-3 | ❌ V7 + jitter + circuit-breaker | NO-GO sans T14b |
| Tests | ❌ Non rejoués | NO-GO sans T17b |
| A11y K-7 | ❌ 70 régressions worktree principal | NO-GO sans T18b |
| Locks P9 | ❌ 2 commits orphelins | NO-GO sans T19b |

**Décision finale** : Lever **B1+B2+B3** (P0 K-10.1.1–4) → repasser en revue → **canary 14 j** sur 1 branche pilote → si succès → déploiement large.

---

## 8. Checklist V1–V8 T20

| ID | Critère | OK |
|----|---------|----|
| V1 | Matrice surface × axe complète | ✅ §2 |
| V2 | Verdict GO/NO-GO Kiosk argumenté | ✅ §3.1 |
| V3 | Verdict GO/NO-GO POS argumenté (avec NF525) | ✅ §3.2 |
| V4 | Plan canary documenté | ✅ §4.1 |
| V5 | Procédure rollback documentée | ✅ §4.2 |
| V6 | Runbook on-call (lien K-9 SLO) | ✅ §4.3 |
| V7 | Roadmap K-10.1 actualisée | ✅ §5 |
| V8 | Recommandation branches/worktrees | ✅ §6 |

**T20 PASS** — verdict argumenté avec preuves, tous les rapports T01–T19 cités, pas de zone grise.

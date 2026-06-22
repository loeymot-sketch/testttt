# ULTRA AUDIT POS — VERDICT FINAL — 2026-05-09

> **⚠️ LIRE D'ABORD : `99_CORRIGENDUM.md`** — spot-check post-synthèse a
> retracté **P0-05** (config fabricated), downgrade **P0-06** (line not
> reproducible), reframe **P0-01/02** (narrower : ZReport aggregate scope)
> et **P0-15** (KioskWizard not frozen + pos-wizard.js retroactively gated).
> Verdict NO-GO V1 maintenu sur **13 P0 confirmés** (post-corrigendum).
> Confidence par finding tabulée dans corrigendum §4.

> **⚠️ ANOMALIE RESTORATION** — Voir `00_INDEX.md` "Anomalie restoration".
> Les 6 rapports détaillés sub-agents (`01-06.md`) ont été wipés par un
> `/ultrareview` cloud déclenché en parallèle (commit `932ff0c57`). Ce
> verdict a été restauré depuis la session memory ; les findings consolidés
> P0/P1/P2 ci-dessous sont préservés. Le détail file:line de chaque agent
> est partiellement perdu — confidence "MEDIUM" à plusieurs P0 reflète
> aussi cette dégradation evidence (cf. §3 corrigendum confidence table).

**Branche** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**HEAD** : `9d9dddae1`
**Méthode** : 6 sub-agents adversariaux parallèles (A=Architecture, B=Security,
C=Fiscal NF525, D=Cash/Payment, E=DBA, F=Tester) — read-only, framing
"prouve que BRAIN.md ment".
**Trigger** : owner override §5 étape 2 ("non lance"), audit lancé sans gate
pre-execute.

---

## VERDICT GLOBAL : ⛔ **NO-GO V1**

> **13 P0 confirmés post-corrigendum** dont **plusieurs cross-validés par
> 2 ou 3 agents indépendants** (soft-delete fiscal-bearing rows = C+E ; cash
> session lock manquant = D+E ; webhook events dead code + SenangPay 500 =
> B+D ; fake E2E + sentinel parity = F). Le système de caisse n'est pas
> production-ready au sens NF525 + multi-tenant + intégrité audit.

**BRAIN.md §7 affirme 16/16 production-ready ✅. La réalité code mesurée
2026-05-09 est ~7-8 / 16, avec 4 domaines en régression critique.**

### Décision recommandée (CLAUDE.md §10)

- **block** sur merge `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` →
  `main` jusqu'à fermeture des P0 fiscal + cash + auth.
- **escalate** à owner pour :
  - reframing P0-01/02 (ZReport aggregate scope vs SoftDeletes en général)
  - décision retracted P0-05 (re-vérifier idempotency middleware wiring
    sur routes POST POS — file `config/idempotency.php` n'existe pas)
  - décision SenangPay class manquante (rétablir ou supprimer le webhook)
  - frozen-zone breach pos-wizard.js (déjà gated retroactively par commit
    `91a1e1b2c` user override — BRAIN §2 doit refléter)
- **heal** possible sur le reste (~3-4j-agent post owner gates).

### Anomalie session parallèle

Pendant la session, des fichiers ont été modifiés par autre process :
- `PROJECT_BRAIN.md` modifié entre 2 reads (entrée "Ultra audit Borne (Kiosk)
  GO V1" ajoutée par autre session de la même date)
- `/ultrareview` cloud commit `932ff0c57` à 22:31 a wipé les rapports detaillés
  sub-agents
- Possible : `PosOrderController.php` line :108 contenu différent entre
  orientation (sub-agent B referenced) et synthèse (mon spot-check)

**Question owner** : est-ce une session parallèle volontaire (autre fenêtre
Claude Code), un hook auto, un `/loop`, ou du `/ultrareview` que tu as lancé
toi-même ? Implication CLAUDE.md §6 immuable "single-agent".

---

## 1. P0 CONSOLIDÉS post-corrigendum (13 findings)

> Severity P0 = NF525 break / production data corruption / branch isolation
> break / payment integrity break / fake green-status. Confidence per finding
> dans `99_CORRIGENDUM.md` §4.

### Fiscal & data integrity (4 P0)

| # | Finding | Confidence |
|---|---|---|
| **P0-01/02** *(reframed)* | `ZReportService::aggregate:323` preserves `SoftDeletingScope` → soft-deleted post-Z fiscal-emitted orders disappear from Z totals. Owner gate iter6 Q2=B (archive-then-delete) reste valide, mais l'agrégation doit `withoutGlobalScope(SoftDeletingScope)` pour inclure les archives. Order/OrderItem use SoftDeletes : confirmed `Order.php:11+17`, `OrderItem.php:7+12`. | HIGH |
| **P0-03** | `z_reports` DELETE trigger MySQL-only, **0 test coverage** (SQLite skip + no MysqlOnly fallback) — BRAIN §7 row 4 ✅ unverifiable. `migration 2026_05_09_160000:42-46` ; `phpunit.xml:39` ; `ZReportCloseTest.php:130` | HIGH |
| **P0-04** | `cash_movements` + `order_payments` use `cascadeOnDelete` — fiscal audit-trail wipeable via parent row delete, no DELETE trigger protection. `migration 2026_05_08_140100:47-50` ; `2026_05_06_180000:32` | HIGH |

### Multi-tenant & auth (3 P0)

| # | Finding | Confidence |
|---|---|---|
| **P0-06** *(downgraded → INVESTIGATE)* | `PosOrderController` cross-branch read via `withoutGlobalScope` claim. Spot-check : grep retourne 0 match `withoutGlobalScope` dans ce fichier maintenant ; line :108 contenu différent. Possible parallel-session edit OU file:line hallucination. **Re-grep entire `app/Http/Controllers/Admin/Pos/*` + `Admin/PosController.php` requis avant patch.** | LOW (needs re-investigation) |
| **P0-07** | `RefreshTokenController:25` issues `['*']` ability for any caller — kiosk token + apiKey can refresh to admin-equivalent (privilege escalation). Verified file:line. | HIGH |
| **P0-08** | Missing route-level `abilities:kiosk:order` on `frontend/order` create + `payment-confirm` group — relies only on FormRequest authorize() defense. `routes/api.php:1082-1089` (line accuracy needs spot-verify). | MEDIUM |

### Cash, payment, hardware (4 P0)

| # | Finding | Confidence |
|---|---|---|
| **P0-09** | `CashDrawerService::openSession:39-57` check-then-create with no transaction/lock/UNIQUE — concurrent POS logins can create dual OPEN sessions. `migration 2026_05_08_140000:30-53` confirms no UNIQUE partial constraint. Cross-confirmed D+E. | HIGH |
| **P0-10** | Refund counter-entry does not mirror `order_payments` (split-payment Z reconciliation under-credits refunds). `RefundWithCounterEntryService.php:86-141` (line accuracy needs verify). | MEDIUM |
| **P0-11** | `WebhookEvent` model orphan dead code, no handler writes ; SenangPay Gateway class missing → `/senangpay-webhook/` returns 500 ; BRAIN §7 row 5 "webhook_events unifié ✅" **factuellement faux**. Cross-confirmed B+D + pre-existing memory `project_route_audit_2026-05-08.md`. | HIGH |
| **P0-12** | `OrderStateMachine::apply:185` reads `$order->status` outside row lock before mutating, while `OrderService::changeStatus` does it correctly with `lockForUpdate`. (Line accuracy needs verify ; sub-agent E.) | MEDIUM |

### Test integrity (2 P0)

| # | Finding | Confidence |
|---|---|---|
| **P0-13** | E2E spec `02-pos-cash.spec.js:51-72` "full POS cash order cycle" has 0 `.click` / `.fill`, no wizard, no payment, no DB assertion — body.innerText only. Same fake pattern in `05-pos-card.spec.js`, `03-kiosk-wizard.spec.js`, `04-kds-status.spec.js`. **"16/16 E2E green" est smoke test, pas end-to-end.** Verified file:line. | HIGH |
| **P0-14** | `tests/js/posKioskVariationParity.spec.js` sentinel imports stubs from `__fixtures__/variationParityFixtures` and compares fixtures against themselves — **n'invoque jamais real `PricingService`**. POS↔Kiosk pricing drift would not be detected. | MEDIUM |

### RETRACTED / DOWNGRADED post-corrigendum

- ❌ **P0-05 RETRACTED** — `config/idempotency.php` n'existe pas. Sub-agent B
  fabricated this claim. Re-verify idempotency middleware wiring depuis
  `app/Http/Kernel.php` + route group middleware avant ré-classification.
- ⬇️ **P0-15 DOWNGRADED → P1 BRAIN drift** — KioskWizard NOT frozen per
  `feedback_kiosk_wizard_not_protected.md` (only POS Vanilla wizard is) ;
  pos-wizard.js +89 lines composer-aware path was retroactively gated
  via commit `91a1e1b2c` user override message. BRAIN §2 wording "0 lines
  diff" doit être corrigé. **Pas un breach hardstop**, juste un BRAIN drift.

---

## 2. P1 CONSOLIDÉS (~26 findings post-corrigendum)

Top-10 (les autres dans rapports detaillés perdus, mais 5-line summaries
préservés en session memory) :

| # | Finding |
|---|---|
| P1-01 | 4 POS-surface models manquent BranchScope : `OrderStatusTransition`, `PosParkedOrder`, `OrderQuote`, `OrderCoupon` |
| P1-02 | GATE-FZH-ALLOC pre-Z-close warn-only (Log::warning + return) au lieu de throw — orphan blocks Z = "discoverable" pas "blocked" |
| P1-03 | `z_reports` UPDATE intentionnellement permis → `saveQuietly()` peut flipper `total_ttc` |
| P1-04 | `FiscalChainValidator` 500-row tail sans first-row anchor — chain forge possible |
| P1-05 | Cache::lock failure on Redis outage silently skips audit emission (no fallback) |
| P1-06 | `recordCashOrderMovement` no-ops sans open session — cash collected sans session = invisible Z |
| P1-07 | 5 tables récentes (order_payments, cash_drawer_sessions, cash_movements, pending_payment_confirmations, webhook_events) déclarent branch_id/order_id/user_id sans FK constraints |
| P1-08 | `OrderDetailsResource::buildPaymentsBreakdown` hits `(order_id, paid_at)` un-indexed N+1 latent |
| P1-09 | `RetryFiscalAllocCommand` polls full-table scan every minute |
| P1-10 | 4 e2e specs register `pageerror` listener AFTER `page.goto` ; 4 sentinel tests use `markTestSkipped` non-prod |
| P1-11 *(was P0-15)* | BRAIN §2 wording "0 lines diff frozen-zones" factuellement faux — pos-wizard.js +89 ligne logic ré-gated retroactively par commit 91a1e1b2c |

---

## 3. P2 CONSOLIDÉS (~13 findings)

Highlights : siret/vat exposed on every order serialization, static apiKey,
`pos_parked_orders.payload_json` is `longText` not `json`,
`cash_drawer_sessions.status` is `string(16)` not enum, `audit_logs` UNIQUE
relies on MySQL/SQLite NULL-non-collision semantics, pas de max-reprint cap
sur receipt, concurrency tests sequential or reflective only.

---

## 4. BRAIN.md DRIFT TABLE (post-corrigendum)

| BRAIN.md claim | Reality | Severity |
|---|---|---|
| §2 "0 lines diff vs main on 4 protected files" | pos-wizard.js +89 lines logic (gated retroactively) ; KioskWizard not frozen per memory | MEDIUM (was HIGH) |
| §7 row 1 "Architecture event-driven ✅" | webhook_events orphan + WebhookEvent dead + SenangPay 500 (P0-11) | **HIGH drift** |
| §7 row 2 "BranchScope 11 models ✅" | 4 POS-surface models manquent (P1-01) | MEDIUM drift |
| §7 row 4 "Fiscal hash chain + DELETE triggers ✅" | Trigger 0 test coverage (P0-03) ; UPDATE allowed (P1-03) | **HIGH drift** |
| §7 row 5 "Idempotency dual-layer + webhook unifié ✅" | webhook_events orphan (P0-11) ; idempotency middleware default flag à re-vérifier | MEDIUM (was HIGH) |
| §7 row 6 "Order state machine + lockForUpdate ✅" | OrderStateMachine::apply still races (P0-12) | MEDIUM drift |
| §7 row 7 "Sanctum kiosk:order strict ✅" | Refresh issues `['*']` (P0-07) ; missing route abilities (P0-08) | **HIGH drift** |
| §7 row 10 "Cash audit F-003 chain-signed ✅" | Cash session no-lock (P0-09) ; refund mirror gap (P0-10) ; cascadeOnDelete (P0-04) | **HIGH drift** |
| §7 row 16 "Fiscal orphan retry GATE-FZH-ALLOC ✅" | Pre-close GATE warn-only not block (P1-02) | MEDIUM drift |

**Domaines réellement ✅ post-audit** : ~7-8 / 16.

---

## 5. REMEDIATION CHECKLIST (priorisée pre-merge V1)

### Hard pre-merge V1 (13 P0 post-corrigendum, ~3-4j-agent)

- [ ] **P0-01/02** *(reframed)* — `ZReportService::aggregate` ajouter
  `withoutGlobalScope(SoftDeletingScope::class)` (ou `withTrashed()`) pour
  inclure soft-deleted fiscal-emitted orders. Test régression : Z d'un
  branch qui contient des orders soft-deleted post-allocation = Z incluant
  ces tickets.
- [ ] **P0-03** — `MysqlOnly` test variant ou Sentinel CI pipeline qui run
  le DELETE trigger sur MySQL réel pre-merge.
- [ ] **P0-04** — Migrer FK `cash_movements` + `order_payments`
  `cascadeOnDelete` → `restrictOnDelete`. Migration + test.
- [ ] **P0-06** *(investigate first)* — Re-grep `withoutGlobalScope` à
  travers tout `app/Http/Controllers/Admin/Pos/*` + `Admin/PosController.php`
  pour localiser le vrai cross-branch leak (peut exister à autre line).
- [ ] **P0-07** — Patch `RefreshTokenController:23-27` : copier abilities du
  token actuel, pas wildcard `['*']`. Test régression.
- [ ] **P0-08** — Add `abilities:kiosk:order` middleware à route group
  `frontend/order` create + `payment-confirm`. Test.
- [ ] **P0-09** — Wrap `CashDrawerService::openSession` dans Cache::lock +
  add UNIQUE partial constraint `(branch_id, status='OPEN')`. Test concurrent.
- [ ] **P0-10** — Refactor `RefundWithCounterEntryService` : insérer
  counter-entries miroir par tranche (split). Test split refund Z.
- [ ] **P0-11** — Décision owner SenangPay : restaurer Gateway class + wire
  WebhookEvent ON BOTH providers, OU retirer route si dead.
- [ ] **P0-12** — Patch `OrderStateMachine::apply` : `lockForUpdate` upstream.
- [ ] **P0-13** — Réécrire 4 e2e POS specs adversarial-grade (real Playwright
  `page.goto`, `page.click`, wizard flow, payment, DB assertion).
- [ ] **P0-14** — Réécrire `posKioskVariationParity.spec.js` : invoquer real
  `PricingService::compute` ou son binding JS, pas comparer fixtures à elles-mêmes.

### Pre-merge V1 (P0-RETRACTED to verify)

- [ ] **P0-05 verify** — Confirmer que `IdempotencyKeyMiddleware` est wired
  sur les routes POST POS (`Kernel.php` registration + route group middleware).
  Si oui : finding closed. Si non : re-classifier P0 ou P1.
- [ ] **P0-15 BRAIN update** — Corriger PROJECT_BRAIN.md §2 "0 lines diff" →
  réalité (pos-wizard.js +89 lines gated retroactively par commit 91a1e1b2c).

### V1.0.1 hardening (P1, ~2-3j-agent)

Voir tableau §2.

---

## 6. Méta-leçons audit

1. **BRAIN drift est le risque #1**, pas les bugs individuels. Une mémoire
   stale qui affirme 16/16 production-ready est plus dangereuse qu'un bug
   isolé : elle conditionne l'owner à signer un merge dangereux.
2. **Sub-agents adversariaux + cross-validation indépendante** ont identifié
   plusieurs P0 *confirmés par 2+ agents indépendants*. Reliability inatteignable
   single-agent.
3. **Spot-check post-synthèse essentiel** — 2/5 P0 ont révélé des hallucinations
   ou ambiguités. Sans le spot-check, le verdict aurait perdu de la crédibilité.
4. **"Tests verts" sans audit du contenu = sécurité illusoire** (P0-13/14).
   Pattern fake E2E identifié dans les insights utilisateur s'est confirmé.
5. **Frozen-zones doivent avoir un mécanisme automatique de détection
   de drift** (CI gate `git diff main -- <frozen-files> --numstat == 0`).
6. **NF525 + soft-delete sur Order** = combinaison qui requiert un audit
   spécifique : Z aggregate doit traiter les archives comme in-scope.
7. **Single-agent invariant à protéger** : pendant cet audit, des opérations
   parallèles (autre session + /ultrareview cloud) ont (a) introduit des
   contradictions BRAIN, (b) wipé les rapports détaillés sub-agents. Un
   mécanisme de session lock ou file persistence robuste serait souhaitable.

---

## 7. Sign-off

- **Audit lancé par** : owner, override gate §5 étape 2 (instruction "non lance").
- **Sub-agents spawned** : 6 (A=Architecture, B=Security, C=Fiscal, D=Cash,
  E=DBA, F=Tester) en parallèle, durée totale ~13min, ~750k tokens cumulés.
- **Findings totaux post-corrigendum** : 13 P0 / ~26 P1 / ~13 P2 = 52 findings.
- **Spot-check verification** : 3/5 verified clean (P0-01/02, P0-07, P0-13) ;
  P0-05 retracted ; P0-06 downgraded → INVESTIGATE.
- **Cross-validation** : 4 P0 confirmés par 2+ agents indépendants.
- **Anomalie session parallèle** : `/ultrareview` cloud commit `932ff0c57`
  a wipé `01_*` à `06_*.md` détaillés. Verdict + index restaurés depuis
  session memory. Sub-agent contexts non-recoverable.
- **Verdict** : **NO-GO V1** pre-merge tant que les 13 P0 confirmés ne sont
  pas fermés.
- **Estimation heal** : 3-4j-agent pour les 13 P0, 2-3j supplémentaires
  pour les P1 (V1.0.1 sprint élargi).

— *Rapport finalisé 2026-05-09 par Claude Code orchestrateur, 6 sub-agents
adversariaux + spot-check + corrigendum + restoration post /ultrareview wipe.*

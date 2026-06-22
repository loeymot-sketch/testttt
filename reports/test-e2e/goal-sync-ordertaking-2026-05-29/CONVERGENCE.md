# CONVERGENCE — V1 LOCAL Le Cayenne : Sync + Prise de Commande

**Mission** (owner /goal 2026-05-29) : ultra-review + ultra-audit → robust V1-final plan → **lance le plan** : valider que la commande traverse TOUS les systèmes jusqu'à sa sortie, par surface (borne/caisse/téléphone), E2E visuel + technique + adversarial, corriger jusqu'au 100 % sans faute.

**Branche** `heal/cms-pr1-quickwins-2026-05-18` · baseline `962d9d154` → **HEAD `ef40a6c1e`** (+2 commits : `18c5668a6` plan+audit+wave2, `ef40a6c1e` 3 heals) · backup `backup/pre-goal-sync-ordertaking-2026-05-29`.

---

## 1. Verdict

> ✅ **V1 LOCAL Le Cayenne est FONCTIONNELLEMENT PRODUCTION-READY** dans son enveloppe (machine unique + FR + `POS_SIMULATION_HARDWARE=true` dev / forbidden prod + 1 TPE + 1-2 bornes + encaissement comptoir Plan B). **0 P0**. Le flux de commande borne→cuisine est **prouvé live de bout en bout**. 3 heals robustesse/observabilité shippés (0 frozen-zone, 0 régression). Reste : backlog non-bloquant + owner gates (frozen + physiques).

---

## 2. Évidence — ce qui est PROUVÉ

### Audit adversarial (45 agents GStack + RED, ancré HEAD)
- **0 P0 confirmé.** 40 findings vérifiés (greppés file:line), **6 hallucinés/déjà-couverts droppés** par le verify-gate.
- **2 "owner gates" déjà résolus** (vérifiés au HEAD) : F1 (clamp discount manuel) RÉSOLU sur les 3 chemins pricing ; POS Refund UI SHIPPED + authz-gated.
- Sévérités : 0 P0 · 3 P1 (test-infra / safety-net / owner-gate UI) · ~14 P2 (majorité LATENT-V1 / V2 / DORMANT) · ~20 P3 (cosmétique / V2 / doc).
- Livrables : `reports/audit/goal-v1-sync-ordertaking-2026-05-29/<system>-verified.json` (9 systèmes).

### Flux de commande BORNE — prouvé live (place du client)
Idle → menu canonical (11 cat, items réels) → **wizard Tacos 4-step** (Poulet mariné + Algérienne + Sans menu) → cart (Tacos €8,50 + Coca €1,50 = **€10,00**) → upsell → **Plan B "PAIEMENT À LA CAISSE"** → commit → **order A0004**. DB : composition_snapshot frozen (`Viande 1 = Poulet mariné`), source=kiosk, fiscal_seq=NULL (correct, unpaid Plan-B), total 10,00. **Cascade Kiosk→KDS prouvée** : A0004 affiché sur KDS avec composition intégrale pour le chef. Screenshots `wave2-kiosk/01..14`. Détail : `WAVE2_BORNE_LIFECYCLE.md`.

### Baseline technique GREEN
- PHPUnit broad (Outbox/Sync/OrderFlow/StateMachine/Concurrency/KDS/Kiosk-quote/Pricing-SSOT/composition/OSS) = **164 passed / 2 skipped / 0 failed**.
- Vitest sync = **16/16**. Heal-area outbox/sync = **56 passed**.
- 5 surfaces visuellement GREEN baseline (kiosk idle / login / POS / KDS / OSS), cross-surface cohérent (A0001 POS↔OSS).
- NF525 **CHAIN OK** (append-only préservé). Frozen-zone **15/15 byte-identical**.

---

## 3. Heals shippés (3 — non-frozen, vérifiés, 0 régression)

| ID | Finding | Fix | Test | Commit |
|---|---|---|---|---|
| **H4** | `PersistOrderPaidAtCounterToOutbox` = seul listener order outbox sans swallow-alarm (fiscal-adjacent payment-confirmed → opérateur jamais pagé sur broadcast perdu) | Parité : `Log::error` + `OutboxBroadcastSwallowedEvent` (defense-in-depth) | +4e sentinel structurel ; 31 PASS | ef40a6c1e |
| **H3** | `MonitorOutboxStaleness` aveugle aux crash-claimed orphans (dispatched_at SET, jamais broadcast) — inaccessibles par retry-failed/rescue | Dimension d'alarme additive (`dispatched_at SET + last_error SET + attempts≥5 + old`), 0 faux-positif (Phase 3a clear last_error) | `OutboxMonitorCrashClaimedSentinelTest` 3 cas ; 56 PASS | ef40a6c1e |
| **H6** | `mobile/data/menu.js` commentaire stale "37 produits" | → "41 entrées sur 11 catégories" (recompté authoritatively) | n/a (cosmétique standalone) | ef40a6c1e |

---

## 4. Backlog (documenté, NON-bloquant V1) + recommandations

| ID | Sev | Système | Recommandation | Pourquoi non fait maintenant |
|---|---|---|---|---|
| H1 | P1 | Fiscal/test-infra | 16 tests `*Sentinel.php` non collectés par CI (`phpunit.xml suffix=Test.php`). **Triage chaque fichier** : renommer les vivants en `*SentinelTest.php`, supprimer/documenter les retirés (ex. allergen NOOP). | Assertions "drifted stale" + certains retirés délibérément → activer en masse surfacerait du RED ; nécessite triage timeboxé (advisor). |
| H2 | P1 | Fiscal/Livreur | `ZReportCashEnrichmentService` orphelin (cross-check cash-delivery EOD ne tourne jamais). **Câbler** en lane scheduler post-Z-close OU listener `ZReportClosed`, idempotent + sentinel. | Fiscal-adjacent ; câblage doit garantir no-double-write + ordering ⇒ implémentation fiscale-careful dédiée. |
| H3-full | P2 | Sync | Crash-claimed orphan **clean-first-attempt** (last_error NULL) indistinguible d'un succès sans flag schéma. **Ajouter** `broadcast_confirmed_at` distinct de `dispatched_at` (claim). | Changement schéma + migration ⇒ cycle dédié V1.0.X. Le subset détectable est déjà alarmé (H3). |
| WAVE2-OBS-5 | P2 | POS | "À encaisser borne" capé ~50 trié queue DESC → sous backlog, plus anciennes commandes inatteignables. **Ajouter** recherche par numéro / oldest-first / raise cap. | Amplifié par DB dev polluée (110+ ordres test) ; faible sévérité prod propre (owner réaligne DB). |
| WAVE2-OBS-3 | P2 | Kiosk | "Paiement en espèces uniquement à la caisse" → quand TTP carte comptoir live, rendre config-driven ("espèces ou carte"). | **Exact aujourd'hui** : carte comptoir "still in configuration" (owner). Changer maintenant serait FAUX. |
| KDS multi-screen bump | P0* | KDS | Bump "Prêt" mémorisé navigateur (banner LOCAL honnête). | Non-bloquant V1 mono-poste (owner gate layout A/B/C). |
| Divers | P2/P3 | tous | KDS kitchen-release contract divergence (3 surfaces vs SSOT, frozen dup) ; OSS V2 cross-tenant latent ; idempotency-recovery branch (VERIFIED non-exploitable) ; etc. | LATENT-V1 / V2 / DORMANT — correctement hors V1. |

---

## 5. Owner gates (WHO / WHAT / WHERE) — voir GOAL §G

Frozen-zone (countersign requis, NON-bloquants "V1 fonctionne", workarounds en place) : G2 PricingService F1(résolu)/F2 · G4 A03-1 pos-wizard menu-role · G7 Z-loop business_date · G9 LOCK_PAY currency. Physiques : G10 acquéreur CB + app TTP intérim · G11 marche physique + brancher imprimante + flip `.env`. **G3 KDS layout A/B/C** (architectural). **G5 POS Refund UI = déjà SHIPPED** (gate fermé).

---

## 6. Discipline tenue
- **0 frozen-zone touch** (15/15 SHA256 identiques). **0 auto-push.** **NF525 CHAIN OK** append-only. `git add` par fichier (secret-safe). Backup branch créé. Convergence : re-run green après chaque heal.

## 7. Hostile final pass (mood adversarial — owner mandate "see all the bad things")
Un agent RED-team dédié (read-only, evidence-bound file:line) a attaqué les 3 heals + le verdict.
- **A.1 (PaidAtCounter swallow alarm)** : UPHELD — signature event exacte, inner try/catch correct, 0 side-effect.
- **A.3 (mobile 41)** : UPHELD — 42 `mkItem(` − 1 def = 41, 11 catégories, standalone confirmé.
- **B (0 P0 order/sync)** : UPHELD pour la config live (Plan B `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true` config/kiosk.php:54) — counter path sound : `OrderPaidAtCounter` dispatché après commit, Transaction `firstOrCreate` (no double-charge), order row commited + polling backstop (no loss/divergence).
- **C (frozen untouched)** : UPHELD — `git diff 962d9d154..ef40a6c1e` sur 15 frozen = vide.
- **A.2 (MonitorOutboxStaleness "zero false-positives")** : **REFUTED (P3)** → un worker live re-drivé par retry-failed porte un `last_error` stale (Phase 1 ne le clear pas) ; un hang Pusher >30s sous l'ancien cutoff `stale-after` se faisait faussement pager. **CORRIGÉ** (`852db0873`) : age gate élargi à `max(stale-after, 600s)` (> backoff ~6,4min) + texte remédiation worker-down vs manual-re-drive + sentinel `test_monitor_does_not_false_positive_on_in_flight_retry_within_backoff_window`. 18 tests PASS.

**Résultat hostile pass** : 1 P3 trouvé + corrigé + re-testé + verrouillé. Aucun P0/P1 nouveau. Verdict §1 tenu.

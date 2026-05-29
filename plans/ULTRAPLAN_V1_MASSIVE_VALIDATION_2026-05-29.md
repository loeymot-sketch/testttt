# ULTRAPLAN — V1 Le Cayenne : Validation Massive « des Racines » → Cloud → SaaS

> **Directeur** : Claude (orchestrateur unique). **Owner** : Kossay (dev solo, premier projet de cette échelle).
> **Date** : 2026-05-29 · **Branche** : `heal/cms-pr1-quickwins-2026-05-18` · **HEAD** : `24343062b`.
> **Statut** : PLAN — à valider par l'owner. **NE PAS auto-lancer l'armée d'agents.** « Fais l'ultraplan → une fois fait, on lance. »

---

## §0 — Doctrine (lire en premier)

### §0.1 La leçon Encaisser (pierre angulaire de toute la méthodologie)
Le bug owner « Encaisser → Espèce → chiffres bizarres » est survenu **alors que 30 tests verts existaient** sur ce modal (`posCounterCollectModalSentinel` 15 + `counterCollectFrDecimal` 4 + `posKioskCashEncaisser` 11). **Aucun ne tapait la séquence réelle pré-remplissage + clic numpad.** Le bug a traversé 30 tests verts.

→ **Règle d'or V1** : *un test vert (unit/source/sentinel) est NÉCESSAIRE mais PAS SUFFISANT.* La preuve primaire est le **flux utilisateur RÉEL piloté** (vraies frappes, vrai clic, capture d'écran analysée), en prenant la place du **client / caissier / cuisinier / livreur / owner**. « Audit de surface » ≠ validation. **On valide des racines : driven E2E + visual + technical, jamais green-test-theater.**

### §0.2 Objectif final (séquencé — ordre owner immuable)
```
Phase A : V1 LOCAL 100% pilotée-verte  ──►  Phase B : Cloud + domaine  ──►  Phase C : Trial restaurant réel  ──►  Phase D : « imbattable » → SaaS vendable
```
- **Cloud (Phase B) GATÉ derrière « LOCAL 100% driven-green »** (mandat `feedback_no_cloud_until_owner_initiates.md` : l'owner initie le *planning* ici, PAS l'*exécution cloud*).
- Chaque phase = porte de validation explicite + evidence empirique.

### §0.3 Convergence (rejet littéral)
DONE = **2 cycles consécutifs identiques, P0+P1=0**, PAR fonctionnalité ET par intersection. Rejet immédiat si : chiffre/label bizarre à l'écran · bouton mort · layout cassé · console error · doublon non-déduppé · frozen-zone diff · NF525 chain altérée · « presque bon ». Production-perfect ou block.

### §0.4 Pipeline par tâche + frozen + NF525
Chaque tâche suit `ultra-audit-profond` (14 étapes). Frozen-zone (CLAUDE.md §7, 15 fichiers) → `lock-plan` + countersign owner. NF525 (CLAUDE.md §8) → chain append-only attestée à chaque wave. `git add` par fichier. Jamais `--no-verify`. Jamais auto-push.

---

## §1 — Décomposition architecture (ancres vérifiées HEAD `24343062b`)

> Anti-fiction : chaque ancre ci-dessous a été grep/Read-vérifiée (cycle 2026-05-29). 0 fichier inventé.

### Couche 0 — Foundation (infra transverse)
| Sous-système | Ancres réelles | Invariant |
|---|---|---|
| Multi-tenant | `app/Models/Scopes/BranchScope.php` ⛔ (20 models, sentinel `BranchScopeCoverageSentinelTest`) | isolation branche absolue |
| Auth | Sanctum `kiosk:order`, `KioskMachineLoginController.php` (token-name `kiosk-token`), `routes/channels.php` (branch.{id} authz) | ability stricte, no wildcard leak |
| Idempotency | `IdempotencyKeyMiddleware.php` ⛔ + `webhook_events` UNIQUE | dual-layer replay-safe |
| Events | `app/Providers/EventServiceProvider.php` (40+ events → listeners) | after-commit dispatch |

### Couche 1 — Synchronisation (le cœur — focus owner) [S1]
- **Outbox** : `app/Jobs/DispatchDomainEventsJob.php` (3-phase claim, backoff `[1,5,15,60,300]` tries=6), `app/Models/DomainEvent.php` (scopePending/Stale/Failed), 15× `app/Listeners/Persist*ToOutbox.php`, `EscalateOutboxBroadcastSwallowed.php`.
- **Commands** : `Outbox{Rescue,RetryFailed,WebhookRetryFailed}Command`, `MonitorOutboxStaleness` (+crash-claimed alarm, heal 2026-05-29), `PruneOutbox`.
- **Broadcast** : Soketi/Pusher `ShouldBroadcast`, `routes/channels.php`. **Cadences polling fallback** : KDS 5s(WS-down)/60s(up), POS 8s, OSS 2s/60s, Kiosk 15s.
- **7 cascades** : (1) POS→KDS · (2) Kiosk→KDS+OSS NF525-at-creation · (3) Allocation fiscale · (4) Hub→KDS · (5) Hub→OSS fail-closed allowlist · (6) KDS bump+undo-toast · (7) Settings/Catalog · + Refund 5-sous-cascade.
- Tests : `tests/Feature/{Outbox*,Sync*,KioskRealtimeBroadcast}` + `tests/js/*sync*.spec.js` + ~16 `tests/e2e/*sync*.spec.js`.

### Couche 2 — Surfaces (prise de commande + service)
| # | Système | Ancres | Frozen | Persona |
|---|---|---|---|---|
| S2 | **Prise de commande** (lifecycle/pricing/quote) | `OrderStateMachine`⛔ `PaymentStateMachine` `AutoPrepareOnPaidPolicy` `KitchenReleaseRule` `PricingService`⛔ | OSM, PricingService | — |
| S3 | **POS Caisse** | `PosController` `PosOrderController` `Pos/ParkedOrderController` · `pos-wizard.js`⛔ `PaymentComponent.vue`⛔ `PosV5TrancheRow`⛔ · **`PosCounterCollectModal.vue`** `PosV5Numpad.vue` (non-frozen, heal Encaisser 2026-05-29) | wizard, PaymentComponent | caissier |
| S4 | **Kiosk Borne** | `KioskEventController` `KioskMachineLoginController` · `Kiosk{Wizard,App,Upsell}`⛔ `Kiosk{Idle,Waiting,Confirmation,Payment,CashInstruction}` | 3 wizard comp | client |
| S5 | **KDS Cuisine** | `KitchenDisplaySystemController` `KdsSyncController` `KitchenReleaseRule` · `KdsV2Grid` `KdsHistoryDrawer` | — | cuisinier |
| S6 | **OSS Écran client** | `OrderStatusScreenController` · `OrderStatusScreenComponent` `OssSyncService.js` | — | client en attente |
| S7 | **Fiscal NF525 + Admin daily-ops** | `FiscalSequenceService`⛔ `ZReportService`⛔ `AuditLogService`⛔ `ZReportCashEnrichmentService` · `CashOverviewController` `AuditTrailComponent` | 3 fiscal services + triggers | owner |
| S8 | **Stock cascade** | `DecrementStock*`/`ReleaseStock*` listeners · `StockRuptureDashboardComponent` | — | owner |
| S9 | **Livreur** | `DeliveryBoyOrderController` `DeliveryBoyCashSessionService` | — | livreur |

### Couche 3 — Standalone (séparés, 0 wireup V1)
- **M1 Mobile RN** `mobile/` (palette noir/orange/jaune/blanc) — **PAS de flux téléphone→cuisine en V1** (mandat owner). Audit UX-only + parité menu (41 items/11 cat).
- **W1 Web** `/Users/1millnonstop/Downloads/web` — démo standalone.

### Intersections critiques (à tester explicitement — le « entre les systèmes »)
POS×KDS · Kiosk×KDS×OSS · KDS×OSS (flip statut) · Order×Stock cascade · Refund×(audit+loyalty+stock) · Loyalty earn/redeem · Settings×(POS+Kiosk) · Encaissement-comptoir × fiscal-seq-allocation.

### Déduplication (« est-ce qu'il y a doublon ? » — owner)
Zones connues à auditer pour doublon : (a) wizard POS (Vanilla JS) vs Kiosk (Vue) — UI séparées OK, logique pricing unifiée via PricingService ; (b) `KitchenReleaseRule` SSOT vs `OrderStateMachine` copie byte-identique (audit 2026-05-29 P3) ; (c) surfaces stock-toggle multiples (ItemList/IngredientList/dashboard) ; (d) endpoints counter-collect (drift `PosComponent` vs test). → Wave dédiée dedup-map.

---

## §2 — Méthodologie de validation (LE cœur — « des racines »)

### §2.1 Le triptyque par fonctionnalité (obligatoire, dans l'ordre)
Pour CHAQUE fonctionnalité (pas la surface — la fonction) :
1. **DRIVEN E2E (primaire)** — piloter la vraie séquence utilisateur (vraies frappes/clics) via Playwright, **en prenant la place du persona** (client borne / caissier / cuisinier / livreur / owner / client-OSS). Capturer chaque écran.
2. **VISUAL** — Read + analyser chaque capture : chiffre/label correct ? bouton vivant + cliquable ? layout intact ? branding ? i18n ? état vide/erreur cohérent ? « ce que voit le client, est-ce bon pour LUI ? ».
3. **TECHNICAL** — console 0 error, network 200, DB state correct (composition_snapshot, fiscal_seq, totaux), state-machine transition correcte.
4. **ADVERSARIAL (RED)** — un agent hostile rejoue la séquence pour casser : double-tap, valeurs limites (le keypad !), concurrence multi-poste, panne réseau mid-flow, payload forgé.
5. **PERSONA-CHECK** — « manque-t-il une fonction ? un bouton mort ? un problème visuel ou technique ? » du point de vue métier réel.

> **Green unit/source tests = filet de sécurité de régression, PAS preuve de fonctionnement.** (Cf. §0.1 — 30 verts, keypad cassé.)

### §2.2 Couverture exhaustive — matrice flux × persona
Chaque commande pilotée **jusqu'à sa sortie** selon son type/surface :
- **BORNE** (client) : idle→menu→wizard compo→cart→upsell→paiement Plan B→#commande→**encaissement comptoir**→préparation→prêt→retrait.
- **CAISSE** (caissier) : wizard POS→cart→PaymentComponent (cash/CB/split/ticket)→tiroir→ticket→KDS.
- **CAISSE encaissement borne** (caissier) : À-encaisser→Encaisser→mode→**keypad** (← bug owner fixé)→confirme→fiscal-seq→préparation.
- **LIVRAISON** (livreur) : commande→assign→session cash open→DELIVERED→encaissement→reconcile→close.
- **TÉLÉPHONE/Mobile** (standalone) : valider l'UX jusqu'où elle va — **dire clairement qu'elle se termine standalone** (pas de flux vers cuisine en V1).
- **Cross-surface** : chaque intersection §1 pilotée bout-en-bout + latence mesurée.

### §2.3 Sync & intersections (focus owner)
Tester chaque cascade LIVE : émettre l'event réel → vérifier propagation sur TOUTES les surfaces concernées + mesurer latence (broadcast < 2s, fallback < cadence polling) + prouver idempotence (rejouer) + prouver dead-letter (queue down → alarme) + prouver reconciliation (broadcast swallow → polling rattrape, pas de fenêtre stale durable).

---

## §3 — Orchestration agents (GStack + Superpowers + Adversars)

### Rôles (read-only audit, sauf Implementer)
Architect · Security+Race · SRE-Sync · DBA · UX/A11y · **Implementer** (TDD, jamais 2 parallèles) · **RED-team** (hostile, dispute toujours APRÈS implementer) · **QA-Visual** (Playwright capture+analyse) · **RED-Visual** (re-analyse indépendante, dispute la QA).

### Fan-out par type de tâche
| Type | Arch | Sec | SRE | DBA | UX | Impl | RED | QA-Vis | RED-Vis |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Surface UI (driven E2E) | x | x | . | . | x | x | x | **x** | **x** |
| Sync cascade | x | x | x | x | . | x | x | . | . |
| Fiscal NF525 | x | x | . | x | . | x | x | . | . |
| Intersection cross-surface | x | x | x | x | x | x | x | x | x |
| Dedup-map | x | . | . | x | . | . | x | . | . |

### Discipline dispatch
- 5 spécialistes read-only = SINGLE message parallèle (~3 min).
- Implementer séquentiel (worktree isolation si parallèle inévitable).
- **RED + RED-Visual TOUJOURS** avant DONE (anti confirmation-bias — cf. mon propre RED-team A.2 qui a réfuté ma claim « zero false-positives »).
- Chaque sous-agent **persiste findings sur disque** (`reports/.../wave-W-role.json`) — survit aux interrupts.

### Échelle cloud (Phase B) — « abuser du cloud avec max agents »
Local : fan-out borné (concurrence ~14, cap 1000 agents/workflow). Cloud : mêmes patterns, plus de parallélisme (waves par système simultanées sur infra dédiée), `/code-review ultra` cloud sur chaque PR. Mais **seulement après LOCAL green** (§0.2).

---

## §4 — Discipline HISTORIQUE & VERSIONING (pour TES modifications futures)

> Tu fais ce projet seul, première fois à cette échelle, et tu modifieras dans le futur. Voici la doctrine que TOI tu suis (pas seulement moi), pour ne jamais perdre le fil ni casser l'état impeccable.

1. **Provenance par commit** — chaque commit taggé `[GOAL-<slug>-<date>-<id>]` (ex `H4`, `BUG-CASH-KEYPAD`) dans le message + le code. On retrouve POURQUOI chaque ligne existe via `git log -S` / `git blame`.
2. **Ledger continuité** — `PROJECT_BRAIN.md` §2 (état courant) lu/écrit à chaque session ; §6 DECISIONS LOG **append-only** (chaque décision owner-validée immuable, anti re-questioning) ; §7 verification checklist (49 domaines).
3. **Milestones rollback** — branche `backup/pre-<mission>-<date>` + tag avant chaque cycle ; tag `v1.0.X-...` à chaque palier validé. Rollback = `git checkout <tag>` + DB dump correspondant (`storage/backups/`).
4. **Tamper-trail frozen** — SHA256 baseline des 15 fichiers frozen (`reports/audit/.../frozen-zone-baseline-sha256.txt`) + CI canary : toute modif non-LOCK-countersignée = CI fail.
5. **Checkpoint-commit** — commit par wave/phase (`checkpoint-commit` skill) : un interrupt (limite usage/context) reprend proprement via `git log` + INTERRUPT manifest. **Jamais de travail mid-flight perdu.**
6. **NF525 immuable** — `audit_logs`/`z_reports` append-only (triggers DELETE-forbidden) = historique fiscal légal 6 ans. La chain HMAC EST l'historique inviolable des ventes.
7. **Pour TES futures modifs** : (a) branche feature depuis main ; (b) jamais toucher frozen sans LOCK ; (c) driven-E2E ta modif (pas juste tests verts) ; (d) BRAIN §3 « last done » + commit taggé ; (e) si doute → me demander d'orchestrer un audit ciblé avant merge.

---

## §5 — Roadmap (4 phases gatées)

| Phase | Objectif | Porte de sortie (evidence) |
|---|---|---|
| **A — LOCAL** | Chaque fonctionnalité + sync + intersection **pilotée-verte** (driven E2E + visual + RED), 2 cycles identiques P0+P1=0 | convergence report + screenshots analysés + frozen=0 + NF525 OK |
| **B — CLOUD** | Déploiement domaine + Soketi + queue worker + backups + observabilité ; orchestration cloud max-agents | deploy log + smoke prod + DR drill ; **GATÉ derrière A green + owner go** |
| **C — TRIAL RESTO** | Service réel Le Cayenne : matériel (imprimante + TPE), personae réelles, soak multi-jours | journal soak + incidents=0 + Z quotidiens OK |
| **D — IMBATTABLE → SaaS** | Multi-tenant durci, onboarding, packaging vente | audit SaaS-readiness + multi-tenant hard-fail tests green |

---

## §X — Waves d'exécution (déclenchées par owner « lance »)

| Wave | Scope | Parallélisme | Checkpoint |
|---|---|---|---|
| **W1 Pre-flight** | env + DB realign (110 ordres test) + backup + baselines + **rebuild bundle** | séquentiel | server 200, NF525 OK, frozen SHA baseline |
| **W2 Surfaces driven E2E** | borne + caisse + caisse-encaissement(← keypad) + KDS + OSS + livreur, chaque persona, jusqu'à sortie | 1 surface à la fois, audit-phase parallèle | tous flux pilotés verts, screenshots analysés, 0 bouton mort |
| **W3 Sync + intersections** | 7 cascades + intersections + latence + dead-letter + reconciliation, RED hostile | séquentiel | latence mesurée, 0 fenêtre stale, idempotence prouvée |
| **W4 Fiscal + cash + Z + refund** | Z-close live + PDF + chain extend + recon Σ + refund cascade + encaissement Plan B | séquentiel | chain append-only, recon balanced |
| **W5 Dedup-map + cleanup** | doublons §1 + endpoints drift + surfaces redondantes | parallèle read-only | map livrée, doublons résolus/documentés |
| **W6 Convergence + hostile final** | full smoke + cross-surface + 2 cycles identiques + RED « mauvaise humeur » | séquentiel | GO/NO-GO, owner gates, BRAIN, tag |

Chaque wave : checkpoint 6-points + interrupt-resume manifest + BRAIN §2/§3 (cf. `ultra-architect-planify` Axis 3).

---

## §G — Owner Gates (WHO / WHAT / WHERE)
| Gate | Description | WHO | Status |
|---|---|---|---|
| G-A | Valider cet ULTRAPLAN avant exécution | owner | **PENDING (cette livraison)** |
| G-B | Frozen heals (PaymentComponent keypad si même bug, PricingService F2, A03-1, Z-loop) | owner countersign LOCK | PENDING |
| G-C | DB realign (purge 110 ordres test) | owner ou Claude+go | PENDING |
| G-D | Cloud go (Phase B) — domaine + acquéreur CB/TTP | owner | PENDING (gated derrière A) |
| G-E | Marche physique resto (imprimante + TPE + walk) | owner | PENDING |

---

## §F — DONE = imbattable
1. Chaque fonctionnalité **pilotée live** comme client/caissier/cuisinier/livreur/owner — pas de surface.
2. Chaque sync + intersection prouvée bout-en-bout, latence mesurée, 0 fenêtre stale, dead-letter alarmé.
3. Dedup résolu/documenté. 0 bouton mort, 0 chiffre bizarre (← keypad), 0 fonction manquante.
4. 2 cycles identiques P0+P1=0 · frozen=0 · NF525 chain OK · 0 console error.
5. RED « mauvaise humeur » final ne trouve rien de non-documenté.
6. Historique tracé (commit-tags + BRAIN + backups) pour modifs futures.
7. **Production-perfect ou block. Jamais surface. Jamais auto-push. Jamais cloud avant local-green.**

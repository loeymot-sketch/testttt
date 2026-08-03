> ⚠️ STATUS 2026-06-05 — EXECUTION HAS ALREADY STARTED (owner opened the gate « exécute le goal max power and smart »).
> A fresh session is NO LONGER required to *start* — work is in progress on branch
> `heal/pre-cloud-exec-2026-06-05` (worktree `.claude/worktrees/pre-cloud-exec`).
> Already done: W1 baselines (PHPUnit 2844/0, Vitest 275/0) + 21-P1 reconcile + **4 P1 healed**
> (S6-01, S17-01, S10-01, M6-001 — TDD, committed, 0 frozen). 15 P1 remain.
> **Before re-running anything, READ `reports/test-e2e/pre-cloud/EXECUTION-STATUS.md` + `W1-BASELINE.md`
> on that branch** so you don't redo W1. Gate-G (frozen fusion `PaymentComponent.vue`) awaits owner countersign.
> The self-contained prompt below remains the canonical anti-drift brief for the campaign.

---

# 🚀 LAUNCH PROMPT — Pre-Cloud Remediation & Validation (paste this whole block into a NEW session)

> Owner: copy everything between the two `=====` lines into a fresh Claude Code session
> opened at the repo root `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`.
> It is self-contained, anti-drift, and authorizes execution. The supervisor (prior session)
> already ultra-reviewed and CONFIRMED the goal; this prompt makes the new session EXECUTE it.

=====================================================================================

Tu es le **superviseur/gérant** de FoodKing V1 (Le Cayenne — logiciel PERSONNEL, mono-poste
LOCAL, FR, 1 branche `branch_id=1`, NF525). Mission : **EXÉCUTER le goal pre-cloud-remediation
déjà préparé et confirmé**, jusqu'à **zéro défaut production-perfect**, en bouclant avec
agents adversaires + tests E2E. Tu lances vraiment cette fois (l'owner autorise l'exécution).

## 0. COLD-START — LIS CES FICHIERS EN PREMIER, DANS CET ORDRE (obligatoire, ne code rien avant)
1. `CONSTITUTION.md` (racine) — vision V1, 5 systèmes, règles dures, frozen, NF525, **TPE = simulé/manuel**.
2. `PROJECT_BRAIN.md §2` — état courant daté (le 1er bloc = ce goal ACTIF + la DIRECTION CORRECTION).
3. **`reports/audit/pre-cloud-2026-06-04/SUPERVISOR_REVIEW_VERDICT_2026-06-04.md`** — le verdict du
   superviseur (CONFIRM-WITH-CORRECTIONS) + **§DIRECTION CORRECTION (BINDING)**. C'EST TA FEUILLE DE ROUTE.
4. `plans/GOAL_PRE_CLOUD_REMEDIATION_VALIDATION_2026-06-04.md` — la stratégie (8 waves, disciplines, gates).
5. `reports/audit/pre-cloud-2026-06-04/PRE_CLOUD_REMEDIATION_CATALOG.{md,json}` — les 156 findings (worklist).
6. Mémoire : `[[feedback-terminals-manual-encaissement-unified-2026-06-05]]`,
   `[[project-cutover-inprogress-2026-06-04]]`, `[[feedback_v1_personal_le_cayenne_2026-05-28]]`,
   `[[feedback_massive_team_orchestration_e2e_per_system]]`, `[[reference_frozen_zones]]`.
7. `SYSTEM_MAP.md` / `SYNC_CONTRACT.md` / `PARALLEL_PROTOCOL.md` — si travail multi-système/parallèle.

## 1. CE QU'IL FAUT ATTEINDRE (le but, non-négociable)
**Gate go-live = les ~19 P1 GREEN** (21 − M11-02 déjà résolu data-ops − S8-01 différé) **+ attestation
NF525 chaîne appended-only + discipline frozen (diff=0 ou LOCK contresigné) + W8 auth audité.**
P2 (58) et P3 (77) = **incrémentaux/non-bloquants** (« strictly zero » = cible asymptotique, PAS la
condition GO). Plus l'objectif owner ci-dessous.

### ⚠️ DIRECTION BINDING (l'owner l'a recadrée — NE DÉVIE PAS)
- **TERMINAUX DE PAIEMENT = HORS go-live.** Borne + caisse non-configurés → **alternative manuelle
  SumUp** (carte→manuel+référence). **AUCUNE préparation terminal.** **S8-01 (TPE branch_id) + gate
  G-F = DIFFÉRÉS** (future mission terminal-onboarding). Ne JAMAIS framer un finding comme « faire
  marcher le vrai TPE en live ». C'est futur, owner-gated.
- **OBJECTIF OWNER #1 = UNIFIER L'ENCAISSEMENT.** Aujourd'hui la popup d'encaissement **borne ≠ caisse**.
  L'owner veut **UN SEUL système d'encaissement** identique, où au paiement on choisit
  **Espèces / Tickets-restaurant / Terminal** (Terminal = alternative manuelle SumUp + référence).
  - Surface unifiée = **`PosCounterCollectModal` (NON-frozen)** — `PaymentComponent.vue` est **FROZEN
    §7, NE PAS toucher sans LOCK+contreseing**. Faire transiter borne-collect ET caisse-collect par
    cette surface non-frozen. **Nouveau gate G-H** (design de la surface unifiée) avant de coder.
  - Fondation existante : le travail encaissement (OrderPayment/méthodes, `PosCounterCollectModal`,
    `confirmCounterPayment` P-CARD/TR/CASH) est sur la branche `heal/cms-pr1-quickwins-2026-05-18`
    (+57 commits, pas dans main) → **réconcilier au merge, ne pas re-coder from scratch**.

## 2. DISCIPLINES NON-NÉGOCIABLES (CLAUDE.md)
- **LOOP §5** : orchestrate→plan→execute→audit→test(technique)→visual-test→self-correct(max 3)→update BRAIN.
- **FROZEN §7** : 14 fichiers intouchables sans `/lock-plan` + contreseing owner + triple-vert. Toujours
  préférer un fix scope-minimal HORS du fichier frozen (caller/config/shim). `pos-wizard.js`,
  `PaymentComponent.vue`, `ZReportService`, `OrderStateMachine`, `FiscalSequenceService`, `AuditLogService`…
- **NF525 §8 + §0.6 du plan** : avant ET après chaque wave fiscale → `php artisan fiscal:verify-chain --all`
  + `fiscal:assert-chain-clean` + `fiscal:verify-z-membership` ; chaîne **appended-only** (count croît/égal,
  hashes antérieurs inchangés) ; diff services fiscaux frozen = 0 sauf LOCK. Changement inattendu → STOP + gate humaine.
- **ANCHOR-FIRST anti-fiction** : les `file:line` du catalogue DÉRIVENT — vérifie **SÉMANTIQUEMENT contre le
  code de main** à l'ouverture de chaque wave ; un finding non-reproduit = NE PAS coder.
- **Per-task pipeline** : skill `ultra-audit-profond` (14 étapes : 5 spécialistes read-only parallèle →
  implémenteur TDD-first → dispute RED → double visual cross-check → gates frozen/NF525 → commit → BRAIN).
  Army map = skill `ultra-architect-planify` Axe 4. **Max sous-agents en parallèle** (Workflow tool /
  Agent), GStack + Superpowers + adversaires, par zone/système.
- **Evidence §13** : test technique vert ≠ fini. Gate visuel obligatoire (capture→Read→analyse, 0 raw label,
  2 captures propres consécutives) + E2E Playwright du click-path réparé. Pour fiscal : attestation chaîne.
- **Sécurité/Git §3quater** : jamais `git add .`/`-A` ; jamais `.env`/secrets/`.key`/`.pem` ; jamais
  auto-push/`--force`/`--no-verify` ; **jamais `config:cache` sur boîte live** (casse la chaîne NF525 via
  `AuditLogService` env() — PR-07/UNI-03). Worktree isolé par wave ; frozen dans un worktree quarantiné sous LOCK.

## 3. PROTOCOLE D'EXÉCUTION (waves risk-first, séquentiel sur roots partagés)
- **W1 — Baselines + Owner Gates + Live-verify (AUCUN fix).** Full PHPUnit + Vitest verts, frozen
  `git diff --stat`=0, snapshot chaîne NF525, walk admin 29 pages (0 console/0 raw label = plancher).
  Résoudre LIVE les 5 needs-live-verify AVANT de designer leur fix. **Dispatcher les owner gates** (§4).
  Écrire `reports/test-e2e/pre-cloud/W1-BASELINE.md`.
- **W2 — Operator/Receipt NF525** (cluster `ReceiptDataService:70` operator=cashier ; heal `6b26e1be3`
  à cherry-pick OU re-dériver — il N'EST PAS sur la branche). Réconcilier avec le travail worktree.
- **+ CLUSTER ENCAISSEMENT-UNIFIÉ (objectif owner #1)** : unifier borne+caisse sur `PosCounterCollectModal`
  (cash/TR/Terminal-manuel), réconcilier le travail encaissement existant, gate G-H d'abord.
- **W3 Money&Fiscal** (OrderService spine + Z netting/bucketing + counter-collect ; frozen→§5 quarantine).
- **W4 Sécurité** (PII guard, branch-isolation, RBAC, **App-Debug toggle S7-03→gate G-E**). PAS S8-01 (différé).
- **W5 Logic-blur** (le gros : wizard steps, parked-recall, supplements, offline-replay, dashboard filters…).
- **W6 UX/Visual** · **W7 Deadcode/config** (gate G-A intent remise d'abord).
- **W8 Deeper-audit** : 147 gaps + **auth surface = passe de CONFIRMATION de couverture** (les guards
  rate-limit/entropy/bcrypt existent DÉJÀ ; seul ouvert = Sanctum kiosk:order TTL 480min, backlog V1.0.1)
  + dine-in (gate G-C). Puis convergence finale ×2 → verdict GO/NO-GO.
- Chaque wave : cluster par root → `ultra-audit-profond` → dispute RED → gate visuel → attestation NF525 si
  fiscal → checkpoint + `INTERRUPT_<wave>.md` + BRAIN.

## 4. OWNER GATES — DEMANDE-LES À L'OWNER AVANT DE DESIGNER LES FINDINGS DÉPENDANTS
A (intention remise manuelle ON/OFF) · B (commandes comptoir sur OSS show/exclude) · C (dine-in
garder/retirer) · E (toggle App-Debug retirer/restreindre) · **G-H (design surface encaissement unifiée)** ·
G (contreseings frozen /lock-plan). **DIFFÉRÉS : D (déjà résolu — branche peuplée), F (TPE — futur).**

## 5. CONVERGENCE / DONE (le « 0 problème, 100% fonctionnel, sans faute »)
Loop adversarial jusqu'à : **P0+P1=0 sur 2 cycles consécutifs avec findings set IDENTIQUE** (flake guard) ;
chaque P1 GREEN avec evidence disque (technique + visuel + chaîne si fiscal) ; frozen diff=0 ou LOCK
contresigné triple-vert ; NF525 chaîne appended-only ; walk 29 pages 0 console/0 raw label ; sentinels 100%.
P2/P3 = GREEN ou owner-deferred-with-rationale. Règles de rejet (§8.1 plan) : tout raw label / layout break /
console error / frozen-diff-sans-LOCK / « almost works » → REJECT + heal. **no push sans owner.**

## 6. RÈGLE FINALE
Tu es responsable de la qualité production. Filtre CHAQUE finding contre la vision V1 AVANT de le traiter
(ne pas adopter aveuglément le catalogue ; différer ce qui contredit l'enveloppe owner — ex. les terminaux).
Agis comme superviseur, pas comme développeur bête. Boucle, dispute, teste, prouve. Au moindre doute
architectural / frozen-touch / contradiction vision → STOP + gate owner.

=====================================================================================

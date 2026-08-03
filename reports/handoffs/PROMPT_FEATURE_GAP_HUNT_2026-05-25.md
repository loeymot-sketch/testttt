# 🔥 PROMPT MANDATE — FEATURE GAP HUNT + ULTRA AUDIT DEEP

**Copie ce prompt ENTIER dans la nouvelle session Claude Code. ~150-200 sub-agents en parallèle massif batchés. Pas de limite token par agent. Profondeur cognitive ULTIME. Mission : trouver les fonctionnalités MANQUANTES + auditer le réel travail utilisateur + corriger jusqu'à 100% validé.**

---

# YOU ARE — CHEF DE DÉVELOPPEMENT EXÉCUTANT — DERNIER CYCLE AVANT PRODUCTION

Tu es nouvelle session Claude Code Opus 4.7 1M context. **La session précédente est SUPERVISEUR** (sévère, exigeante). Toi tu es l'exécutant ULTRA-INTELLIGENT.

**Ton rôle a évolué** : tu n'audites plus le code existant. Tu **chasses les fonctionnalités MANQUANTES** que les utilisateurs réels vont demander dès qu'ils utilisent le système. Les 16 cycles précédents (213 agents) ont audité le code existant — efficace. Mais ils ont presque oublié de demander : **« qu'est-ce qui devrait exister et n'existe pas ? »**

**Owner mandate verbatim 2026-05-25** :
> « Quand je vois là maintenant l'écran de cuisine, je peux pas y accéder aux archives parce que je peux par exemple avoir fait valider une commande par erreur avec rapidité, je vais revenir pour la corriger. Là cette fonction si je la vois pas et peut-être d'autres fonctions qui doivent être présente et ils sont pas là — pareil pour la caisse, la borne et le système global. Couvrir ces points avec intelligence. Ultra déterminé. Maximum d'agents spécialisés gstack superpowers adversarial. Finir par /test-e2e capture d'écran, analyse profond, raisonnement profond, corrections profondes — tests technique, logique, interface, visuel, prendre la place de l'utilisateur. Couvrir tous les points, corriger au maximum avec captures d'écran comme preuves et les analyser. »

---

# §0 BOOTSTRAP MANDATORY (20-25 min — pas de raccourci)

## Lectures obligatoires AVANT toute action

| # | Fichier | Section critique |
|---|---------|------------------|
| 1 | `CLAUDE.md` | §1-§16 intégral |
| 2 | `PROJECT_BRAIN.md` | §1 NORTH STAR · §2 CURRENT STATE · §4 NEXT TO DO |
| 3 | `~/.claude/projects/.../memory/MEMORY.md` | START HERE + 5 derniers feedback_*.md |
| 4 | `reports/test-e2e/goal-2026-05-23/RAPPORT_COMPLET_CYCLE.md` | Synthèse cycle précédent |
| 5 | `reports/audits/SUPER1_CLAIMS_VERIFICATION_2026-05-25.md` | Numérique confirmé |
| 6 | `reports/audits/SUPER2_SECURITY_ADVERSARIAL_2026-05-25.md` | Security gaps V1.0.2 |
| 7 | `reports/audits/SUPER3_NF525_EDGES_2026-05-25.md` | NF525 edges PARTIAL |
| 8 | `reports/audits/SUPER4_PROD_FAILURE_MODES_2026-05-25.md` | **3 ops gates V1** |
| 9 | `reports/audits/SUPER5_REGRESSION_HUNTER_2026-05-25.md` | ZERO regressions confirmed |
| 10 | `reports/audits/SUPER6_CHEF_RUSH_STRESS_2026-05-25.md` | **Option A wins empirique** |
| 11 | `reports/audits/SUPER7_ADVERSARIAL_ON_REPORT_2026-05-25.md` | Report oversold ~30% |
| 12 | Tous `reports/handoffs/PROMPT_GOAL_*.md` | Historique mandats |
| 13 | `reports/playbooks/OWNER_OPERATIONS_PLAYBOOK_2026-05-23.md` | Workflows owner |

## Activations MCPs

- `.mcp.json` racine → Playwright MCP (accepte au démarrage)
- Graphiti `group_id="foodking"` → search "feature gap", "wave-final", "chef-rush"

## Acknowledge au superviseur (cette session précédente)

En 5 lignes :
1. « J'ai lu les 13 sources contexte »
2. « Top 3 lessons learned des audits »
3. « Mon plan atomique des 5 phases avec compte agents par batch »
4. « Total estimé X agents · Y heures »
5. « Je lance ? »

---

# §1 OWNER'S OBSESSION — FEATURE GAP HUNT (le coeur de cette mission)

L'agent précédent a corrigé 33+ bugs dans le code existant. Mais il n'a **JAMAIS demandé** : « qu'est-ce qui manque ? »

**Owner-mentionné explicite** (à NE PAS sous-évaluer) :
- **KDS Archives "undo" récent** : chef bumpe PREPARED par erreur (rush hour), il a besoin de revenir en arrière. **Pas dispo aujourd'hui** (Wave X X3 history = read-only V1). Owner concrètement bloqué.

**Owner-implicite** (à découvrir via 6 personas) :
- POS : annuler une vente déjà encaissée ? Refund manuel ? Remboursement carte ?
- POS : pause caisse pendant qu'on aide un client physiquement ?
- Caisse : transférer une commande d'un caissier à un autre ?
- Borne : modifier sa commande après Valider mais avant payer ?
- Borne : ajouter un message au chef (allergie, demande spéciale) si pas dans le wizard ?
- Borne : commande pour plusieurs personnes — split paiement ?
- Cuisine : marquer une commande "en attente d'ingrédient" sans la bumper ?
- Cuisine : voir les commandes annulées du jour (raison, qui) ?
- OSS : client demande "où en est ma commande exactement" ?
- Admin : exporter les ventes du jour en PDF/Excel pour comptable ?
- Admin : voir le détail d'une transaction quand client réclame ?
- Admin : noter un incident (matière premières renversée, client mécontent) ?
- Stock : alerte automatique "il reste 3 portions" avant rupture ?
- Stock : prédire la demande basé sur jour de semaine ?

**Et probablement 50 autres** que tu vas découvrir.

---

# §2 GUARDRAILS — RÉPÉTÉS POUR ÉVITER DÉRIVE

## 🚫 FROZEN ZONES (PROPOSAL mode, jamais auto-fix)

```
resources/js/components/admin/pos/PaymentComponent.vue
resources/js/components/admin/pos/v5/PosV5TrancheRow.vue
resources/js/components/frontend/kiosk/KioskWizardComponent.vue
resources/js/components/frontend/kiosk/KioskAppComponent.vue
resources/js/components/frontend/kiosk/KioskUpsellComponent.vue
public/js/pos-wizard.js + public/css/pos-wizard.css
app/Services/Fiscal/*.php
app/Models/Scopes/BranchScope.php
app/Http/Middleware/IdempotencyKeyMiddleware.php
app/Services/Pricing/PricingService.php
app/Domain/Order/OrderStateMachine.php
database/migrations/*.php applied
```

**Si une feature gap nécessite frozen-zone touch** : écris `proposals/PROPOSAL_GAP_*.md` avec raisonnement fort multi-persona. Ne touche RIEN. Owner countersigne au cas par cas.

## 🛡️ NF525 invariants (verifiable AVANT + APRÈS chaque correction)

- `php artisan fiscal:verify-chain` doit dire **CHAIN OK** avant ET après chaque ship
- `audit_logs` append-only
- `fiscal_sequence_no` monotonic gap-free
- `composition_snapshot` JSON frozen

## 🎯 Mandate V1 single-resto FR + no useless complexity + scope-minimal ≤30 LOC FR-only sur fichiers non-frozen pour auto-apply

## 🔧 Bundle freshness (Q12)

Tout modif `.vue` ou `.json` i18n → `npx mix` mandatory + verify mtime.

---

# §3 ARCHITECTURE D'EXÉCUTION — 8 PHASES, ~180 AGENTS, BATCHÉE INTELLIGEMMENT

```
PHASE A  ·  GAP-FIX rapide ops gates du Super.4    ·   3 agents //    · 1h
            (G0.7 UptimeRobot + G0.8 cap items + G0.6 runbook doc)

PHASE B  ·  FEATURE GAP HUNT — découverte           ·  ~85 agents //   · 90 min
            (7 systèmes × 6 personas + 8 missing-functionality patterns +
             6 cross-system gaps + ~30 inspirations e-commerce/POS leaders)

PHASE C  ·  GAP TRIAGE + OWNER DECISIONS NEEDED     ·   3 agents //    · 30 min
            (regrouper findings, score by value × effort, surface owner)

PHASE D  ·  ULTRA AUDIT TECHNIQUE (encore plus profond) · ~50 agents //   · 90 min
            (7 systèmes × 7 spécialistes × 3 personas négociation +
             frozen-zone proposals refresh + adversarial dispute final)

PHASE E  ·  HEAL WAVE — appliquer corrections       ·   N agents //    · selon E
            (1 agent par gap healable scope-minimal, N selon Phase C)

PHASE F  ·  BEFORE/AFTER deep diff EACH heal        ·  ~3N agents //   · selon F
            (DM2 discipline — pre+post snapshot par correction)

PHASE G  ·  /test-e2e MASSIVE FINAL                 ·  ~30 agents //   · 60 min
            (7 systèmes × 4 personas + 10 production scenarios +
             chaîne complète Borne→Caisse→KDS→OSS→Cash 10× consécutives)

PHASE H  ·  SYNTHÈSE + GRAPHITI + BRAIN UPDATE       ·   3 agents //    · 20 min

TOTAL : ~180-220 agents · 5-8h wall-clock · TROUVE TOUT ce qui manque
```

---

# §4 PHASE A — RÉGLER LES 3 OPS GATES SUPER.4 (3 agents parallèle)

Le superviseur a découvert 3 gaps opérationnels qui doivent être réglés AVANT go-live. Avant de chasser de nouvelles features, ferme ces 3.

## A.1 — UptimeRobot heartbeat endpoint + setup

```
You are AGENT A.1. Scope-minimal.

Mission: create /api/healthz endpoint + register at UptimeRobot free plan
(or document setup if owner SaaS key required).

Tasks:
1. Add route GET /api/healthz returning JSON:
   {
     "status": "ok",
     "checks": {
       "db": "ok|fail",
       "redis": "ok|fail",
       "websocket": "ok|fail",
       "fiscal_chain": "ok|fail",
       "queue_pending": <count>
     },
     "timestamp": "ISO8601"
   }
2. Add Console command `php artisan healthz:check` (returns 0/1)
3. Add to app/Console/Kernel.php cron `->everyFiveMinutes()` writing
   storage/logs/heartbeat.log
4. Add scripts/deploy/UPTIMEROBOT_SETUP.md owner-instructions:
   - Crée compte free UptimeRobot
   - Add monitor HTTPS lecayenne.fr/api/healthz interval 5min
   - Configure alerts email + SMS owner
5. Document fallback (Cronitor, Better Stack alternatives)

Sentinel: tests/Feature/HealthzEndpointTest.php

Commit: feat(ops-gate-1): healthz endpoint + UptimeRobot setup doc
```

## A.2 — ValidJsonOrder cap 50 items

```
You are AGENT A.2. 1 LOC fix.

Mission: cap order items count to 50 to prevent DoS via cart 1000 items.

Tasks:
1. Read app/Rules/ValidJsonOrder.php
2. Add at line ~32: if (count($decoded) > 50) { $fail('items_cap_exceeded'); }
3. Add i18n FR key 'items_cap_exceeded' = "Maximum 50 articles par commande"
4. Test: tinker create cart with 51 items → should reject

Sentinel: tests/Unit/Rules/ValidJsonOrderItemCapTest.php

Commit: feat(ops-gate-2): cap order items 50 — DoS protection
```

## A.3 — Runbook TPE-vs-app reconciliation

```
You are AGENT A.3. Documentation only.

Mission: write reports/playbooks/RECONCILIATION_TPE_RUNBOOK_2026-05-25.md
for cashier morning routine.

Sections:
1. Pourquoi: protéger contre electricité coupée mid-paiement, fiber drop
2. Routine matin (5 min):
   - Imprime ticket fin de journée TPE physique
   - Imprime Z-report Laravel /admin/cash-overview ?date=hier
   - Compare ligne par ligne montants + nombre transactions
   - Écart > 0 → noter sur cahier + flag escalation
3. Actions selon scénario écart (5 scenarios):
   - TPE > Laravel: charge fantôme (cust paid + system ignored)
   - Laravel > TPE: revoir ce qu'on n'a pas encaissé carte
4. Quand appeler le support (Shine, banque, owner)
5. Template Excel/Google Sheets pour journal reconciliation

Owner-friendly language, pas technique. Print-ready A4.

Commit: docs(ops-gate-3): TPE reconciliation runbook A4 printable
```

---

# §5 PHASE B — FEATURE GAP HUNT MASSIVE (~85 agents parallèle)

**C'est le cœur de cette mission**. Tu dispatches en 4 sous-batches successifs.

## §5.1 — Batch B.1 : 42 agents (7 systèmes × 6 personas)

**Single-message 42 agents.**

Pour CHAQUE système (S1 Borne, S2 POS, S3 KDS, S4 OSS, S5 Cash, S6 Stock, S7 Admin) × CHAQUE persona = 42 :

### Personas (réutilisés du cycle précédent mais maintenant axés FEATURE GAP)

| Persona | Question centrale |
|---------|-------------------|
| **P1 Chef-rush** | « En rush, quel bouton/écran me manque ? » |
| **P2 Client-impatient** | « Quelle option client j'aimerais avoir et n'ai pas ? » |
| **P3 Caissier-multitask** | « Quel raccourci/vue me ferait gagner 30 secondes ? » |
| **P4 Owner-night** | « Quel rapport/insight m'aiderait à pilotage soirée ? » |
| **P5 Inspecteur-fiscal** | « Quelle traçabilité me manque pour audit 6 ans ? » |
| **P6 Staff-newbie** | « Que peut-il faire involontairement qui me coûterait cher ? » |

### Prompt template pour chaque persona-système

```
You are GAP-HUNTER AGENT [system]-[persona] (e.g. S3-P1 = KDS Chef-rush).

Mission: pilot Playwright on [system URL], LIVE — try every action, every
button, every flow. Then ask : "QU'EST-CE QUI ME MANQUE ?"

Approach:
1. Login as appropriate user (admin@lecayenne.fr / 123456)
2. Naviguer EXHAUSTIVEMENT le système (chaque bouton, chaque menu, chaque
   modal, chaque empty state)
3. Pour chaque écran capture quartet (PNG + DOM + console + network)
4. RAISONNE comme la persona [P]:
   - Owner-mentioned KDS Archives undo (P1 KDS Chef-rush) = exemple
   - Owner-mentioned POS undo encaissement (à inventer si manquant) = exemple
   - Owner-mentioned borne modif commande (P2 borne client) = exemple

5. Pour CHAQUE feature gap identifiée:
   - ID: GAP-[system]-[persona]-NNN
   - Titre court (1 ligne)
   - User story: "En tant que [P] sur [system], je devrais pouvoir [X]
     parce que [Y]"
   - Évidence absence (screenshot, parcours navigation, search code)
   - Impact (BLOCKER-DAILY / FRICTION-WEEKLY / NICE-TO-HAVE)
   - Effort estimé (XS=1h / S=4h / M=8h / L=2j / XL=1sem)
   - Risk if absent (data loss? customer churn? fiscal compliance?)
   - Frozen-zone touch needed? (oui/non)

Spécifiques par persona à NE PAS MANQUER:

P1 Chef-rush sur S3 KDS:
- Archive undo (owner-mentioned, vérifie absence)
- Reprendre commande "en attente" sans la bumper
- Voir commandes annulées avec raison
- Communiquer avec caisse (signaler manque ingrédient)

P2 Client-impatient sur S1 Borne:
- Modifier commande après cart avant payer
- Annuler commande mid-flow (avant payer)
- Sauver pour revenir plus tard
- Voir détail allergènes par item
- Recevoir SMS quand prêt

P3 Caissier-multitask sur S2 POS:
- Annuler vente encaissée (refund)
- Pause caisse pendant absence
- Transférer commande à autre caissier
- Voir client historique
- Note interne sur transaction

P4 Owner-night sur S7 Admin:
- Export PDF/Excel ventes du jour
- Comparaison J vs J-1, J vs S-1
- Détail transaction quand client réclame
- Top items du jour avec graphique
- Predict tomorrow basé sur historique
- Note incident journalier

P5 Inspecteur sur tous systèmes:
- Replay parfait d'une transaction historique
- Traçabilité qui a modifié quoi
- Export NF525 archive complète
- Z-report ré-imprimable d'un jour passé
- Audit chain verify CLI accessible

P6 Staff-newbie sur tous systèmes:
- Confirmation avant action irréversible
- Undo récent en cas d'erreur
- Aide contextuelle inline
- Workflow guide premier jour
- Limites sécurité (montant max single transaction)

Output: reports/gap-hunt-2026-05-25/B1-[system]-[persona].json

Use Playwright MCP if connected. Captures to:
tests/e2e/__screenshots__/gap-hunt-[system]-[persona]/

Report-back to orchestrator: N gaps found / top 3 BLOCKER-DAILY / verdict
```

## §5.2 — Batch B.2 : 8 cross-system gap agents

Identifient les gaps INTER-SYSTÈMES qu'un système isolé ne montrerait pas :

- **C1** Borne→POS handover : si client commande borne puis veut payer caisse, workflow propre ?
- **C2** POS→KDS rectification : caissier annule encaissement → KDS doit savoir
- **C3** KDS→OSS communication : chef veut signaler "retard 10 min" au client
- **C4** Admin→Caisse : owner pousse promo flash → caisse l'applique automatique ?
- **C5** Stock→Borne+POS+KDS : rupture qui se propage cohérent partout
- **C6** Multi-device sync : 2 caissiers simultané même commande
- **C7** Cross-day operations : commande commencée 23h59, payée 00h01 → quel jour fiscal ?
- **C8** Customer journey end-to-end : SMS notification, fidélité, retour

## §5.3 — Batch B.3 : 6 missing-functionality patterns inspirés leaders

Étudient ce que les concurrents POS font :

- **L1** Square POS — quelles fonctionnalités on a / on n'a pas
- **L2** Toast POS (resto US) — comparaison
- **L3** McDonald's borne (kiosk benchmark) — interactions client
- **L4** Lightspeed (resto Europe) — gestion stock + employés
- **L5** Hubrise / Deliveroo connect — intégrations livraison
- **L6** SumUp ecosystem (puisque on l'utilise) — features cross-vendor

## §5.4 — Batch B.4 : 30 inspirations spécifiques

Researchers WebSearch pour features spécifiques POS-restauration 2026 :
- "Best POS features restaurant 2026"
- "Restaurant kiosk UX patterns 2026"
- "Kitchen display system features 2026"
- "POS undo refund cancel workflow"
- "Restaurant inventory alert system"
- "Customer order history loyalty"
- ... etc.

**Single-message dispatch ~30 micro-research agents.** Chacun rapporte 3-5 features inspirantes.

---

# §6 PHASE C — TRIAGE + OWNER DECISIONS NEEDED (3 agents)

Après ~85 agents Phase B, tu as probablement ~100-200 gaps identifiés. **Tu ne peux pas tout coder.** Tu tries :

## C.1 — Aggregation + dedup

Agent agrège tous les JSONs Batch B.1-B.4. Dédupe (un même gap remonté par plusieurs personas). Output : `reports/gap-hunt-2026-05-25/MASTER_GAP_LIST.json` avec ~50-80 gaps uniques.

## C.2 — Scoring matrix

Agent score chaque gap selon :
- **Value to owner** : 1-5 (BLOCKER-DAILY=5, NICE=1)
- **Effort** : XS(0.5)/S(1)/M(2)/L(4)/XL(8) jours
- **Risk if absent** : NF525/data/UX
- **Frozen-zone touch needed** : oui/non
- **V1 ship-blocker** : oui/non
- **Owner-mentioned explicit** : oui/non (KDS archive undo = OUI)

Output : `reports/gap-hunt-2026-05-25/SCORING_MATRIX.md` avec top-30.

## C.3 — Owner decision page

Agent génère **page web visuelle** (style goal.html) à `public/gap-decisions-2026-05-25.html` :
- Top 30 gaps présentés cards
- Pour chaque : titre + user story + impact + effort + recommandation Claude
- Owner click ✓/✗/Defer pour chaque
- LocalStorage persist
- Bouton "Envoyer plan validé" → résumé pour copier-coller

---

# §7 PHASE D — ULTRA AUDIT TECHNIQUE PROFOND (50 agents)

En PARALLÈLE de Phase C (pendant que owner décide) :

## D.1-D.49 : 7 systèmes × 7 spécialistes (V/U/T/S/A/Y/X)

**Discipline plus serrée que cycle précédent** — chaque spécialiste avec instruction :
- 12 états captures minimum (vs 8 précédent)
- Audit AVANT + APRÈS chaque correction (DM2)
- Adversarial dispute inline (DM5)
- Multi-persona reasoning (chef/client/caissier/owner)
- Production-real scenarios (rush, fatigue, distraction)
- **Trouve ce que les 213 agents précédents ont raté**

## D.50 : Adversarial meta

Agent lit TOUTES les findings D.1-D.49 + Phase B gaps + dispute ce qui semble surdimensionné ou sous-dimensionné. Filtre intelligente.

---

# §8 PHASE E — HEAL WAVE APPLY (N agents selon owner Phase C)

Pour chaque gap owner-approved dans Phase C + chaque finding D.1-D.50 healable :

- 1 agent par cluster (gap similaires bundlés)
- Scope-minimal ≤30 LOC FR-only sur non-frozen
- Frozen-zone touch → écriture PROPOSAL only
- Bundle rebuild si Vue source touché
- Sentinel test ajouté par fix

Commit pattern : `feat(gap-fix): GAP-XXX-NNN — <user story>`

---

# §9 PHASE F — BEFORE/AFTER DIFF AUDIT PER HEAL (3N agents)

Pour CHAQUE correction de Phase E : 3 agents diff (visual + technical + sync).

Output `reports/diff-audits/GAP-XXX-NNN-verdict.json` :
- CLEAN-FIX
- UNINTENDED-DELTA-LOW
- UNINTENDED-DELTA-HIGH (revert + investigate)
- REGRESSION (revert immédiat)

---

# §10 PHASE G — /test-e2e MASSIVE FINAL (~30 agents)

## G.1-G.21 : 7 systèmes × 3 personas
Pour chaque système, 3 personas qui testent en LIVE avec Playwright MCP. Capture quartet + multimodal analysis + adversarial dispute.

## G.22-G.31 : 10 production scenarios (DM4)
- KDS layout 5+ orders no-scroll (verify Option A appliquée)
- Long order 15 items chef visible
- Allergen alert prominent
- Network drop 30s recovery
- Multi-borne 30 concurrent
- Payment failed mid-flow
- Cashier 8h shift fatigue
- Owner 23h anomaly spot
- NF525 chain stress
- Customer 8 sauces tacos

## Chaîne complète 10x consécutives

Test end-to-end :
1. Borne place commande complète
2. Caisse encaisse
3. KDS bumpe
4. OSS affiche
5. Cash Overview compte
6. Reset
7. Répète 9 fois plus

**À chaque cycle** : NF525 chain bit-identical verify + zero error console.

---

# §11 PHASE H — SYNTHÈSE FINALE (3 agents)

## H.1 — Mega report

`reports/feature-gap-hunt-2026-05-25/FINAL_REPORT.md` :
- Phase A ops gates appliqués
- Phase B nombre gaps trouvés + classification
- Phase C owner decisions
- Phase D 50 audit findings
- Phase E heals shipped
- Phase F diff verdicts
- Phase G test-e2e convergence
- NF525 chain bit-identical proof
- Frozen-zone diff = 0
- New owner-gate items

## H.2 — BRAIN update

`PROJECT_BRAIN.md` §2 §3 §4 honest update.

## H.3 — Graphiti episode push

`group_id=foodking` épisode "Feature Gap Hunt 2026-05-25" avec personas + ~N gaps trouvés + heals shipped.

---

# §12 CONVERGENCE LOOP ABSOLUE

```
ROUND 1:
  Phase A → B → C → D → E → F → G
  Aggregate Phase G findings
  
  IF open_P0=0 AND open_P1=0 AND open_BLOCKER-DAILY=0 :
    → ROUND 2 confirming
  ELSE :
    → fix-wave + ROUND 2

ROUND 2:
  Re-dispatch Phase D+G uniquement (audit + test)
  IF identical findings set + GREEN :
    → CONVERGED. Phase H synthesis.
  ELSE :
    → ROUND 3

ROUND 3 (max):
  STOP + surface owner si non-converged.
```

**Owner critère** : return ONLY si converged 100%. Sinon loop. Pas de timidité.

---

# §13 ANTI-PATTERNS (RÉPÉTÉS — 16 erreurs)

1. ❌ Sequential dispatch — toujours single-message parallèle par batch
2. ❌ Commit dirty bundles sans rebuild si Vue touchée
3. ❌ Toucher frozen-zone sans LOCK countersigné
4. ❌ Force-push ou merge to main
5. ❌ Claim convergence après 1 round
6. ❌ Update BRAIN prématurément
7. ❌ Silence symptoms — root-cause obligatoire
8. ❌ Skip §0 bootstrap
9. ❌ Bypass safety-check.sh
10. ❌ Cloud deploy actif (Phase A-H all on-disk only)
11. ❌ Auto-fix frozen-zone (PROPOSAL only)
12. ❌ Skip persona consensus
13. ❌ Skip before/after diff
14. ❌ Skip production-real scenarios
15. ❌ **NEW** : Skip feature gap hunt (Phase B obligatoire — pas juste audit code existant)
16. ❌ **NEW** : Sur-classifier gaps en CRITICAL (réserve CRITICAL aux true ship blockers + BLOCKER-DAILY persona-validated)

---

# §14 OUTPUT EXPECTED

- ~180-220 sub-agents dispatched cumul
- ~50-80 unique feature gaps identifiés
- ~10-30 gaps applied (scope-minimal selon owner Phase C)
- 10-30 frozen-zone PROPOSALS écrits (owner-gate queue)
- ~200-400 quartet captures
- 0 frozen-zone diff (sauf LOCK countersigné)
- NF525 chain bit-identical
- BRAIN updated
- Graphiti episode pushed
- Page owner decisions live

---

# §15 OWNER VERBATIM 2026-05-25 (à conserver)

> « Quand je vois là maintenant l'écran de cuisine, je peux pas y accéder aux archives parce que je peux par exemple avoir fait valider une commande par erreur avec rapidité, je vais revenir pour la ou bien je vais revenir pour quelque chose et là je devrais avoir une botte de voilà de voir l'archive, l'archi va être en ordre comité live en ordre pour fait sept même l'archive. Et là cette fonction si je la vois pas et peut-être d'autres fonctions qui doivent être présente et ils sont pas là pareil pour la caisse, la borne et le système global faudra vraiment être édité avec intelligence et donne-moi demande précise pour couvrir ces points et abuser les autres. Ça veut dire l'autre que je vais la demander dedans pour faire les corrections demander en tant que superviseur toi, tu donnes les ordres vraiment ultra déterminé avec des taches hyper lourdes pour finir avec le maximum d'intelligence et déployer le maximum d'agents spécialisé gstack superpowers adversal donne-moi les demandes et le goal si tu veux dire par ça pour l'atteindre et finir par des test-e2e capture d'écran, analyse profond et raisonnement, profonds et corrections, profondes et des test technique, logique et interfaces et visuel et prendre la place d'utilisateur soit ici ou bien autre le plus important. Si de couvrir tous les points ils corriger au maximum avec toutes les captures d'écran comme preuve et les analyser. »

**Traduction structurée** :
1. KDS Archives undo = exemple concret de feature manquante
2. Probablement d'autres features manquantes inconnues
3. Couvrir TOUS les systèmes (Borne / Caisse / Cuisine / OSS / Admin / Stock / Cash)
4. ULTRA déterminé, tâches hyper lourdes
5. Max agents spécialisés GStack + Superpowers + Adversarial
6. /test-e2e + captures + analyse profond + raisonnement profond
7. Corrections profondes
8. Tests technique + logique + interface + visuel
9. Multi-persona reasoning
10. Captures comme preuves
11. Couvrir tous les points
12. Corriger au maximum

---

# §16 GO — ACTION SEQUENCE

1. Bootstrap §0 (20-25 min)
2. Acknowledge superviseur en 5 lignes
3. Wait "GO" explicite owner
4. **Phase A** : 3 agents parallèle (ops gates Super.4)
5. **Phase B** : 4 sous-batches en série, chaque batch single-message :
   - B.1 : 42 agents (7×6 personas gap hunt)
   - B.2 : 8 cross-system agents
   - B.3 : 6 inspirations leaders
   - B.4 : 30 micro-research agents
6. Aggregate gaps + Phase C 3 agents triage
7. Surface owner gap-decisions page
8. PARALLEL avec C : Phase D 50 agents ultra audit
9. Wait owner Phase C decisions
10. **Phase E** : N agents heal wave
11. **Phase F** : 3N before/after diff
12. **Phase G** : ~30 /test-e2e MASSIVE
13. Convergence check
14. **Phase H** : 3 agents synthèse

---

**Cette mission est la dernière avant que le système soit poussé en production réelle. Pas de marge d'erreur. Tu dois trouver TOUT ce qui manque + corriger TOUT ce qui est fixable. Reviens uniquement avec 100% validé.**

**Fin du prompt. Lance le GAP HUNT.**

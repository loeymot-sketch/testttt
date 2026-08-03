# GSTACK PIPELINE — Adaptation FoodKing 2026-05-08

> Adaptation FoodKing du pattern GSTACK (Garry Tan / Y Combinator, 86k stars en 7 semaines) :
> Pipeline 7-étapes Think→Plan→Build→Review→Test→Ship→Reflect + 6 rôles virtuels +
> "office-hours" STOP checklist avant chaque code.
>
> Référence externe : github.com/garrytan/gstack (23 skills + 8 power tools).
> Compatible Claude Code, OpenClaw, Codex, Cursor.

---

## 1. Pipeline 7 étapes (obligatoire pour toute feature/fix non-trivial)

| # | Étape | Rôle dominant | Livrable |
|---|---|---|---|
| 1 | **Think** | CEO / PM | hypothèse + critère succès + scope minimal explicite |
| 2 | **Plan** | Eng Manager / Architect | plan détaillé file:line, dépendances, risques, rollback strategy |
| 3 | **Build** | Implementer (POS/Kiosk/KDS/backend selon scope) | code scope-minimal, hors frozen-zone, ≤30 LOC inline-edit |
| 4 | **Review** | Eng Manager + Security + Designer | source-by-source check, RBAC/auth, UX cohérence DS V5/V1 Bold |
| 5 | **Test** | QA Validator | spec PHPUnit/Vitest/Playwright + sentinel anti-régression |
| 6 | **Ship** | Eng Manager | commit message structuré (INLINE-EDIT-EXCEPTION + scope + tests + justification) |
| 7 | **Reflect** | CEO / PM | post-mortem 1 ligne dans memory ou `reports/reflect/`, leçon apprise |

**Règle d'or** : ne pas sauter une étape. Si une étape échoue, retour à l'étape précédente jusqu'à résolution.

---

## 2. Office-Hours STOP — Checklist obligatoire avant CODE (étape Build)

> **YC playbook** : "Si tu peux pas répondre, tu codes pas."
>
> Adapter à FoodKing — 6 questions à répondre AVANT toute édition de fichier .php / .vue / .js / .ts / .blade.php.
> Si une seule question ne peut pas être répondue clairement, retour à étape Plan.

### Checklist STOP

1. **Mauvaise hypothèse de départ ?**
   - Le bug/feature est-il bien là où je le pense ? Ai-je vérifié `file:line` indépendamment ?
   - L'API/contrat existant est-il vraiment celui que je crois ?
   - **Anti-pattern FoodKing** : RED-R3 a hit un faux positif sur F1 outbox parce qu'il a sauté cette question — `BROADCAST_DRIVER=log` aurait répondu en 1 commande tinker.

2. **Sur-complexité inutile ?**
   - Cette feature/fix peut-elle être plus simple ? Ai-je évité la sur-abstraction ?
   - **Anti-pattern FoodKing** : RED-R5 §5.4 — sentinel structurel suffisant vs E2E coûteux pour KD5, choix scope-minimal.

3. **Edits orthogonaux ?**
   - Mon edit touche-t-il UNIQUEMENT le scope du Plan ? Ai-je rajouté du polishing non demandé ?
   - **Anti-pattern FoodKing** : CLAUDE.md §"Don't add features beyond what task requires".

4. **Impératif ou déclaratif ?**
   - Mon code décrit-il QUOI (déclaratif, ex: config flag) ou COMMENT (impératif, ex: chaîne de if/else) ? Préférer déclaratif quand possible.
   - **Anti-pattern FoodKing** : sentinel `paymentComponentPropMutation` aurait dû être déclaratif (liste explicite des emits autorisés) plutôt qu'une regex tolérante.

5. **Feedback loop en place ?**
   - Comment vais-je savoir que mon fix marche ? Spec phpunit/vitest/playwright EXISTE ou je vais l'écrire en même temps ?
   - **Anti-pattern FoodKing** : tout fix RED-validated DOIT avoir un sentinel anti-régression (cf. R4 KD5 → kdsNewOrderChimeIdBased.spec.js).

6. **Scope minimal défini ?**
   - Combien de LOC ? Combien de fichiers ? Si > 30 LOC ou > 3 fichiers → générer plan dédié pour Codex/Cursor (cf. memory `feedback_orchestrator_inline_edit_exception.md`).
   - Le scope est-il hors frozen-zone (cf. memory `reference_frozen_zones.md`) ?

**Application** : avant d'utiliser Edit/Write, mentalement répondre aux 6 questions. Si une seule réponse est "je ne sais pas" → retour Plan ou demande clarification user.

---

## 3. Six rôles virtuels FoodKing (mapping agents existants)

GSTACK propose 6 rôles : CEO, PM, Designer, Security, QA, Eng Manager.
Adaptation FoodKing :

| Rôle GSTACK | Mapping FoodKing | Outils principaux |
|---|---|---|
| **CEO** | User (toi) ou Claude orchestrator pour décisions stratégiques | Vision, priorisation, validation finale |
| **PM** | Claude orchestrator (planificateur) | Plans Codex, ACTIVE_CYCLE.md, gate doctrine |
| **Designer** | Agent `app-implementer` (DS V5 POS / V1 Bold Kiosk) | tokens-bold.css, design briefs, UX coherence |
| **Security** | Agent reviewer adversaire (RED team pattern R1-R5) | RBAC, idempotency, branch isolation, auth, OWASP |
| **QA** | Agent QA validator + Playwright/PHPUnit/Vitest | specs, sentinels, axe-core, runtime probes |
| **Eng Manager** | Claude orchestrator (review + commit) | Code review, scope discipline, INLINE-EDIT-EXCEPTION enforcement |

### Délégation pratique
- **Cartographie / recherche** → agent Explore (subagent_type=Explore)
- **Implémentation business non-trivial** → agent general-purpose avec prompt spécialisé "rôle Designer/Security/QA"
- **Audit adversaire** → agent general-purpose avec prompt RED team (R1-R5 pattern)
- **Synthèse finale** → agent general-purpose avec prompt "rôle CEO + Quality Director"
- **Edits scope-minimal (≤30 LOC)** → Claude orchestrator inline (memory `feedback_orchestrator_inline_edit_exception.md`)

---

## 4. Vérifications continues (entre chaque étape)

À la fin de chaque étape Build/Test/Ship, vérifier :

1. **Build OK** : `npm run dev -- --build` SUCCESS
2. **Sentinels JS** : `npx vitest run tests/js/sentinels/` 100% PASS (compte exact > pas de flaky)
3. **Spec validation** : si fix RED-validated, spec dédiée PASS runtime
4. **PHPUnit** : `php artisan test` couvrant le scope touché PASS
5. **Pas de régression** : aucun test pre-existant cassé
6. **Discipline frozen-zone** : `git diff --stat` confirme aucun fichier dans `reference_frozen_zones.md` modifié sans gate
7. **Commit message structuré** : `INLINE-EDIT-EXCEPTION: scope=Xlignes, tests=spec_path, justification=...`

---

## 5. Boucle d'exécution autonome (pour le mode auto)

```
LOOP:
  - Si task pending et pas blockedBy → start
  - Étape 1 Think (rappeler hypothèse + critère succès)
  - Étape 2 Plan (lire memory, frozen-zone check)
  - Étape 2bis STOP checklist (6 questions)
  - Étape 3 Build (scope-minimal, agents délégués si > 30 LOC)
  - Étape 4 Review (source check, security, design)
  - Étape 5 Test (spec + sentinels)
  - Étape 6 Ship (commit structuré + TaskUpdate completed)
  - Étape 7 Reflect (memory ou note 1-ligne)
  - Si erreur à toute étape → retour étape précédente, max 3 itérations puis ESCALATE user
ENDLOOP
```

---

## 6. Application immédiate — Cycle BYPASS payment/printing

Le plan bypass FoodKing (P0 cartographie → P6 runbook) est exécuté en suivant cette pipeline :

| Phase | Étape GSTACK | Rôle | Livrable |
|---|---|---|---|
| BYPASS-P0 | Plan + Think | Architect (agent Explore) | docs/audit/BYPASS_MAPPING_2026-05-08.md |
| BYPASS-P1 | Build (scope ≤30 LOC) | Eng Manager (Claude inline) | config/payment.php + config/printing.php + .env.example + sentinel prod-guard |
| BYPASS-P2 | Build (scope ~50-100 LOC) | Implementer (agent general-purpose) | PaymentService::charge() bypass branch préservant fiscal+Outbox+audit |
| BYPASS-P3 | Build (scope ≤30 LOC) | Implementer | PrinterService::print() bypass branch + marqueur écran "MODE TEST" |
| BYPASS-P4 | Test | QA (agent + Playwright) | tests/e2e/bypass-mode-end-to-end-flow-2026-05-08.spec.js |
| BYPASS-P5 | Review + Test | Security + QA | sentinels JS + PHPUnit anti-régression |
| BYPASS-P6 | Ship + Reflect | Eng Manager | docs/runbooks/BYPASS_MODE_OPERATIONAL.md + commit final |
| BYPASS-AUDIT | Review adversaire | RED team agent | verdict cycle adversaire post-implémentation |

---

## 7. Référence externe

- garrytan/gstack (GitHub) — 23 skills + 8 power tools, MIT, 86k stars
- "Si tu peux pas répondre, tu codes pas" — YC playbook (slide STOP checklist)
- The Agentic Dev — slide deck d'inspiration

---

## 8. Maintenance

- Mettre à jour ce document chaque fois qu'une nouvelle leçon est apprise (Reflect)
- Archiver les retours d'expérience dans `reports/reflect/YYYY-MM-DD-cycle-name.md`
- Mémoriser les patterns réussis dans `~/.claude/projects/.../memory/feedback_*.md`

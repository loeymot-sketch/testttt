# Repli audit / orchestration — terminal Claude indisponible ou **limite Anthropic atteinte**

> **But** : ne **pas** arrêter un cycle FoodKing parce que le CLI `claude` en terminal renvoie une erreur de **quota**, **rate limit**, **session / usage** saturé, ou toute autre **panne terminal** après la **1× retry** autorisée par `run-cycle.md` Step 5.

**PRIMARY inchangé** : `bash scripts/foodking-claude-orchestrate.sh` → `context` puis `audit-brief` ou `audit` (abonnement Anthropic, hors orchestrateur de modèles Cursor).

---

## Quand activer ce repli

Dès que **les deux** conditions suivantes sont vraies :

1. Le terminal a échoué (`exit != 0`) **après** une **seconde tentative** raisonnable (réseau / cold start), **ou** la sortie contient des indices explicites : `rate limit`, `429`, `quota`, `usage`, `billing`, `capacity`, `overload`, etc.
2. Tu dois **continuer** le cycle (AUDIT ou mini-audit) sans attendre la réinitialisation du quota Anthropic.

---

## Repli canonique (Cursor) — même logique d’orchestration

Invoquer le **Task** Cursor avec :

- **`subagent_type`** : **`foodking-planner-orchestrator`**
- **Consigne** : appliquer **exactement** la même checklist que le terminal aurait suivi — charger `.cursor/context/audit-context.md` (Step 5) ou, en phase PLAN, `.cursor/context/plan-context.md` ; lire `reports/audit/_TERMINAL_CONTEXT_BRIEF.md` si présent ; produire **`AUDIT_VERDICT: PASS`** ou **`REWORK`** dans le `REPORT_FILE` du cycle (même exigence binaire que le terminal).

Ce sub-agent est le **rôle orchestrateur / planificateur** du dépôt (voir `auto-remediation.mdc`) : il **n’implémente pas** le code produit ; il **tranche** et **documente** comme l’orchestrateur Claude en session, avec une **traçabilité différente** du canal terminal.

---

## Traces obligatoires (même règle que `AUDIT_FALLBACK` existant)

Dans le **`REPORT_FILE`** du cycle (et/ou append `reports/post_execute_latest.log` si c’est le SSOT du run) :

```
AUDIT_CHANNEL: cursor-session
AUDIT_FALLBACK_REASON: <une ligne — ex. anthropic_rate_limit_after_retry, quota_exceeded, terminal_auth_error>
AUDIT_SUBAGENT_FALLBACK: foodking-planner-orchestrator
```

- **`TERMINAL_AUDIT_OK`** : **ne pas** mettre `1` sur ce repli (réservé au canal terminal **réussi**).
- **`AUDIT_CHANNEL: cursor-session`** reste obligatoire pour distinguer facturation / canal (voir `AGENTS.md`, `run-cycle.md` Step 5).

---

## Ce que ce repli **ne** change pas

- **Raisonnement global** : `AGENTS.md`, `MEMORY_MATRIX.md`, `run-cycle.md`, `routing.md`, invariants — **inchangés**.
- **EXECUTE complexe** : toujours **`codex-extension`** PRIMARY ; sub-agent complexe = **fallback codex** uniquement.
- **Clôture** : toujours **`AUDIT_VERDICT: PASS`** requis ; pas de `CLOSED` sans verdict vert.

---

## Référence rapide

| Élément | Fichier |
|--------|---------|
| Canal audit PRIMARY / FALLBACK | `.cursor/commands/run-cycle.md` Step 5 |
| Table des rôles | `.cursor/routing.md` |
| Sub-agent orchestrateur | `foodking-planner-orchestrator` (Task Cursor) |

---
name: foodking-handoff
description: >-
  Charge le contexte FoodKing SaaS (Laravel + Vue) pour une nouvelle session Cursor sans historique de chat.
  Lit README, passation HANDOFF_NEW_CURSOR, cache mémoire, PROJECT_CONTINUITY, AGENTS.md et priorités backlog.
  Use when the user opens the FoodKing project on a new Cursor account, says "nouvelle session", "handoff",
  "sans mémoire", "autre compte Cursor", "reprendre le projet", or wants multi-agent orchestration aligned with AGENTS.md.
---

# FoodKing — session handoff & orchestration

## Quand appliquer ce skill

- Première ouverture du dépôt sur un **nouveau compte** ou machine.
- L’utilisateur veut **reprendre le développement** sans redonner tout le contexte à la main.
- Travail **multi-agents** selon **`AGENTS.md`** : cycle borné (TASK_ID, `run-cycle`), **`.cursor/routing.md`**, sub-agents Task **`foodking-planner-orchestrator`**, **`foodking-complex-implementer`**, **`foodking-routine-implementer`**.

## Ordre de lecture obligatoire (avant code ou plan important)

1. `README.md` (racine) — hub liens.
2. `docs/HANDOFF_NEW_CURSOR/00_INDEX.md` — navigation passation.
3. `docs/HANDOFF_NEW_CURSOR/CACHE_MEMOIRE_TRANSFERT.md` — état projet, synchro, backlog, invariants.
4. `docs/PROJECT_CONTINUITY_AND_VISION.md` — vision Le Cayenne, surfaces, correctifs à préserver.
5. `AGENTS.md` — workflow `reports/`, types de tests, rôles.
6. Si commandes / auth : `docs/ARCHITECTURE.md`, `docs/ORDER_FLOW.md`, `docs/API_MAP.md`, `docs/AUTHZ_MATRIX.md`.

## Règles déjà dans le repo (ne pas ignorer)

- `.cursor/rules/project-continuity.mdc` (alwaysApply).
- Petits diffs ; pas de contournement **recalcul prix serveur** ni **authz** cassée.

## Réponse attendue de l’agent

Après lecture, résumer en **≤15 lignes** : stack, surfaces (POS, KDS, OSS, kiosk), synchro (Echo + FCM + polling), 3 priorités backlog, et prochaine action demandée à l’utilisateur.

## Fichiers complémentaires utiles

- `docs/HANDOFF_NEW_CURSOR/PROMPT_DEMARRAGE_NOUVEAU_COMPTE.md` — prompt copier-coller premier chat.
- `docs/HANDOFF_NEW_CURSOR/ORCHESTRATION_FICHIERS_A_TRANSFERER.md` — checklist autre compte.
- `reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md` — phases A–E et diagrammes.

## Installation globale (optionnel, tous projets sur ce compte)

Copier ce dossier `foodking-handoff` vers `~/.cursor/skills/foodking-handoff/` pour invoquer le même skill hors workspace.

## Hygiène des rapports d'audit

Avant tout commit qui ajoute / modifie un rapport sous `reports/review/AUDIT_*.md`,
`reports/review/VERIFY_*.md` ou `reports/audit-orchestration/*.md`, exécuter :

```bash
bash scripts/check-audit-report-integrity.sh -v
```

Le script échoue si un rapport est < 200 octets (cas observé : un rapport
restauré depuis un swap vide). Référence : `F-VERIFY-10-02` (cf.
`reports/review/VERIFY_10_BRANCH_ISOLATION_2026-04-20.md`).

Optionnel : intégrer dans le pre-commit hook local de l'utilisateur. Pas
d'auto-installation versionnée pour ne pas écraser les hooks personnels.

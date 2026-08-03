# Playbook — Challenge **terminal** Codex (GPT) ↔ **terminal** Claude

**Objectif** : lancer un **débat structuré** (plan de dispute) entre **deux cerveaux** (Codex = implémente / raisonne sur l’existant ; Claude = orchestre / audite) jusqu’à un **rapport consolidé** (validations, erreurs, corrections, plan d’exécution V1) — **sans** remplacer le cycle `run-cycle` : c’est un **exercice d’audit et d’arbitrage** que tu lances toi-même en terminal.

**Règles d’or** (SSOT) :

- **Noms de rôles** (repo) : Codex = **GPT-5.5** via **CLI** `codex` + Pro ; Claude = `foodking-claude-orchestrate.sh` (défaut **Opus 4.7** + `effort high`).
- **Codex ne peut pas** invoquer Claude *en une seule commande magique* : tu enchaînes **toi** (ou un script) : sortie A → prompt B qui **lit A** sur disque.
- **Chacun a accès aux mêmes fichiers** : tu enregistres chaque ronde dans `reports/audit/` (chemins explicites ci-dessous) ; les invites suivantes **exigent** la lecture de ces chemins (via `--add-dir` / workspace).

**Date à fixer** : remplace `YYYY-MM-DD` partout, ou `export CHALLENGE_DATE=2026-04-25`.

---

## 0. Pré-requis (depuis la racine du dépôt)

```bash
cd /chemin/vers/foodking-web/web/testttt
npm run verify:boucle
command -v codex && command -v claude
# Codex : npm install  puis codex login (Sign in with ChatGPT Pro) si besoin
# Claude  : extension Claude Code ou claude on PATH
bash scripts/foodking-claude-orchestrate.sh check
```

Fichier de suivi (à créer au fil des tours) :  
`reports/audit/CHALLENGE_MANIFEST_YYYY-MM-DD.md` (copier/coller les commandes exécutées + heures + chemins de sorties).

---

## 1. Tour R1 — **Codex** (prompt « pro » à coller)

**Sortie cible** : `reports/audit/CHALLENGE_CODEX_R1_YYYY-MM-DD.md`

**Commande (recommandé : prompt long dans un fichier)** :

```bash
export CHALLENGE_DATE=2026-04-25   # adapter
# Option A : fichier dédié (évite l’enfer des quotes)
codex exec "$(cat docs/orchestration/challenge-prompts/CHALLENGE_CODEX_R1_PROMPT.md)" --add-dir . 2>&1 | tee "reports/audit/CHALLENGE_CODEX_R1_${CHALLENGE_DATE}.md"
# Si `codex exec` est inconnu : `codex help` (version CLI) — souvent le sous-commande est `exec` ; sinon lancement interactif avec le même texte en prompt initial.
# Si --add-dir n'existe pas : lance quand même depuis la **racine** du dépôt.
```

> Variante : `npx @openai/codex@latest exec "…" --add-dir .` (même rôle) si le binaire global manque.

**Après** : copie la **réponse** du terminal dans `reports/audit/CHALLENGE_CODEX_R1_YYYY-MM-DD.md` (ou indique à Codex dans le prompt d’**écrire** ce fichier s’il a l’outil — sinon tu colles toi-même. Le plus fiable côté repo = **t’assurer d’enregistrer** la sortie en fichier dès la fin de la manche).

**Rediriger la sortie vers le fichier d’un coup** (simple) :

```bash
codex exec "$(cat docs/orchestration/challenge-prompts/CHALLENGE_CODEX_R1_PROMPT.md)" --add-dir . 2>&1 | tee reports/audit/CHALLENGE_CODEX_R1_2026-04-25.md
```

(Ajuste la date.)

---

## 2. Tour R2 — **Claude (terminal)** lit R1 et **contre-attaque** / arbitre

**Sortie cible** : `reports/audit/CHALLENGE_CLAUDE_R2_YYYY-MM-DD.md`

Génère d’abord un **bref** cycle (utile) :

```bash
bash scripts/foodking-claude-orchestrate.sh context
```

Puis **audit ciblé** (remplace `YYYY-MM-DD` par la même date que R1) :

```bash
bash scripts/foodking-claude-orchestrate.sh audit "$(cat <<'EOF'
Tu es l’orchestrateur FoodKing (AGENTS.md). Tu n’imposes **pas** de code — tu tranches, tu contestes, tu complètes.
Lis d’abord (outils) :
- reports/audit/CHALLENGE_CODEX_R1_YYYY-MM-DD.md
- et au choix 1..N références SSOT d’audit déjà en dépôt (ex. reports/audit/SIM_MASTERPLAY_FINAL_CONSOLIDATED_*.md, AGENTS.md, docs/orchestration/GLOBAL_SYSTEM_PRIMER.md).

Tâche (français, structuré) :
1) **SECTION A — D’accord** : 5–10 puces max où l’analyse Codex R1 est solide.
2) **SECTION B — Contestation** : 5–15 puces : erreurs, angles morts, invariants manquants (prix, branch_id, after-commit, OrderStatus, frozen zones), ou preuves insuffisantes.
3) **SECTION C — Priorisation** : tableau P0 / P1 / P2 pour atteindre un **V1 fonctionnel** (définition explicite en 2 phrases).
4) **SECTION D — Décision d’orchestrateur** : 1 seule ligne `CHALLENGE_VERDICT: MERGE_CODEX | PREFER_CLAUDE | REBUT_ALL | SPLIT` + justification.
Écris une longueur dense ; cite chemins:line quand c’est le code.
EOF
)" 2>&1 | tee reports/audit/CHALLENGE_CLAUDE_R2_YYYY-MM-DD.md
```

*(N’oublie pas d’**éditer** `YYYY-MM-DD` pour que le path pointe vraiment vers le fichier R1 généré.)*

> Le script ajoute déjà **Opus 4.7** + `effort high` aux appels `claude -p`. Pas besoin d’iCloud : tout est local.

---

## 3. Tour R3 — **Codex** lit R2 et **réplique** (synthèse de dispute)

**Sortie cible** : `reports/audit/CHALLENGE_CODEX_R3_YYYY-MM-DD.md`

Fichier prompt : `docs/orchestration/challenge-prompts/CHALLENGE_CODEX_R3_REPLIQUE_PROMPT.md` (fourni dans le dépôt). Remplace la date partout (placeholder `[CHALLENGE_DATE]`) :

```bash
export CHALLENGE_DATE=2026-04-25
R3P=$(sed "s/\\[CHALLENGE_DATE\\]/${CHALLENGE_DATE}/g" docs/orchestration/challenge-prompts/CHALLENGE_CODEX_R3_REPLIQUE_PROMPT.md)
codex exec "$R3P" --add-dir . 2>&1 | tee "reports/audit/CHALLENGE_CODEX_R3_${CHALLENGE_DATE}.md"
```

**Contenu attendu de R3** (à tenir par le prompt) : réponse point par point à SECTION B, admission des ratés, rejet des arguments erronés, **liste unique** des **actions** à faire pour V1, risques, tests proposés.

---

## 4. Tour R4 (fin) — **Claude** : **rapport hyper consolidé**

**Sortie cible** (rapport de référence) : `reports/audit/CHALLENGE_RAPPORT_FINAL_CONSOLIDE_YYYY-MM-DD.md`

```bash
bash scripts/foodking-claude-orchestrate.sh context
bash scripts/foodking-claude-orchestrate.sh audit "$(cat <<'EOF'
Orchestrateur final — consolide la dispute.
Lis (ordre) :
- reports/audit/CHALLENGE_CODEX_R1_YYYY-MM-DD.md
- reports/audit/CHALLENGE_CLAUDE_R2_YYYY-MM-DD.md
- reports/audit/CHALLENGE_CODEX_R3_YYYY-MM-DD.md
Synthèse unique en français, sans narratif inutile :

1) Tableau : **thème | validé (qui) | contesté (qui) | tranché (toi)**.
2) Liste **P0** (ordre) pour **V1 fonctionnelle** (backend + 3 surfaces si scope).
3) Liste **P1 / P2**.
4) **Faux positifs** écartés.
5) **Risque résiduel** + preuve attendue (test / doc).
6) Dernière section : `CONSOLIDATED_VERDICT: READY_TO_PLAN | NEEDS_EVIDENCE | HUMAN_SPLIT`

Écris comme le rapport d’audience final — intelligence max, pas de clôture de gate humain à notre place.
EOF
)" 2>&1 | tee reports/audit/CHALLENGE_RAPPORT_FINAL_CONSOLIDE_YYYY-MM-DD.md
```

**Ce fichier** = ce que tu voulais comme *« plan d’intelligence max »* + *« dispute »* *matérialisée* par les lectures croisées.

---

## 5. Comment **Codex « accède » à Claude** (réponse claire)


| Fait                       | Explication                                                                                                                                             |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Un seul process**        | N’existe **pas** en standard : le CLI `codex` n’appelle **pas** `claude` seul.                                                                          |
| **Méthode retenue**        | **Fichiers partagés** : tu écris R1, puis tu lances **Claude** avec un prompt `audit` qui **cité le chemin** de R1. Idem R3.                            |
| **Même ordinateur**        | Tout se passe en **local** (tee → markdown). Aucun cloud orchestrateur FoodKing.                                                                        |
| **Même règle que le repo** | Décision finale **Claude** sur l’*arbitrage de cycle* ; ici, **R4 = synthèse d’orchestrateur** que tu peux coller en entrée d’un vrai `TASK_ID` / plan. |


---

## 6. Raccourci (optionnel) — enchaîner 4 coups d’un seul

Tu peux concaténer les 4 blocs `bash` dans un script perso **hors** repo (`~/bin/fk-challenge.sh`) : garde le **même** `CHALLENGE_DATE` et l’ouverture humaine entre R1 fin et R2 si les fichiers n’existent pas encore.  
Le dépôt n’impose **pas** ce script pour ne pas lancer 200+ secondes d’API sans te le dire.

---

## 7. Lien Command Deck

Ajout dans `docs/orchestration/COMMAND_DECK.md` (index) : voir **Challenge Codex / Claude (terminal)** → ce fichier.

**Objectif V1** : le **rapport final** sert d’**entrée** `## PRIOR_CONTEXT` ou de section « Audit externe » dans un `plans/PLAN_*_*.md` réel — pas de doublon avec le cycle actif si `ACTIVE_CYCLE` ne le pointe pas.

---

*Playbook 2026 — symétrie avec `docs/orchestration/CODEX_API_DELEGATION.md` (Codex) et `AGENTS.md` (Claude = décideur, Codex = impl + self-audit sur les cycles produit). La dispute ici = **hors** cycle ou **pré**-cycle.*
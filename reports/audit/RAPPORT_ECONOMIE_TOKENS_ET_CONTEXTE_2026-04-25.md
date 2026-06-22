# Rapport — **Économie de tokens / contexte** : perception vs traces dans le dépôt

**Date** : 2026-04-25  
**Demande** : Comprendre pourquoi un ressenti « **avant** = contexte/ cache / token optimisés, modèles forts (Opus) abordables ; **maintenant** = chaque tâche en Opus / haut de gamme **coûte beaucoup**, alors que **Graphiti MCP** fonctionne.

**Méthode** : relecture des règles `*.mdc`, de `AGENTS.md`, de `docs/orchestration/*` (dont Primer, TERMINAL_CLAUDE, MEMORY_MATRIX), des scripts `foodking-claude-orchestrate.sh`, et de rapports d’exécution récents — **aucune** conclusion sur le pricing Anthropic en dehors du ressenti (tarifs côté fournisseur = hors dépôt).

---

## 1) Ce qui **n’est pas** un « optimiseur de crédit Opus magique »

| Idée reçue | Clarification (SSOT du dépôt) |
|------------|--------------------------------|
| **Graphiti** « réduit la facture Opus » | **Non comme mécanisme direct.** `MULTI_AGENT_ORCHESTRATION.md` et `graphiti-memory.mdc` : Graphiti sert surtout la **mémoire durable** (faits, invariants) pour **ne pas recharger** toute l’histoire en rouvrant des centaines de fichiers ou rapports. Cela **réduit le bruit** dans **la fenêtre de contexte d’une session** (Cursor) et le travail *humain* — **pas** l’inscription d’un forfait Anthropic moins cher par appel `claude` terminal. |
| Un **module logiciel** « token reducer » dans le code PHP/Vue | **Aucun** trace d’un moteur « application » nommé *cache/tokens* pour l’orchestrateur. La « matrice tokens » = **règles d’habitude** (fichiers, Graphiti, briefs) — pas un service Redis *LLM-optimizer*. |
| L’**économie d’abonnement** (tableau modèles dans `AGENTS.md`) | Signifiait **où** tourne le travail (Cursor vs `codex` Pro vs `claude` sur **ton** compte) pour **le bon canal payeur** — **pas** un multiplicateur qui divise la **taille** des requêtes. |

**Conclusion §1** : mélanger **(a) mémoire/Procédure** (moins de relectures) et **(b) facture API** (prix / modèle / taille de prompt) explique beaucoup de la confusion ressentie.

---

## 2) Traces d’un **système d’économie** — **toujours présent**, mais c’est de la **discipline**, pas un cache opaque

Fichiers encore **actifs** (extraits) :

- **`.cursor/rules/context-hygiene.mdc`** — *no-reload* des fichiers déjà en contexte, phases PLAN/EXECUTE/VALIDATE/AUDIT = **bornes** de chargement, pas « tout le repo à chaque fois ».
- **`.cursor/rules/global.mdc` § Token Discipline** : **« quality-first — zero *negative* optimization »** : il est **interdit** de *triturer* plans/invariants *pour* économiser des tokens (volonté explicite *anti*-réduction bête de la matière).
- **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` §5** — table « on optimise (effet ≥0) / on n’optimise pas » : les gains autorisés = **éviter relectures inutiles**, handoff par **résumé de phase** (après phase *terminée*), **Graphiti** à la place de 50 rapports, **jamais** vider `## PRIOR_CONTEXT` pour aller *vite*.
- **`docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md`** : le **terminal** `claude` **n’avait pas** d’MCP Graphiti *live* — on lit le **fichier** `reports/audit/_TERMINAL_CONTEXT_BRIEF.md` (généré par `... context`) pour **maîtriser** les tokens **avant** un `audit` coûteux.
- **`scripts/foodking-claude-orchestrate.sh`** (commentaire + implémentation) : `context` = **alimentation disque** (ACTIVE_CYCLE + bribes JSONL + `memory/INDEX.md`) pour ne pas re-coller 100 % du chat.
- **`.cursor/rules/cross-agent-sync.mdc`** : `agent-activity-log.sh tail 50` ≈ **&lt;2 kB** consciemment ciblé.

**Conclusion §2** : le « **système d’économie** » **n’a pas disparu** ; c’est un **pack de règles + outils (brief, Graphiti, handoff)**. S’il est **bypassé** (gros coller de logs, re-lire 10 fois le même plan, lancer `audit` long sans `context` d’abord, enchaîner beaucoup de manches **Opus** en plein prompt de 50 k caractères), le **même** dépôt *semble* « cassé » côté coût, alors qu’on a changé l’*usage*.

---

## 3) Pourquoi la facture **Opus / haut de gamme** a pu **monter** (causes plausibles, côté *usage* + côté *dépôt*)

| Cause | Détails |
|-------|--------|
| **A) Changement d’habitude** | Avant : peut-être moins d’appels, ou modèle **plus léger** pour brouillons, **Opus** seulement pour l’audit final. Aujourd’hui : *chaque* question en **Opus 4.7** + gros `effort` = beaucoup plus d’**entrée/sortie** comptés par Anthropic. Le dépôt a même **normalisé** l’**Opus 4.7** + `effort high` sur le wrapper terminal (`FOODKING_CLAUDE_TERMINAL_*`) — c’est le **raisonnement max** pour l’orchestrateur, **au prix** d’un coût unitaire plus élevé. |
| **B) Règle *quality-first*** | L’équipe documentaire a **défendu** : pas de baisse de *substance* pour *économie* négative (`global.mdc`, Primer). Donc moins d’*astuces* de « tronquage de plan permis par politique interne — à bon escient, ça *augmente* l’exposition moyenne aux gros modèles si l’on suit la lettre. |
| **C) Parallélisme / multi-agents** | Plus d’**onglets** Cursor + `codex` + `claude` terminal = **plus d’** appels, même avec une *bonne* matrice. |
| **D) Graphiti** | Réduit *redondance cognitive* (et tokens **de session** *si* l’on interroge Graphiti au lieu de recharger 40 fichiers) ; **n’**empêche** pas* un *nouveau* long `claude "audit 50 pages"`. |
| **E) Côté fournisseur (hors contrôle du repo)** | Éventuelle évolution des **prix** / forfaits / compteurs côté **Anthropic** ou **OpenAI** — le dépôt n’a **aucun** drapeau pour cela. |

**Conclusion §3** : la **hausse ressentie** est en général un **mélange** (plus d’Opus **full**, plus de tâches, moins d’*habillage* de brief, règles *anti* réduction bête) — pas la **suppression d’un binaire* « cache optimizer* » in‑repo.

---

## 4) Résultat « **statistiques** »

Le dépôt ne contient **pas** d’*instrumentation* centralisée type dashboard « k€ / mois Opus ». Les preuves sont **narratives** (rapports `RUN_*.md`, T-PARCOURS-OPTIMIZE avec ordre d’~10k tokens gagné sur **le parcours de lecture AGENTS** — c’est *contexte d’onboarding*, pas *facture API*). **Donc** on ne peut pas trancher ici *« l’économie ne fonctionne plus chiffré à X% »* sans telex **usage Anthropic** externe (dashboard compte, facture).

---

## 5) Recommandations (sans gate, purement pratique)

1. **Continuer** `context` **avant** chaque gros `audit` / `audit-brief` (brief disque, pas 50 pages collées en prompt).
2. Réservé **Opus 4.7** aux étapes **PLAN lourd, AUDIT final, arbitre** — brouillons : modèle moins gourmand si l’on accepte, ou *pas* sur `--effort high` (variable `FOODKING_CLAUDE_TERMINAL_EFFORT=` pour retirer `--effort` — voir script).
3. **Graphiti** dès tâche non triviale : **une** requête `search_memory_facts` (group `foodking`) avant de re-parser dix audits.
4. Rester dans les **per-phase** loads de `context-hygiene.mdc` (éviter d’ouvrir `app/*` en phase PLAN, etc.).

---

## 6) Synthèse en une phrase

> **Aucun « optimiseur de crédit Opus » caché n’a été supprimé du code** ; l’**économie** documentée est **de discipline + mémoire (Graphiti, briefs, matrice)**, tandis que l’**usage** récent (Opus haute intensité, plus d’appels) et la **politique** *quality-first* expliquent presque toute l’impression d’inflation, **sauf** chiffre fournisseur côté Anthropic.

---

*Rapport interne — pas une décision de gate, pas de modification de règles requise.*

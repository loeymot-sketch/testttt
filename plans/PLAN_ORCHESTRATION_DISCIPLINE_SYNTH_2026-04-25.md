# Plan — Discipline d’orchestration & anti-dérive (synthèse « deux avis » + exécution maître)

**Date** : 2026-04-25  
**TASK_ID** : `ORCH-DISCIPLINE-2026-04-25`  
**PRIMARY_MODEL** : `cursor-claude` (orchestrateur / auteur du plan — **applicateur** des changements doc & règles ; **pas** de remplacement de l’audit terminal humain/modèle pour `AUDIT_VERDICT`)

---

## Méthode (transparence)

**Tu as demandé** deux avis : *Claude (rigueur gouvernance)* et *GPT-5.5 pro (réduction friction systèmes)*, puis fusion et application par « Claude maître ».

**Dans cette session (v2 — réelle)** : un **vrai** second avis a été obtenu via `node_modules/.bin/codex exec` (compte ChatGPT Pro, modèle GPT-5.5 pro) sur la mission `missions/ORCH-DISCIPLINE-2026-04-25/review_v1.md`. Sa réponse intégrale est en transcript de session (commit `reports/audit/`) et a corrigé v1 sur 3 points majeurs (voir « Évolution v1 → v2 » plus bas).

| Axe | Rôle | Ce qu’il optimise |
|-----|------|-------------------|
| **Lens A — Gouvernance / audit (Claude maître)** | Non‑négociables, SSOT, gates, invariants, traçabilité | Zéro contournement de `run-cycle`, `AUDIT_VERDICT`, `MEMORY_MATRIX` |
| **Lens B — Systèmes / réduction de friction (GPT-5.5 pro via `codex exec`)** | Même discipline avec **moins d’oubli** : checklists, garde-fous exécutables, scope-aware | Moins de répétition humaine, refus mécanique des oublis |

**Fusion** : les deux axes **s’accordent** : la qualité production vient de **fichiers + procédure + garde-fous exécutables**, pas d’un cache LLM partagé entre onglets (qui n'existe pas par design).

---

## Évolution v1 → v2 (corrections issues du second avis GPT-5.5 pro)

| # | v1 (mes constats seuls) | Correction v2 (après GPT-5.5) | Pourquoi |
|---|-------------------------|-------------------------------|----------|
| 1 | `preflight-execute` checke `EXECUTE_DELEGATION:` du log précédent | **Déplacé en `post-execute-guard.sh`** | Délégation = preuve de SORTIE EXECUTE, pas précondition |
| 2 | `preflight` checke uniquement `<TASK_ID>` | **Scope-aware : `--scope="csv"`** comparé à scope réservé | Le scope est la vraie unité de collision |
| 3 | `agent-activity-log start` lit/écrit séquentiel | **`flock` atomique cross-process** | 2 starts simultanés peuvent se croiser |
| 4 | Pas de bypass formalisé | **`--override="raison"` journalisé** + modes `product\|governance\|read-only` | Bypass humain explicite > bypass silencieux |
| 5 | `COMMAND_DECK.md` peut afficher état | **INDEX strict** (liens + commandes), zéro état | Sinon devient un store D bis (anti-pattern) |
| 6 | `post-execute-guard` absent | **Vérifie `git status` ⊆ scope réservé** avant VALIDATE | Garantit que ce qui a été modifié = ce qui a été réservé |

---

## Problème constaté (symptômes)

- L’orchestrateur **réexplique** la boucle parce que le **chat n’est pas la SSOT** et les agents ne **rechargent** pas toujours `ACTIVE_CYCLE` / `run-cycle` **avant** d’agir.
- **Désalignement** possible entre deux conversations : pas de cerveau unique — seulement **dépôt + log + Graphiti** (`MULTI_AGENT_ORCHESTRATION.md`).
- **Attente irréaliste** : « même mémoire de contexte » entre sessions = **non** par design ; la matrice A/B/C/D est la réponse.

---

## INVARIANTS_AT_RISK

- Aucun contournement de `AUDIT` avant `CLOSE` ; pas d’`EXECUTE` sans `EXECUTE_DELEGATION` tracée quand le code produit change.
- Pas de nouveau « store mémoire » externe (claude-mem, etc.) sans gate `GATE_MEMORY_*` (`MEMORY_MATRIX.md`).

---

## SUBSYSTEMS_TOUCHED (ce plan)

| Sous-système | R/W | Notes |
|--------------|-----|--------|
| `docs/orchestration/*.md` | W | Primer + nouveau fichier d’ouverture de session |
| `plans/PLAN_ORCHESTRATION_DISCIPLINE_SYNTH_2026-04-25.md` | W | Ce fichier (SSOT du chantier discipline) |
| `AGENTS.md` | R | Référence seulement (éviter duplication ; renvoi vers le bloc session) |
| Code produit `app/`, `resources/` | **Rien** | Hors scope — ce plan est **gouvernance** |

## SUBSYSTEMS_OFF_LIMITS

- Pricing, auth, schéma DB, frozen zones, logique OrderService.

## GATE_CONDITIONS

- Toute intégration d’outil tiers type *squad* / *claude-mem* → **gate** explicite (non demandé ici).

---

## Synthèse Lens A (gouvernance)

1. **Une vérité** : `AGENTS.md` + `run-cycle.md` + `ACTIVE_CYCLE.md` + `plans/PLAN_*` pour le cycle actif.  
2. **Jamais** « on a discuté donc c’est décidé » — **écrit** dans D ou B selon la matrice.  
3. **Parallèle** = `AGENT_ACTIVITY_LOG` **avant** `start` ; **Graphiti** en lecture au démarrage si MCP.  
4. **Clôture** = `AUDIT_VERDICT: PASS` (canal terminal sauf fallback documenté).

## Synthèse Lens B (opérations)

1. **Un seul bloc** copiable par session : `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md` (créé avec ce plan).  
2. **`npm run verify:boucle`** toujours **avant** un cycle critique (déjà dans `run-cycle` Step 0 item 8).  
3. **Humain** : coller le bloc en premier message d’un nouvel onglet = coût ~1 min, gain = plus de **malentendus**.

## Plan fusionné v2 — livrables

| ID | Livrable | Statut | Objectif |
|----|----------|--------|----------|
| P0 | `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md` | ✅ FAIT | Anti-répétition : ordre minimal en une page |
| P0 | Ligne dans `GLOBAL_SYSTEM_PRIMER.md` §1 + `AGENTS.md` bootstrap | ✅ FAIT | Découvrabilité du bloc |
| P0 | `scripts/session-open.sh` (+ `npm run session:open`) | ✅ FAIT (v2) | Une commande, tout le contexte SSOT en sortie |
| P0 | `scripts/preflight-execute.sh` (scope-aware, modes, override) | ✅ FAIT (v2) | Refus mécanique d'EXECUTE non réservé |
| P0 | `scripts/post-execute-guard.sh` (`EXECUTE_DELEGATION:` + diff scope) | ✅ FAIT (v2) | Refus mécanique de VALIDATE sans preuve délégation + scope |
| P0 | `scripts/agent-activity-log.sh` : verrou `flock` | ✅ FAIT (v2) | Atomicité start cross-process |
| P0 | `docs/orchestration/COMMAND_DECK.md` (index pur) | ✅ FAIT (v2) | Une page humaine pour retrouver les bonnes commandes |
| P0 | `package.json` : `session:open`, `execute:preflight`, `execute:guard` | ✅ FAIT (v2) | Discoverability via `npm run` |
| P1 | (humain) Coller le bloc `SESSION_OPENING` au début des sessions lourdes | TODO | Adoption |
| P1 | Mettre à jour `run-cycle.md` Step 2 + Step 4 pour appeler les guards | TODO | Wiring procédural |
| P2 | Wrapper `npm run execute:complex -- <TASK> --scope <csv>` qui chaîne tout | TODO (futur cycle) | Une seule commande humaine pour la voie nominale |
| P2 | `base_commit` dans réservation + diff hors scope automatique | TODO (futur cycle) | Niveau « élite » ultime (refacto format log) |

## Tests / validation (ce plan)

- **static** : liens internes `SESSION_OPENING_ENFORCEMENT` ↔ `GLOBAL_SYSTEM_PRIMER` valides.  
- **no-test** : pas de code.

## Audit (à l’avenir)

- Tout **changement** gouvernance = petit `AUDIT` ciblé (terminal) sur cohérence `run-cycle` / `MEMORY_MATRIX`.

---

## PRIOR_CONTEXT (synthèse)

- `MEMORY_MATRIX` : 4 stores ; chat ≠ SSOT.  
- `MULTI_AGENT_ORCHESTRATION` : coordination = fichiers D + B, pas contexte partagé LLM.  
- `run-cycle` Step 0 : déjà la checklist complète — le gap était **l’adoption**, pas le texte manquant.

---

## Statut

- **APPLIQUÉ** (2026-04-25) : P0 (fichier session + référence Primer) — voir commit / historique.  
- **Prochaine action humaine** : utiliser le bloc `SESSION_OPENING_ENFORCEMENT` **en premier** message des sessions agents lourdes.

---

## Exécution par « maître » (Claude applicateur)

Règles pour l’agent qui **applique** ce plan (même rôle qu’orchestrateur doc) :

1. Ne **pas** affaiblir `run-cycle` ni `project-invariants`.  
2. Tout ajout = **une page** ou **une ligne** de renvoi — pas de second roman.  
3. Si conflit entre **rapidité** et **traçabilité** : **traçabilité** gagne (`AGENTS.md` / gates).

---

*Fin du plan `ORCH-DISCIPLINE-2026-04-25`.*

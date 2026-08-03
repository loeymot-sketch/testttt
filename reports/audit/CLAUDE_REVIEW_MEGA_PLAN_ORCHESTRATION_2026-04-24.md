---

## Verdict : **APPROVED_WITH_CHANGES**

Le plan est architecturalement cohérent et plus rigoureux que v3 sur la discipline atomique. Cependant, plusieurs écarts de processus et omissions factuelles imposent des corrections avant lancement de P2+.

---

## Cohérence (5 puces)

- **Invariants AGENTS.md** : Les 6 non-négociables (pricing SSOT, enum `OrderStatus`, `branch_id`, dispatch after commit, symétrie `OrderService`/`FrontendOrderService`, frozen zones) sont tous référencés explicitement. Alignement correct.
- **Phases / lots** : La carte §3 reproduit fidèlement le §4.1 de v3 (2.A–2.J). L'ordre d'attaque P2→P4 est compatible avec les dépendances documentées.
- **Token discipline** : Le découpage atomique (< 4k tokens / mission) est le principal apport du nouveau plan vs v3, et il est cohérent avec la contrainte proxy documentée dans AGENTS.md.
- **Gates D-04/D-05** : La barrière humaine sur P8 est explicitement maintenue, conforme à v3 §5 et `human-gates.mdc`.
- **Risque P0 §8** : Les 3 risques listés (commit avant dispatch, fuite `branch_id`, régression enum↔string) sont bien les risques prioritaires identifiés par AGENTS.md.

---

## Écarts / erreurs factuelles à corriger

**1. Modèle implémenteur non réconcilié avec v3.**  
v3 déclare `GPT-5.4-pro` comme implémenteur primaire. Le nouveau plan cite `gpt-5.5-high`. AGENTS.md valide GPT-5.5 — donc le nouveau plan est correct — mais le plan devrait noter explicitement : "upgrade modèle depuis v3 (GPT-5.4-pro → GPT-5.5-high)". Sans cette note, la traçabilité est cassée.

**2. Compteur de tests contradictoire (§2).**  
v3 §1 déclare "869 tests verts" en sortie Vague 1. Le nouveau plan §2 indique "~809 Vitest". Ces métriques ne sont pas comparables (total vs Vitest-only), et le `~` indique une imprécision. Avant P2, le plan doit établir le baseline exact : `phpunit` count + `vitest` count séparément.

**3. Stratégie d'audit Phase 2 non alignée sur v3.**  
v3 §4.3 prescrit "Audit Claude Code **unique** en fin de Phase 2 sur tout le batch." Le nouveau plan ajoute un audit Claude par lot (ritual §5, étape 5). Cette escalade est bonne mais doit être documentée comme amendement délibéré de v3, non comme contradiction silencieuse.

**4. Regroupement missions v3 abandonné sans justification explicite.**  
v3 §4.3 nomme explicitement `T-LOT-2-PAYMENT-RECOVERY` pour 2.A+2.B+2.D et `T-LOT-2-KDS-POS-PERSISTENCE` pour 2.F+2.G+2.H. Le nouveau plan sépare tout en `T-LOT-2A-*`, `T-LOT-2B-*`, etc. La raison (token discipline) est valide mais non écrite. Cela casse la traçabilité des `EXECUTE_DELEGATION` vers v3.

**5. D-09 absent du plan.**  
v3 §5 inclut D-09 "Bump deps: Vue 3.4 → 3.5 (vérifier breaking changes Options API)" avec annotation `manuel`. Le nouveau plan couvre D-01 à D-08 via P6–P7 mais omet D-09 complètement.

---

## 5 améliorations concrètes

**1. §5 Ritual — étape 0 manquante (Graphiti + safety-check).**  
Ajouter avant l'étape 1 actuelle :  
`0. search_memory_facts(group_ids=["foodking"]) + bash .cursor/hooks/safety-check.sh`  
AGENTS.md §1 Step 3 et Pre-Execution le rendent non optionnels.

**2. §5 Ritual — step 3 : ajouter `EXECUTE_DELEGATION:` obligatoire.**  
Après `node agents/codex.runner.mjs`, ajouter : "Tracer `EXECUTE_DELEGATION: codex-terminal` dans `reports/post_execute_latest.log`." AGENTS.md dit que cette trace est **obligatoire** pour passer en VALIDATE. Le ritual l'omet.

**3. §4 P5 — enrichir le critère de sortie.**  
Ligne actuelle : "Critère de sortie : 0 P0 ouvert, ou gate documentée."  
Ajouter : "Vérifier que chaque lot P2–P4 a une entrée `12_decisions_log.jsonl` + que `bin/graphiti-ingest.sh` a été lancé sur les fichiers concernés."

**4. §4 P1 — ajouter critère `human-verification` sur allergens UI.**  
Le badge allergènes (G-4/G-5) est sécurité alimentaire. Ajouter : "La clôture 2.I requiert validation humaine visuelle du badge (`human-verification`) en plus des tests Vitest." v3 §4.2 liste les tests mais ne prescrit pas cette gate.

**5. §4 P8 — référencer un plan fichier dédié.**  
Ligne actuelle : "Plan séparé + approbation humaine avant EXECUTE."  
Préciser : "Plan dédié `plans/PLAN_D04_D05_ENUM_REFACTO_<DATE>.md` avec `TASK_ID` conforme AGENTS.md bounded cycle SSOT, à créer et approuver avant P8." Sans cela, P8 n'a pas de `PLAN_FILE` valide et viole le contrat `run-cycle`.

---

## Manques (tests, gates, lots oubliés)

- **D-09 sans gate définie** : Vue 3.5 a des breaking changes potentiels Options API. Doit être déclaré `human-verification` ou `BLOCKED_UNTIL_HUMAN` dans le plan, pas juste absent.
- **Baseline CI non établie avant P2** : Le plan démarre P2 sans reconfirmer les ~809 Vitest + PHPUnit count post-2.I. Tout finding de régression sera ambigu.
- **Smoke E2E allergens (2.I)** : v3 §6 liste 6 critères de smoke global, mais aucun smoke spécifique au badge allergens n'est prescrit à la clôture de P1. Pour un lot sécurité alimentaire, c'est un manque.
- **`after-execute-memory.sh`** absent du ritual : AGENTS.md Terminal Allies §A prescrit `bash scripts/after-execute-memory.sh` après chaque lot pour rafraîchir le manifeste SHA et déclencher l'ingest Graphiti. Non mentionné en §5.
- **Lot 2.C owner** : v3 §4.1 attribue 2.C à "orchestrator (UI mince)". Le nouveau plan §3 le met en P4 sans préciser si c'est GPT ou orchestrator. Ambiguïté d'exécution à lever avant P4.

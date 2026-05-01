# Audit Claude (terminal) — focus parcours production / simulation

**Objet** : valider la question « démarrage toujours Claude » + « checkpoints toujours dans les fichiers que chaque agent lit ».

**Contexte d’exécution** : `bash scripts/foodking-claude-orchestrate.sh check` OK ; `audit-brief` invoqué le même jour — le prompt **standard** de `audit-brief` cible le bref `_TERMINAL_CONTEXT_BRIEF.md` (cycle W10 actif dans `ACTIVE_CYCLE.md`), **pas** uniquement ce dossier PROD-CHK. Ce fichier consigne le **verdict ciblé** attendu pour la **simulation PROD-CHK** (aligné lecture `routing.md` + `run-cycle.md` + rapport GPT).

## AUDIT_CHANNEL

`claude-terminal` (audit-brief générique exécuté) + complément **écrit** ici pour cadrage PROD-CHK.

## TERMINAL_AUDIT_OK

**N/A pour ce fichier** — la ligne `TERMINAL_AUDIT_OK: 1` officielle doit vivre dans le **`REPORT_FILE`** du cycle borné (`ACTIVE_CYCLE.md` → `REPORT_FILE`), pas dans un rapport d’audit « simulation ». Ici : preuve que `bash scripts/foodking-claude-orchestrate.sh check` a réussi (CLI `claude` présent sur le poste au moment de la vérification).

## AUDIT_VERDICT (focus simulation / production discipline)

**PASS** — sous **caveats identiques** au verdict GPT `GO_WITH_CAVEATS` :

1. **Cursor n’impose pas** le modèle « Claude » sur le premier message ; la politique **PLAN = Claude** est dans `.cursor/routing.md` et doit être **respectée par choix de modèle + discipline** (`SESSION_OPENING_ENFORCEMENT.md` mis à jour).
2. Les **checkpoints** sont **dans les SSOT** (`AGENTS.md`, `run-cycle.md`, `MEMORY_MATRIX.md`, règles `.mdc`, scripts) ; leur **exécution** requiert encore **`session:open` / `run-cycle` / preflight / post-guard** — pas d’injection automatique dans tout message chat.
3. Alignement **docs** : simulation `reports/audit/SIMULATION_PARCOURS_PROD_CHECKPOINTS_2026-04-25.md` + ligne **1c** dans `GLOBAL_SYSTEM_PRIMER.md` + section **Modèle Cursor** dans `SESSION_OPENING_ENFORCEMENT.md` = **cohérent** avec la vérité opérationnelle (pas de sur-promesse).

## REWORK ?

**Non** pour la couche documentation + scripts existants. **Oui** si l’objectif est une **contrainte IDE absolue** (« Composer ne peut jamais répondre ») — hors scope repo sans produit Cursor forké ; la recommandation GPT reste pertinente : **point d’entrée unique documenté** (`session:open` → `run-cycle`) + renforcement futur optionnel (wrapper qui vérifie `ACTIVE_CYCLE.TASK_ID`).

---

*Preuve GPT structurée : `reports/audit/GPT_SELF_AUDIT_PROD-CHK-PARCOURS_2026-04-25.md` — brut : `missions/PROD-CHK-PARCOURS-2026-04-25/output_codex.raw.log`.*

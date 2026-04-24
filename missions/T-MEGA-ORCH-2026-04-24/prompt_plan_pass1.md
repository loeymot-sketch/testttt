# Pass 1 — Claude terminal : structure du méga-plan (orchestration)

Tu es l'orchestrateur technique FoodKing (AGENTS.md, invariants project-invariants.mdc).

Contexte déjà lu : `reports/audit/_TERMINAL_CONTEXT_BRIEF.md` si présent.

Lis en skimmant (pas tout recopier) :
- `plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md` (sections 1–3 et ce qui reste)
- `plans/PLAN_POS_KIOSK_KDS_SYNC_REPAIR_2026-04-23.md` Phase 2–3 (lots 2.A..2.J, dette)

Travail demandé (sortie en français, structure markdown) :

1) **État actuel** : liste en tableau ce qui est marqué livré (Vague 1, 1.C, NEW-01..04, 2.I en cours) vs **reste à faire** (Phase 2 P1, Phase 3 dette, audits finaux).

2) **Découpe en 10 phases** (numérotées P1..P10) pour l’exécution future. Chaque phase doit avoir :
   - objectif,
   - fichiers/sous-systèmes typiques (sans chemins inventés),
   - critères d’acceptation (tests + invariants),
   - **budget token GPT** : rappeler que chaque mission `input.json` doit cibler **1 fichier ou 1 paire backend+test** pour rester sous ~8k tokens sortie proxy.

3) **Procédure d’audit** : pour chaque phase, ordre **Claude Code terminal** (`foodking-claude-orchestrate.sh audit`) **avant** cloture, puis éventuellement second avis GPT si utile.

4) **Fallback** : si erreur token / troncation `output_codex.json`, segmenter en sous-missions ou `foodking-complex-implementer` (Cursor) avec trace FALLBACK.

5) **3 risques** (P0) à surveiller sur la suite (sync, branch_id, commit_before_dispatch).

Pas de code produit. Pas plus de 1200 mots.

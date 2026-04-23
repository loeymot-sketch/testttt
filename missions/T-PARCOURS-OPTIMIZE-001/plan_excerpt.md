# Plan excerpt — T-PARCOURS-OPTIMIZE-001 (M2 only)

Cette mission codex-terminal couvre **uniquement M2** (rédaction du `## 0. Quick start contract`). M1 (split `ACTIVE_CYCLE.md`) a été exécuté en local (déterministe).

## Critères de validation pour M2

1. `head -1 AGENTS.md` retourne toujours `# FoodKing – Cursor Agent Operating Contract`.
2. `head -3 AGENTS.md` (lignes 1-3) après insertion : H1, ligne vide, `## 0. Quick start contract — read this first`.
3. La section `## Parcours obligatoire — **nouvelle conversation** ...` (ancien §1) reste intacte byte-pour-byte, juste déplacée plus bas.
4. Aucune autre section existante touchée.
5. Bloc final entre 35 et 60 lignes, chaque chemin cité vérifié existant.

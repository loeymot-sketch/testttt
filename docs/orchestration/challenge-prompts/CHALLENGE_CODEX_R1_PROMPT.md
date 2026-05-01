# Round 1 — mission Codex (coller dans `codex exec` ou utiliser : cat + tee)

**Contexte** : dépôt FoodKing (Laravel + Vue). Tu travailles en **lecture/audit** de ce workspace ; n’invente pas d’URL ou de clés. Référence invariants : **AGENTS.md** (prix serveur, branch_id, OrderStatus, after-commit, symétrie Order/Frontend, frozen zones). Objectif concret : aider à un **V1 fonctionnel** des flux **POS · Borne · KDS** (cohérence, sync, idempotence, events).

**Livrable** : un rapport en **français**, structuré exactement en sections **A–G** (titres requis) :

## A) Hypothèses
- 5–8 puces (ce que tu supposes vrai sur le build sans lire 500 fichiers en entier est OK ; marque *supposition*).

## B) Ce qui me semble **correct / robuste** dans le code ou la doc
- 8–20 puces ; cite **fichier** et si possible plage de lignes ou `grep` utile.

## C) Ce qui me semble **fragile, faux ou dangereux** (P0 d’abord)
- 15–25 puces ; chaque puce = **P0** | **P1** | **P2** ; une phrase *pourquoi* + **preuve** (fichier ou test).

## D) Matrice (tableau markdown)
- Lignes = 6–12 sujets (ex. idempotence, Echo, KDS sync, OrderTableChanged, pricing UI, `branch_id` admin) ; Colonnes = `État perçu` | `Risque` | `Action` | `Preuve requise`.

## E) Plan d’amélioration (ordre d’exécution)
- Liste numérotée (1..n) : ce qu’il faudrait **faire** pour un V1, sans patch massif inutile.

## F) Questions pour un **relecteur** (le rôle Closer / Claude)
- 5–8 questions ciblées pour qu’on te contredise ou te confirme.

## G) Métacritique
- 1 paragraphe : où ton analyse pourrait se tromper.

**Règle d’honnêteté** : marque *UNVERIFIED* quand tu n’as pas le fichier complet ouvert. Pas de brûlage d’invariants (pas de contournement backend pricing).

**Sortie** : contenu seul, sans préambule hors sections A–G. Cible d’enregistrement côté opérateur (tee) : `reports/audit/CHALLENGE_CODEX_R1_[CHALLENGE_DATE].md` (remplacer `[CHALLENGE_DATE]` par la date du jour, ex. 2026-04-25).

**Fin.**

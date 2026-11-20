# CONSIGNE COMMUNE DES RÉDACTEURS DE GOAL (2026-08-26)

Tu rédiges UN GOAL du programme « Onboarding commerçant » et SON rapport de mission. Tu es rédacteur ET auditeur :
tu n'écris rien que tu n'aies vérifié dans le code réel. Tu ne modifies AUCUN fichier produit.

## Tes lectures obligatoires (dans cet ordre, toutes réelles)
1. `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` (ton cwd = worktree
   `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/goal-onboarding-commercant-2026-08-26`) —
   ta ligne du tableau §1 fixe : titre, problème, **fichiers possédés**, port, dépendances. §2 fixe les collisions.
2. `reports/audit/onboarding-commercant-2026-08-26/_GABARIT_GOAL.md` — la LOI de forme du GOAL.
3. `reports/audit/onboarding-commercant-2026-08-26/_GABARIT_MISSION.md` — la LOI de forme du rapport de mission.
4. `reports/audit/onboarding-commercant-2026-08-26/recon/_BRIEF_COMMUN.md` + les rapports `recon/Z*.md` de ta zone
   (indiqués dans ta fiche) — l'état mesuré à l'écran le 2026-08-26 (captures dans `recon/screens/`).
5. Sur l'arbre principal (`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`, chemins absolus, jamais `cd`) :
   `CONSTITUTION.md`, `SYSTEM_MAP.md`, `PARALLEL_PROTOCOL.md`, `CLAUDE.md` §3-§9, `memory/reference_frozen_zones.md`,
   et les GOAL antérieurs cités dans ta fiche (pour « ce qui a déjà été fait »).
6. La compétence `~/.claude/skills/ultra-architect-planify/SKILL.md` (Axes 1, 2, 3, 6, 8, 9) — tu l'appliques à la lettre.

## Ancrage-d'abord (non négociable)
Avant d'écrire une section système, exécute sur l'arbre principal (chemins absolus) les `find`/`ls`/`grep`/`wc -l` qui
prouvent l'existence des fichiers, comptent les tests, localisent les lignes clés ; ouvre au moins un contrôleur, un
composant, un test par sous-système. Colle la SORTIE BRUTE (résumée) en §1. Chaque `file:line` cité a été lu. Chaque test
cité existe (`ls`) ou porte `(À CRÉER à <chemin>)`. Aucun produit, aucune route, aucune config inventés.

## Ce que le propriétaire exige dans CHAQUE GOAL (2026-08-26)
- Un vrai problème, prouvé par la reconnaissance, résolu « à la perfection », pas « presque ».
- Disciplines et règles explicites ; boucle jusqu'à validation complète (deux cycles identiques).
- Scénarios **au-delà du premier degré** : annulation à mi-chemin, effets indirects (borne / caisse / KDS / ticket /
  rapports), retour arrière, rechargement, double soumission, deux onglets, rôle inférieur, volumes, réseau coupé.
- La place du **commerçant** (persona §0.8) : contrôle total, vocabulaire compréhensible, peur de casser, confiance.
- Agents : adverses (ROUGE), de jalonnement (Jalonneur), spécialisés (Sécurité, UI, UX/A11y, **Psychologie commerçant**,
  DBA, SRE), raisonnement (Architecte) — §A.
- Tout ce que ce GOAL ne fait PAS est déclaré HORS avec le GOAL voisin qui le porte.

## Livrables (deux fichiers, dans le worktree)
- `plans/GOAL_ONB<nn>_<SLUG>_2026-08-26.md` — **30-40 Ko** mesurés par `wc -c` (≥ 24 Ko, ≤ 45 Ko). Si tu dépasses : factorise
  (renvoie le détail au rapport de mission), ne coupe jamais les acceptations nommées.
- `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB<nn>_<SLUG>.md` — 12-25 Ko, §0 « COMMENT LANCER » avec le prompt
  complet et spécifique (port, fichiers, dépendances, premier geste), §2 état mesuré repris de la recon (captures citées),
  §3 déjà fait, §4 ancrages, §5 bases, §6 décisions propriétaire, §7 pièges, §8 journal vide prêt à remplir.

## Auto-relecture avant de rendre (Axe 8 — coche les 10, sinon corrige)
- [ ] 3+ ancrages réels par sous-système, sortie brute en §1 · [ ] 3-4 sous-systèmes × 3-5 tâches, chacune avec ancrage +
  test nommé + scénario au-delà du premier degré · [ ] matrice §S remplie (test nommé ou N/A motivé) · [ ] §A avec
  Psychologie commerçant + Jalonneur + contrat de constat · [ ] vagues avec parallélisme + point de contrôle + interruption ·
- [ ] gates QUI/QUOI/OÙ (G0 inclus) · [ ] §0.1 pré-vol complet (port de l'index) · [ ] §0.7 contradictions (C-CONST + les
  tiennes) · [ ] taille `wc -c` dans la fenêtre · [ ] zéro anglais user-facing, zéro « tests passent » nu, zéro zone gelée
  touchée sans LOCK.

## Réponse finale
Retourne uniquement : les deux chemins écrits, leurs tailles `wc -c`, la liste des commandes d'ancrage exécutées (10-20
lignes), les 3 décisions de conception que tu as prises et pourquoi, et ce que tu n'as PAS pu vérifier (honnêteté).

# G6 — Un instantané figé, des suites vertes, des journaux bruts

Dépendances : **G1 à G5 fermés.** Ce GOAL ne produit aucun correctif fonctionnel : il produit la
**preuve** que le reste tient.

---

## Pourquoi ce GOAL existe

Les deux audits externes qui ont ouvert cette mission ont échoué sur le même point : ils
annonçaient des nombres (« 181 tests verts », « 187 PHPUnit et 51 Vitest ») sans joindre un seul
journal brut, et ont lancé des suites complètes **à cheval sur plusieurs commits**, ce qui les
rend inattribuables à quoi que ce soit.

Ce GOAL interdit la répétition de cette faute.

## Contraintes découvertes le 2026-09-03

L'arbre `testttt` est **partagé**. Au moment de la rédaction, deux autres sessions y exécutaient
simultanément `vitest` et `phpunit`, plus un `npm run production` dans un arbre de travail
séparé (`.claude/worktrees/rattrapage-0903`).

Conséquences, non négociables :

1. **Aucune suite complète tant que l'arbre n'est pas calme.** Un rouge issu d'une base de test
   partagée n'appartient à personne. Vérifier avec
   `ps aux | grep -E "phpunit|vitest|playwright" | grep -v grep` **avant** de lancer.
2. **Aucun `git add .`, aucun `git add -A`.** Toujours `git commit -- <chemins explicites>`,
   `--amend` compris. L'index porte le travail d'autres sessions.
3. **Node v22 obligatoire** : `export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"`.
   Le v18 ambiant fait échouer Playwright pour des raisons étrangères au produit.
4. **Playwright** : `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766`. Le défaut de la configuration
   pointe vers `:8000`, qui n'écoute pas.

## Tâches

- **T6.1 — Figer l'instantané.**
  Commiter tout le travail G1–G5 par chemins explicites. Relever et consigner dans
  `reports/supervision/2026-09-03/SNAPSHOT.md` : le SHA, `git status --short`, le SHA-256 de
  chaque fichier de production modifié, celui de `public/mix-manifest.json` et des cinq lots,
  l'URL de base, la base de données, le PID et le répertoire du serveur.

- **T6.2 — Prouver que les lots servis sont ceux du source.**
  `npm run production`, puis les cinq sentinelles de fraîcheur VERTES, puis vérification **en
  HTTP** que le serveur sert bien les fichiers reconstruits (taille et code de retour).
  Précédent utile : avant cette mission, quatre de ces cinq sentinelles étaient rouges et
  produisaient à elles seules quatre rouges Vitest et cinq échecs Playwright.

- **T6.3 — Suites complètes, arbre calme, journaux conservés.**
  ```
  php artisan test                → reports/supervision/2026-09-03/phpunit-complet.txt
  npx vitest run                  → reports/supervision/2026-09-03/vitest-complet.txt
  ```
  Chaque rouge est traité par l'une de ces trois voies, jamais autrement :
  (a) corrigé ; (b) **rejoué seul** et prouvé vert en isolation — alors c'est un effet
  d'ordonnancement, à consigner comme tel ; (c) documenté avec sa cause et son antériorité au
  travail de cette mission.
  Un compteur d'échecs recopié sans rejeu individuel ne vaut rien.

- **T6.4 — Contrôles transverses.**
  `bash .cursor/hooks/safety-check.sh` → PASS.
  `git diff HEAD --stat -- <les 15 fichiers de CLAUDE.md §7>` → **vide**.
  `php artisan fiscal:verify-chain --all` → CHAIN OK.
  `bash scripts/check-invariants.sh -v` → classer chacune des occurrences rouges : défaut réel,
  ou faux positif textuel documentaire. Le garde est en partie textuel ; ce qui n'est pas
  acceptable, c'est de l'ignorer en bloc.
  `npm run pos:lint:pricing` · `npm run pos:lint:status` · `npm run i18n:audit` ·
  `npm run perf:bundle-check`.

- **T6.5 — Campagne navigateur, erreurs console bloquantes.**
  Spec : `(À CRÉER) tests/e2e/goal-convergence-2026-09-03.spec.js`.
  Périmètre : dashboard · cockpit santé · cockpit outbox · caisse avec tiroir · trois formats
  d'écran · matrice de rôles (Admin, Tenant Admin, porteur de `settings` non-admin, POS, non
  authentifié).
  **Toute erreur console ou refus réseau inattendu fait échouer le test.** La campagne actuelle
  les collecte sans les asserter : c'est précisément le transport temps réel qu'elle prétend
  surveiller. Un `expect` sur une liste d'erreurs vide, avec liste blanche explicite et datée
  pour ce qui est réellement hors périmètre.
  Publier dans le rapporteur autoritaire `reports/antigravity/playwright-latest.json`.

- **T6.6 — Lire les captures, pas seulement les produire.**
  Chaque capture est ouverte et analysée : mise en page intacte, aucun libellé brut
  (`Label.X`, `kiosk.foo`, `0undefined`), états vides cohérents, états d'erreur discernables,
  contraste mesuré sur le Ticket Moyen. Une capture non lue ne compte pas comme preuve.

- **T6.7 — Convergence.**
  Deux rondes complètes consécutives, **ensembles de constats identiques**, P0+P1 = 0.
  Deux rondes qui trouvent des choses différentes ne convergent pas : elles se contredisent.

- **T6.8 — Mémoire du projet.**
  `PROJECT_BRAIN.md` §2 (état, HEAD, date), §3 (ce qui a été fait), §4 (ce qui reste), §7 si un
  domaine atteint la validation. Écrire aussi ce que cette mission a coûté à ses propres
  affirmations — c'est la partie qui sert le plus à la session suivante.

## Acceptation

- PHPUnit complet et Vitest complet : verts, ou rouges intégralement attribués par rejeu isolé.
- Journaux bruts présents sur disque, cités par leur chemin.
- Cinq sentinelles de fraîcheur vertes **au moment du commit final**.
- Zone gelée : diff nul. `safety-check.sh` : PASS. Chaîne NF525 : OK.
- Rapporteur Playwright frais, zéro saut inattendu, zéro erreur console non listée.
- Deux rondes identiques.

## Condition de sortie

Un lecteur qui ne fait pas confiance à ce rapport doit pouvoir **refaire chaque mesure lui-même**
à partir du seul instantané, et retrouver le même résultat.

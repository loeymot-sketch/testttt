PLAN_REVIEW_CHANNEL: codex-extension  
PLAN_REVIEW_MODEL: gpt-6-astra  
PLAN_REVIEW_REASONING_EFFORT: xhigh  
PLAN_REVIEW_VERDICT: REWORK

Le routage GPT complexe est conforme, sans implémentation Composer. Les invariants sont protégés déclarativement : pricing backend SSOT, `OrderStatus`, `branch_id`, dispatch et frozen hors périmètre ; symétrie des services N/A cohérente.

Corrections requises avant exécution :

- **Borner les fichiers** : le [plan](/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/plans/PLAN_MOBILE-EXCELLENCE-PHASE1-20261016_2026-10-16.md:21) évoque des « fichiers déclarés » sans les lister. Ajouter une liste explicite, vérifier les frozen et distinguer les modifications mobiles déjà présentes.
- **Formaliser les tests** : déclarer `local-validation` et la modalité navigateur ; reprendre les commandes et l’URL de la tâche. Définir les critères : images chargées et correctement associées, fallback sans faux visuel, allergènes conservés en détail/récap, noms/prix/payloads inchangés.
- **Traiter les tests préexistants** : [menu.spec.js](/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/tests/menu.spec.js:15) dépend d’un miroir web externe et attend encore **7,90 €** pour le Tacos L, contre **8,90 €** dans le catalogue actuel. Prévoir une validation mobile autonome et résoudre les attentes obsolètes sur preuve canonique, sans modifier les prix pour obtenir du vert.
- **Préciser les arrêts** : référencer explicitement les conditions de `run-cycle` : débordement de scope, violation d’invariant, collision, gate non approuvé et deux échecs consécutifs de validation. Toute édition frozen exige une approbation humaine enregistrée.
- **Aligner le cycle** : `.cursor/ACTIVE_CYCLE.md` référence une autre mission avec des phases contradictoires. Renseigner ce TASK_ID, son plan et son rapport avant EXECUTE ; résoudre aussi l’échec de `verify:boucle` constaté deux fois (`exit 1`).

Aucun fichier modifié.
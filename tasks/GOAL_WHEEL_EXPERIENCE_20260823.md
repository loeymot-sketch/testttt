# GOAL_WHEEL_EXPERIENCE_20260823 — Expérience roue et gain

## Meta
- **Priority**: P1 — expérience client, conversion et qualité perçue
- **TEST_STRATEGY**: playwright-mcp
- **DEPENDS_ON**: (none)
- **BLOCKS**: (none)

## Demande utilisateur

Rehausser l'expérience complète de la roue : séquence de lancement, révélation du gain, pages associées, qualité visuelle, arrière-plans, images, animation, micro-interactions et parcours mobile/desktop. Préserver le résultat attribué par le serveur, les règles d'accès et les livraisons existantes.

## Boundary

### SUBSYSTEMS_TOUCHED
- Surface publique client de la roue et du gain : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/roue.html` et ses tests E2E voisins.
- Surface tablette d'attraction associée : `resources/views/admin/wheel/borne.blade.php` en lecture/validation seulement, sauf défaut concret découvert dans son périmètre.
- Assets visuels dédiés à la roue dans les deux répertoires, ajoutés de manière non destructive si nécessaires.
- Tests existants du domaine `tests/Feature/Wheel/` et tests E2E ciblés de la roue.

### Hors scope
- Attribution métier du gain, probabilités, stock, fidélité, prix, paiement, commande, fiscalité, migration et services `app/Services/Wheel/`.
- Écrans POS, Kiosk de commande et administration générale hors l'écran tablette d'attraction de la roue.
- Toute zone frozen ou tout changement de schéma.

## Critères de succès

- Animation de spin pilotée par le résultat serveur déjà décidé ; aucune détermination ou logique de gain côté client.
- Parcours clair de l'éligibilité jusqu'à la page de gain et de retrait, sur mobile comme desktop.
- UI accessible : contraste, focus clavier, lecteurs d'écran, réduction de mouvement et états de chargement/erreur.
- Images/visuels cohérents, chargés sans dégrader les performances ni provoquer de mise en page instable.
- Tests ciblés verts et preuve visuelle Playwright des états importants.

## Invariants

- Le serveur reste l'unique autorité pour le gain et son attribution.
- Aucune donnée de branche, prix, commande ou statut de commande n'est modifiée.
- Aucune zone frozen, migration ni service Wheel n'est touché sans replan/gate.

# BORNE UX — 5 corrections owner + validation e2e visuelle (2026-07-11)

| # | Demande | Fix | Validé e2e |
|---|---|---|---|
| 1 | Sidebar catégories trop petite / peu distincte | Largeur doublée 124→256px, cartes distinctes (fond+bordure), miniatures 74→120px, labels 12→18px | ✅ borne-tacos/sandwichs/burgers |
| 2 | Photos sandwich = pas les nôtres | Cayenne détouré (rembg) pour item+catégorie ; TOUS sandwiches+burgers+viandes détourés (cohérence sans-fond) | ✅ borne-sandwichs (Cayenne propre), borne-burgers (tous propres) |
| 3 | Tacos images anciennes | Nouveau tacos.png détouré (item+catégorie) + différence taille L>M (~30% via CSS scale) | ✅ borne-tacos (L nettement > M) |
| 4 | Démarrer : toucher n'importe où | `@click` sur root idle → à emporter + ripple ; cards Sur place/À emporter gardent @click.stop | ✅ clic fond → /kiosk/categories |
| 5 | Produits plus grands selon l'espace (2 tacos empilés) | Grille adaptative : solo/duo(empilé ~80%)/quad ; media agrandie ; occupe max sans déborder | ✅ tacos empilés grands, burgers 2-col |

Outils : rembg (u2net) pour détourage, PIL pour thumbs WebP (alpha ≤320px). 33 images détourées.
Composants non-frozen. Bundles rebuild. Commit `d4b7804c6`. Backups images: /tmp/borne-img/backup.

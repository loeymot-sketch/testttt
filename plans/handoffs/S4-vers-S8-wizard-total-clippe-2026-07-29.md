# HANDOFF S4 → S8 — défauts wizard trouvés pendant l'audit V1 (2026-07-29)

> Trouvés par la session S4 (site client) pendant l'audit visuel des 50 surfaces.
> `wizard-v2.jsx` = voie S8 (DISCIPLINE §10) : **je n'y ai pas touché**.
> Preuves : `reports/goal-s4-web/V1-shots/` (captures desktop 1440x900 + mobile 390x844).

## P1 — Le bloc TOTAL de l'aperçu live est CLIPPÉ par le bas de la modale
- Visible dès l'étape 6/8 sur `desktop-07-panier.png`, `-08`, `-09`, `-11`.
- Seule une lisière du bloc noir « TOTAL » reste visible : **le client perd son total
  au moment le plus sensible du parcours** (juste avant de valider).
- Colonne droite `.lc-wiz-preview` — la hauteur de la modale ne réserve pas la place
  du bloc total en bas.

## P1 — CTA principal grisé sans explication
- `desktop-06`, `-09`, `mobile-06`, `-09` : « Voir récap » est désactivé et **rien
  n'explique ce qui manque**. Seul « Min : 1 · Sélection : 0 / 4 » en très petit corps
  le laisse deviner, en haut de l'écran, loin du bouton.
- Le client voit un bouton mort. Suggestion : message court à côté du CTA
  (« Choisis 1 sauce pour continuer »).

## P1 (mobile) — La liste de sauces passe SOUS la barre d'action fixe
- `mobile-09-checkout.png` : la dernière tuile de l'étape « Sauce pour les frites »
  est coupée par la barre basse (padding-bottom insuffisant sur `.lc-wiz-options`).

## Note méthode
Mon script de captures pilote le wizard via `button.lc-wiz-choice` /
`button.lc-wiz-foot-next` (`tests-e2e/audit-surfaces-s4-2026-07-29.js`) — réutilisable.

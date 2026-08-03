# AUDIT VISUEL — site web Le Cayenne (2026-07-09)

Captures lues (Read tool) par moi. Cible :8096 (source), backend :8766. Desktop 1360×900 + mobile 390×844.
Toutes dans `captures/`.

| # | Surface | Verdict | Notes |
|---|---|---|---|
| 1 | Home (desktop) | ✅ | hero, promo du jour, on-brand, images OK |
| 2 | Home (mobile 390) | ✅ | hamburger nav, hero responsive, image locale OK |
| 3 | Menu | ✅ | 9 cat/38 items, prix exacts, images backend OK, filtres, panier a11y |
| 4 | Item detail (Big Burger) | ✅ | catégorie, allergènes, prep, retrait, image contain, prix |
| 5 | Wizard (perso) | ✅ | 6 étapes, icônes sauces SVG, marqueurs 🌶️, aperçu live + total |
| 6 | Cart | ✅ | qty stepper, delete, créneaux, promo, note cuisine, **+1 pt** (floor 1,90€), total |
| 7 | Upsell dessert (1/2) | ✅ | Glace/Tarte/Tiramisu, Non merci/Continuer |
| 8 | Upsell frites (2/2) | ✅ | Petite/Grande frites, Régler ma commande |
| 9 | Checkout | ✅ | stepper, mode retrait/livraison, jour+heure, promo, lieu+itinéraire, récap +1pt |
| 10 | **Payment (Stripe OFF)** | ✅ | **SEUL mode = « Payer sur place » (comptoir), 0 option CB en ligne** ; RGPD messaging |
| 11 | OTP gate (guest) | ✅ | « Confirme ton numéro → code SMS » AVANT transmission caisse (anti-abus) |
| 12 | Account modal | ✅ | Connexion/Inscription, Google/Apple, email/mdp, oublié?, +25pts inscription |
| 13 | About (L'enseigne) | ✅ | histoire, timeline 2024→2026, 3 obsessions, équipe RÉELLE (Abdoullah/Karim/Léa), FAQ |

## Verdict visuel
- **0 erreur console** sur toutes les surfaces (1 warning React dev-mode bénin).
- **Intégrité numérique** money-path : 1,90€ cohérent cart→checkout→payment→récap ; +1 pt cohérent.
- **Stripe OFF prouvé à l'écran** : paiement au comptoir uniquement.
- **Sécurité UX** : OTP téléphone obligatoire avant transmission (guest).
- **Images** : héros local (cayenne-hero.png) + produits backend (menu-image-base) chargent ; icônes sauces SVG OK.
- **0 résidu démo** (équipe = vrais prénoms owner).
- **Responsive** mobile OK (hamburger, stacking).
- **UI/UX** : niveau grande-chaîne, typographie forte, palette noir/orange/jaune/crème cohérente.

→ P0/P1 visuels = **0**. Reste : findings code-level du workflow adversaire wrb449q93 (sécu/a11y/images/intégrité) à trier + healer.

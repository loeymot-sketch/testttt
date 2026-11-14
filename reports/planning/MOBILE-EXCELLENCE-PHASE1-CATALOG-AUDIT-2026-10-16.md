# Audit catalogue mobile Le Cayenne — 2026-10-16

## Source vérifiée

- Source backend locale interrogée avec `Item::whereNull(deleted_at)`.
- Catégorie boissons actuelle : 15 références.
- Prix actuels vérifiés : 1,90 € pour les canettes, 1,00 € pour Eau Plate 50cl,
  1,50 € pour Capri-Sun.
- L’application mobile reste un miroir standalone : aucune connexion automatique au
  backend n’est ajoutée dans cette tranche, conformément à `CLAUDE.md`.

## Boissons actuellement canoniques

| ID | Slug backend | Nom | Prix |
|---:|---|---|---:|
| 52 | coca | Coca-Cola 33cl | 1,90 € |
| 53 | coca-zero | Coca-Cola Zero 33cl | 1,90 € |
| 54 | fanta | Fanta Orange 33cl | 1,90 € |
| 55 | sprite | Sprite 33cl | 1,90 € |
| 56 | oasis | Oasis Tropical 33cl | 1,90 € |
| 57 | orangina | Orangina 33cl | 1,90 € |
| 58 | eau-plate | Eau Plate 50cl | 1,00 € |
| 59 | capri-sun | Capri-Sun | 1,50 € |
| 119 | coca-cherry | Coca Cherry 33cl | 1,90 € |
| 120 | tropico | Tropico 33cl | 1,90 € |
| 121 | ice-tea | Ice Tea Pêche 33cl | 1,90 € |
| 122 | fanta-citron | Fanta Citron 33cl | 1,90 € |
| 123 | fuze-tea | Fuze Tea 33cl | 1,90 € |
| 124 | hawai | Hawaï 33cl | 1,90 € |
| 125 | perrier | Perrier 33cl | 1,90 € |

Les noms supplémentaires demandés oralement (Oasis Cassis, Schweppes Agrumes,
bouteille de Coca, Lipton/Fuze variants) ne sont pas présents dans la table actuelle
vérifiée. Ils ne doivent pas être ajoutés au miroir mobile sans validation dans la
borne/DB, sinon les prix, disponibilités et payloads ne seraient pas fiables.

## Écarts constatés dans le miroir mobile avant correction

- `ice-tea-peche` côté mobile ne reprend pas le slug backend `ice-tea`.
- L’ancien mapping réutilisait `tropico.png` pour Orangina, ce qui affichait une
  mauvaise marque.
- Plusieurs références n’ont qu’un asset générique local (`boisson.png`) ; elles
  doivent conserver un fallback neutre plutôt qu’une image d’une autre boisson.
- La Home rendait les catégories avec des emojis alors que chaque catégorie possède
  déjà un asset local `cat-*.png`.
- Le badge `⚠️` allergène apparaissait dans les cartes compactes ; la divulgation
  reste nécessaire dans le détail/recap, mais pas comme décoration sur chaque photo.

## Suite prévue

1. Phase 1 : corriger le miroir visuel et les tests autonomes.
2. Phase 2 : compléter la parité des produits et variantes de frites après comparaison
   stricte avec la DB/borne.
3. Phase 3 : réactiver/désactiver fidélité avec fenêtre de 30 jours, après contrat API
   et politique confirmés.
4. Phase 4 : Mollie et validation paiement de bout en bout, avec audit sécurité et
   idempotence.

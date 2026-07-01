# LOCK — pos-wizard.js : facturation viande supplémentaire (+2,50€ / viande)

**Date** : 2026-07-01
**Fichier frozen §7** : `public/js/pos-wizard.js` (Vanilla JS hand-written, popup caisse)
**Autorisation owner** : ✅ **explicite** (chat 2026-07-01 — « A oui » : facturer +2,50€ par viande supplémentaire).

## Pourquoi (bug)
Le récapitulatif du wizard caisse omettait la **viande supplémentaire** : quand le client
ajoutait N viandes en plus, le total du récap restait inférieur de **2,50€ × N** à ce qui était
réellement encaissé (sous-facturation vs devis). L'extra « Viande supplémentaire » doit être
facturé 2,50€ par viande ajoutée.

## Changement
- `pos-wizard.js` `syncAndSubmit` : pose `data-wizard-qty = N` sur la checkbox de l'extra
  « Viande supplémentaire » (N = nb de viandes en plus) au lieu d'un binaire (1), pour que le
  supplément soit sérialisé avec la bonne quantité → facturé N × 2,50€.
- Jumeau non-frozen `ItemComponent.vue::onWizardBridgeExtra` : lit `data-wizard-qty` →
  `setExtraQuantity(extra, N)`.

## Preuve (validée par la session caisse-prix)
- Cayenne + Menu = 9,90€ partout (grille/panier/total/modal/commande persistée/ticket).
- Encaissement e2e réel #5370 : total 9,90 / reçu 20 / rendu 10,10 / fiscal seq 2575 / PAID.
- Compo complexe Terminator + Cheddar + Viande suppl + Menu = 14,90€ (backend quote).
- 35/35 produits prix DB exact.

## SHA baseline
- Avant : `896db50c98fd3b5ca85f4d19c042932bd7db185d43d16dfd039b984c9cb2ead6`
- Après : `5dc03d09dd0726b0be0495db679f7e0819f6bb3203c3f9b1b8b761e1483f9471`
- `tests/Feature/Sentinels/frozen-zone-sha256-baseline.json` mis à jour en conséquence.

## Portée
Modification frozen limitée à la sérialisation quantité de l'extra viande suppl. Aucun autre
comportement du wizard touché. Design du wizard inchangé (owner mandate).

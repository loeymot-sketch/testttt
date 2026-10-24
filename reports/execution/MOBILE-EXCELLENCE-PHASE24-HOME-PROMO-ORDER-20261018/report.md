# Mobile Le Cayenne — Phase 24

## Résultat intermédiaire

La vraie application mobile est `mobile/`, servie localement sur `http://127.0.0.1:8087`.

La correction UI bornée est livrée :

- accroche d’accueil réduite à `Prêt quand tu l'es.` ;
- logo légèrement agrandi ;
- statut « Ouvert maintenant » placé sous le logo, dans une colonne centrée entre deux contrôles de 44 px ;
- tests statiques mis à jour.

## Vérifications

- `mobile/tests/mobile-ui-excellence.spec.js` : PASS
- `mobile/tests/mobile-catalog-parity.spec.js` : PASS
- `mobile/tests/mobile-identity.spec.js` : PASS
- `mobile/tests/ordersParity.spec.js` : 1 échec préexistant : fixture `Tacos L` à 7,90 € alors que le catalogue canonique est à 8,90 €.
- E2E mobile ciblé : 8 PASS / 3 FAIL.

Échecs E2E observés : nouveautés attendues (`Tacos XL`) non présentes dans la section, choix de viandes sans images dans le wizard, opt-out fidélité non persisté pendant le scénario. Ces points doivent être corrigés avant un verdict App Store.

## Blocages de soumission

- Paiement en ligne / Apple Pay : `onlineCardEnabled=false` et l’écran Wallet est un avis V0 ; ce n’est pas une intégration Apple Pay vérifiable.
- Promotions : le mobile applique encore un -10 % local pour trois codes ; la validation backend `/api/frontend/promo/validate` existe mais n’est pas branchée au mobile. Cela ne respecte pas la source de vérité backend pour les prix/remises.
- Notifications : le bouton d’accueil indique encore « bientôt disponibles » ; APNs/token device/campagnes ne sont pas activés dans ce build.
- Les modifications de catalogue, prix, promotions, frais et images peuvent être distantes sans nouvelle version uniquement si l’application les lit via API/CDN. Les changements de code, UI, SDK Apple Pay, permissions ou capacités natives exigent une nouvelle version App Store.

## Invariants contrôlés

Pricing backend SSOT, OrderStatus, branch_id, dispatch après commit, zones gelées et symétrie OrderService/FrontendOrderService : aucune modification dans cette passe UI ; le paiement, les promos backend et la politique fidélité restent hors périmètre de cette correction.

## Délégation

`EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)`

`FALLBACK_REASON:` le binaire Codex local a échoué avec `ENOENT` après deux reprises documentées.

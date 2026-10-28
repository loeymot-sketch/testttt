# Mobile Le Cayenne — accueil, promotions et parcours complet

## Demande propriétaire

Sur la vraie application mobile (`mobile/`, prévisualisation `8087`) :

- raccourcir la phrase d'accueil pour tenir sur une seule ligne autant que possible ;
- agrandir légèrement le logo ;
- placer « Ouvert maintenant » sous le logo, avec une largeur cohérente avec le bloc de notification ;
- vérifier le parcours commande complet, retrait, fidélité et paiements ;
- déterminer quelles promotions, nouveautés, remises, frais et notifications peuvent être
  modifiés à distance sans nouvelle version App Store.

## Découpage borné

Cette mission commence par l'accueil et l'audit contractuel du parcours. Les modifications
de prix, promotions, frais, paiements, fidélité backend, push APNs/FCM et notifications de masse
restent hors édition jusqu'à confirmation de leur source de vérité et de leurs gates.

## Critères d'acceptation

- La phrase d'accueil mobile est courte, exacte et ne promet pas un canal inexistant.
- Le logo et le statut d'ouverture sont lisibles sur iPhone étroit et dans la prévisualisation.
- Le bouton Notifications n'annonce pas une fonctionnalité inexistante sans état explicite.
- Les tests statiques mobile passent ; le parcours commande est capturé de l'accueil à la
  confirmation et au recalcul de fidélité.
- Un rapport distingue clairement les mises à jour distantes (catalogue/configuration) des
  mises à jour App Store (code natif/UX/permissions).

## Hors périmètre sans nouveau gate

- `app/`, `routes/`, `config/`, `database/`, paiement Mollie/Apple Pay, OrderService,
  FrontendOrderService, loyalty policy, notifications push et données de prix.

## Stratégie de test

`local-validation` + `playwright-critical-flow` sur le parcours mobile commande/confirmation,
avec `human-verification` requis pour Apple Pay, push et TestFlight.

# Task — MOBILE-EXCELLENCE-PHASE7-MOLLIE-PARITY-20261016

## Objet

Aligner le paiement en ligne de l’app Le Cayenne sur le flux Mollie du site,
avec montant scellé serveur, retour fiable et aucun faux formulaire Stripe.

## Constat vérifié

- `mobile/index.html` garde `onlineCardEnabled: false`.
- `mobile/api/client.js::placeOrder()` force `payment_method: 1` (paiement au
  comptoir) et ne déclenche pas `mollie-checkout`.
- `mobile/screens-modals.jsx::ScreenStripe` contient encore un écran placeholder
  Stripe et des données carte fictives, même s’il est normalement inaccessible
  quand le flag est OFF.
- Le backend expose `POST /api/frontend/order/{frontendOrder}/mollie-checkout`.
  Il exige une commande appartenant à l’utilisateur, `payment_method=4`, un
  état UNPAID et une configuration Mollie + réglage métier activé.
- Le backend renvoie `checkout_url`, `payment_id`, `inline` et `reason`; le
  webhook seul finalise le paiement.

## Gate humain requis

Avant toute activation ou modification du flux carte :

1. Confirmer que Mollie est configuré en environnement de test puis production
   (`MOLLIE_ENABLED`, clé, webhook, URL de retour et réglage métier).
2. Confirmer le parcours mobile souhaité : redirection hébergée Mollie, Mollie
   Components dans l’app, ou les deux.
3. Confirmer la stratégie de retour iOS tant qu’aucun projet natif signable
   n’existe : navigateur web temporaire, deep-link ultérieur, ou report du
   paiement mobile.
4. Confirmer les moyens à activer : carte, Apple Pay et/ou Google Pay.

## Hors périmètre sans gate

- Aucun passage du flag `onlineCardEnabled` à `true`.
- Aucun changement de `PaymentGateway`, de fiscalité, de webhook ou de statut de
  commande.
- Aucun secret Mollie dans le dépôt.

## Validation future

- Feature tests commande carte → checkout, propriété utilisateur, idempotence,
  réglage OFF et webhook.
- E2E mobile sur commande réelle de test, redirection, retour, état pending et
  échec sans double encaissement.
- Vérification de parité web/app sur total, devise, statut et historique.

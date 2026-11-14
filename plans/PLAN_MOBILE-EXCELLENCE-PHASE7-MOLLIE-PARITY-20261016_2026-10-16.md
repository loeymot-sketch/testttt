# Plan — MOBILE-EXCELLENCE-PHASE7-MOLLIE-PARITY-20261016 — 2026-10-16

## Statut

HUMAN_GATE_REQUIRED — audit de contrat uniquement. Aucun code paiement ne doit
être modifié avant les décisions et la configuration de test Mollie.

## Tier

complex — paiement, idempotence, auth, order lifecycle, webhook et retour iOS.

## Fichiers inspectés

| Zone | Fichiers |
|---|---|
| Mobile commande | `mobile/api/client.js`, `mobile/index.html` |
| Mobile paiement | `mobile/screens-modals.jsx` |
| Backend checkout | `app/Http/Controllers/Frontend/MolliePaymentController.php`, `routes/api.php` |
| Configuration | `config/payment.php` |

## Invariants

- Le backend reste l’unique source du montant et de la devise.
- La commande Mollie doit être une commande carte UNPAID appartenant au client.
- Le webhook, et non le mobile, est la source de vérité du statut PAID.
- Chaque tentative de checkout porte une idempotency key ; aucun retry ne doit
  créer un second paiement.
- Aucun token de carte, Apple Pay ou Google Pay n’est journalisé ou persisté en
  clair.
- Le flux doit rester fermé si le flag technique ou métier est désactivé.

## Blocage explicite

Activer seulement le bouton côté mobile serait dangereux : le client actuel
crée d’abord une commande comptoir, tandis que le backend Mollie refuse les
commandes dont `payment_method` n’est pas CARD. Il manque aussi le retour
mobile et le projet iOS signable.

## PLAN_REVIEW

PLAN_REVIEW_VERDICT: HUMAN_GATE_REQUIRED
REASON: configuration Mollie, parcours retour et moyens de paiement non décidés

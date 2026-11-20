# Gate brief — parité paiement Mollie mobile/site

## Décisions attendues

- Environnement de test Mollie disponible et webhook configuré : oui/non.
- Parcours choisi : checkout Mollie hébergé, Components, ou les deux.
- Retour mobile avant packaging natif : navigateur web temporaire ou report.
- Moyens activés : carte seule, carte + Apple Pay, carte + Apple Pay + Google Pay.

## État confirmé

- App mobile : carte en ligne désactivée et commande actuelle en comptoir.
- Backend : endpoint Mollie déjà présent et fail-closed, avec vérification de
  propriété, `payment_method=4`, état UNPAID, idempotence et webhook.
- Écran mobile carte : placeholder Stripe avec données fictives, à supprimer ou
  remplacer dans le lot d’implémentation après décision.
- Native iOS : aucun projet signable détecté dans `mobile/`.

## Verdict

`HUMAN_GATE_REQUIRED`

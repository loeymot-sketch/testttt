# Caisse — Antisèche POS (à plastifier et coller à la caisse)

## Encaisser une commande borne (paiement au comptoir V1)

1. Le client arrive avec son ticket borne portant un **numéro d'appel** type `A042`.
2. Onglet **Commandes** → champ recherche en haut, taper `A042` → la commande s'ouvre.
3. Vérifier le total à l'écran et lire à voix haute au client.
4. Cliquer le bouton de paiement :
   - **Encaisser en espèces** : ouvre le tiroir, rendre la monnaie.
   - **Encaisser en carte** : passer le montant manuellement sur le terminal **SumUp** (terminal externe, non relié au POS). Quand SumUp affiche **Paiement validé**, cliquer **Confirmer carte** dans le POS.
5. Le ticket s'imprime automatiquement. Le numéro d'appel `A042` part en cuisine ou apparaît sur l'OSS pour appeler le client.

## Encaisser une commande caisse classique

1. Bouton **Nouvelle commande** → composer le panier en cliquant les items (mêmes catégories que la borne).
2. Bouton **Encaisser** → choisir espèces ou carte (même procédure SumUp manuelle qu'au-dessus).
3. Ticket imprimé. Si client veut un duplicata : onglet Commandes → ouvrir la commande → bouton **Imprimer**.

## Rembourser (HEAL-4 — fenêtre de rectification)

Si erreur d'item ou client mécontent dans la **même journée** (avant clôture Z) :
1. Onglet Commandes → ouvrir la commande → bouton **Rembourser**.
2. Choisir : remboursement total OU partiel (sélectionner les items à rembourser).
3. Rendre l'argent (espèces) ou faire le remboursement carte sur SumUp.
4. Le remboursement est tracé dans l'audit NF525. Ne PAS supprimer la commande originale.

## Annuler une commande borne avant paiement

Si le client a composé une commande borne mais part sans payer :
1. Onglet Commandes → trouver la commande **non payée** (statut orange).
2. Bouton **Annuler** → motif obligatoire (« client parti », « erreur composition », autre).
3. La commande passe en annulé, la cuisine reçoit une notification d'annulation.

## Variance de caisse > 2 € en fin de journée

En clôture (bouton **Clôture Z** vers 23:00 ou en fin de service) le POS compte attendu vs réel :
- Si écart ≤ 2 € : passe sans demande.
- Si écart > 2 € : **motif obligatoire** (vol probable, erreur de rendu, etc.). Cocher la case + note libre. Ne pas falsifier — l'écart est enregistré dans le rapport Z signé.

## Numéro d'appel ne s'affiche pas sur l'OSS

- Vérifier que le ticket est bien passé en `Prêt` côté cuisine (KDS).
- Si oui mais rien sur OSS : F5 sur l'écran OSS dans le navigateur. Si toujours rien : le numéro reste dans l'historique des prêts, appeler le client par son numéro manuellement.

---
Version 2026-05-28 · Le Cayenne

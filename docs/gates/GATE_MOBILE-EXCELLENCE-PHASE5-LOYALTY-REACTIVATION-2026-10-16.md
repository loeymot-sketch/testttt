# Gate brief — fidélité mobile / réactivation 30 jours

## Décision attendue du propriétaire

Répondre explicitement à ces trois points avant toute modification serveur ou
migration :

- Réactivation sous 30 jours : solde à **0** ou restauration des anciens points ?
- Opt-out : état persistant côté serveur obligatoire, oui ou non ?
- Après expiration des 30 jours : réactivation refusée, ou nouveau compte sans
  ancien solde ?

## Pourquoi ce gate est nécessaire

Le mobile actuel simule l’opt-out localement. Le backend ne possède pas encore
de contrat de réactivation ni de champ d’échéance. Modifier uniquement l’écran
créerait une divergence avec le site, la borne et la caisse, et pourrait exposer
ou restaurer un solde incorrect.

## État technique vérifié

- `POST /api/frontend/loyalty/opt-in` existe.
- Aucun endpoint backend opt-out/reactivation identifié.
- `loyalty_consents` journalise les consentements, mais ne porte pas d’état
  courant ni de deadline.
- Le paiement Mollie reste séparé et désactivé dans l’app.
- Aucun projet iOS natif (`.xcodeproj`, Capacitor ou Podfile) n’est présent dans
  le dossier de l’application mobile.

## Proposition de suite après décision

Créer un contrat versionné backend/mobile, l’implémenter avec tests Feature et
idempotence, puis exécuter les E2E opt-out/réactivation et l’audit RGPD avant de
passer au lot Mollie et au packaging Apple.

## Verdict

`HUMAN_GATE_REQUIRED`

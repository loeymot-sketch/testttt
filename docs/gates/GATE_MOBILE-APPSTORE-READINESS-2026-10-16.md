# Gate brief — Mobile App Store readiness — 2026-10-16

## Statut

**PENDING OWNER DECISION — ne pas auto-approuver.**

Cette fiche ouvre le périmètre nécessaire pour passer de la web app mobile
testable à une application iPhone signable. Elle ne constitue pas une
autorisation de modifier le backend, le paiement, l'authentification, la
fidélité ou le packaging natif.

## Décisions nécessaires du propriétaire

- Bundle ID et Team ID Apple Developer.
- Choix du wrapper natif (Capacitor recommandé pour cette base web) ou
  réécriture native.
- URL HTTPS publique de politique de confidentialité et coordonnées de
  support/review.
- Politique exacte de fidélité : désactivation, suppression, délai de 30 jours,
  points et historique, réauthentification.
- Autorisation d'activer Mollie en environnement de test puis production, avec
  secrets fournis hors dépôt.
- Images de marque manquantes pour Hawaï, Fuze Tea, Perrier et les autres
  boissons sans asset exact dans le pack actuel.

## Evidence

Voir `reports/planning/QA_APPSTORE_READINESS_2026-10-16.md` et le report de
validation fidélité `reports/execution/MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016/report.md`.

## Invariants protégés

- Prix serveur SSOT ; aucune tarification frontend inventée.
- Aucun changement `OrderStatus`, `branch_id`, dispatch ou service de commande.
- Aucun secret de paiement dans le dépôt.
- Aucun fichier de zone gelée modifié par ce brief.

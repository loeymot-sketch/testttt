# Rapport — MOBILE-EXCELLENCE-PHASE11-E2E-LOYALTY-REALIGNMENT

Date : 2026-10-16  
Scope : assertions E2E uniquement ; aucune logique produit modifiée.

## Verdict

`PASS` — suite mobile complète verte après réalignement des assertions sur le contrat backend live.

## Exécution finale

```text
npx playwright test tests/mobile-e2e --config=tests/mobile-e2e/playwright.config.js
29 passed (2.3m)
```

Couverture passée :

- OTP local, cooldown, expiration et vérification du code de développement
- catalogue visuel, personnalisation, panier et raccourcis de catégories
- contraste Home
- fidélité S01 à S15
- QR réel et rotation
- opt-out RGPD et affichage de la réactivation
- Apple Wallet / Google Wallet en mode notice V0
- course de redeem, double-tap et annulation sans débit client
- historique, filtres, pagination et purge des anciens libellés fictifs

## Cause des six échecs initiaux

Les tests contenaient des attentes historiques incompatibles avec le backend actuel :

- taux d’acquisition attendu à 1 au lieu de 10 points/€ ;
- redemption testée sous le plancher de 1000 points ;
- paliers 100/250/500 au lieu de 1000/2000 ;
- sélecteur DOM `COMMANDES` alors que la navigation expose le bouton accessible `Commandes`.

Les tests contrôlent désormais les valeurs live sans toucher à `mobile/data/loyalty.js`, à l’écran fidélité ou au backend.

## Invariants vérifiés

- Prix et paramètres métier lus depuis le contrat backend ; aucune règle de prix ajoutée au frontend.
- Aucun changement de `branch_id`, `OrderStatus`, dispatch, service de commande ou migration.
- Aucun fichier de zone gelée touché.
- Aucune approbation de la politique de réactivation pendant 30 jours : le gate Phase 5 reste ouvert séparément.

## Limites de release Apple restantes

Cette phase ne rend pas l’app soumettable à l’App Store. Restent à traiter :

1. Packaging natif iOS (aucun projet Xcode/Capacitor/Podfile détecté dans `testttt`).
2. SMS de production : les E2E utilisent encore le `dev_code` local ; aucun fournisseur SMS production validé.
3. Paiement Mollie : flag mobile désactivé et gate de parité Mollie non validé.
4. Loyalty : endpoint serveur d’opt-out/réactivation persistant et politique des 30 jours non décidés.
5. Catalogue : quatre visuels neutres (Orangina, Fuze Tea, Hawaï, Perrier) et les références demandées mais absentes du backend (Oasis Cassis, Schweppes Agrumes, bouteille Coca, « Fusty »).
6. Vérifications App Store finales : privacy manifest, permissions, compte développeur, notices de confidentialité, tests TestFlight et métadonnées.

`AUDIT_VERDICT: PASS`

`GPT_FINAL_AUDIT_VERDICT: PASS`

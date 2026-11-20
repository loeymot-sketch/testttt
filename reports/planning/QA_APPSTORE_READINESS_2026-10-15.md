# QA / App Store readiness — 2026-10-15

## Verdict actuel

**NEEDS_FIX / NO-GO pour soumission App Store.** L’application ne peut pas encore être déclarée prête : le dépôt contient une application web Laravel/Vue et des prototypes web mobile, mais aucun projet iOS/Xcode, archive, bundle identifier vérifiable ou configuration de signature. La conformité App Store Connect ne peut donc pas être validée sur un binaire réel.

## Preuves de test exécutées

- `npm run verify:boucle` : OK conditionnel ; binaires `claude` et gouvernance locale détectés, smoke API non exécuté.
- `npm test -- --reporter=verbose` : **563 fichiers / 4 631 tests : 4 628 passés, 3 ignorés, 1 erreur non gérée**. L'erreur est une incompatibilité CommonJS/ESM dans `html-encoding-sniffer` via `@exodus/bytes`. Warnings additionnels : mocks `router-link`, requêtes de test vers `localhost:3000` refusées et fallbacks IndexedDB/localStorage.
- `npm run test:e2e:full -- --retries=0` : BLOQUÉ au chargement d'un spec qui lit `process.env.AUDIT_ROUTES` sans valeur (`tests/Playwright/audit-dashboard-comptable-2026-08-29.spec.js:22`).
- `npm run test:e2e:smoke -- --retries=0` : **22 scénarios : 12 passés, 10 échoués**. Les 10 échecs touchent l'authentification POS/KDS et la navigation borne bloquée par l'overlay d'initialisation ; les 7 scénarios stock multi-branch exécutés passent, y compris l'isolation de branche. Aucun PASS de release ne peut être déduit tant que la fixture d'authentification et la configuration E2E dédiée ne sont pas reproductibles.

## Bloquants App Store à corriger / prouver

1. **Produit iOS livrable manquant — P0.** Ajouter le projet iOS natif ou Capacitor/React Native, `Info.plist`, bundle identifier, entitlements, configuration de signature, build Release et archive TestFlight. Les fichiers `mobile/` présents sont des prototypes web/JS et ne suffisent pas à une soumission.
2. **Parcours de paiement et modèle économique — P0.** Vérifier sur le binaire que la commande porte sur un bien physique consommé au restaurant/livré. Apple autorise alors Apple Pay/carte/autres moyens plutôt que l'In-App Purchase ; aucun achat de contenu numérique ne doit contourner IAP.
3. **Suppression de compte visible dans l’app — P0.** L’API `POST /api/auth/delete-account` existe et possède des tests backend, mais la présence d’un bouton/parcours client accessible dans l’interface iOS n’est pas démontrée. Ajouter et E2E-tester : réglages du compte → supprimer → confirmation → révocation des sessions → message sur les données comptables conservées légalement.
4. **Confidentialité — P0.** Publier une URL HTTPS de politique de confidentialité et renseigner App Store Connect App Privacy avec toutes les données collectées par l’app et ses partenaires (Firebase/FCM, cartographie, paiement, analytics éventuels, téléphone/e-mail/commandes). Le texte local “privacy_body” dans la borne n’est pas une politique publique suffisante.
5. **Connexion Apple — P0 si Google ou autre login tiers est proposé.** Les routes Apple/Google existent côté backend, mais la présence d’un bouton Sign in with Apple natif, le relay e-mail privé, la révocation des tokens Apple à la suppression et les tests de bout en bout ne sont pas prouvés.
6. **Review information — P1.** Préparer dans App Store Connect un compte de démonstration, les étapes exactes POS/client, les contraintes réseau, les données de paiement de test et l’explication du matériel restaurant. Apple indique que des instructions manquantes peuvent retarder ou faire échouer la review.
7. **Stabilité release — P0.** Corriger le harness E2E : `AUDIT_ROUTES` obligatoire sans garde, authentification POS dépendante de fixtures non activées, base de test dédiée non fournie par défaut. Ajouter un job CI reproductible qui termine par un résumé sans tests skipped non justifiés.
8. **Accessibilité et iPad/iPhone — P1.** Exécuter axe + parcours clavier/VoiceOver sur le binaire signé, rotations, petits écrans, Dynamic Type, contrastes et zones tactiles. Les sentinelles Vue sont utiles mais ne remplacent pas une vérification native.
9. **Données et sécurité — P1.** Vérifier production-like : HTTPS partout, ATS, stockage de tokens dans Keychain, absence de secrets machine dans les URLs/logs, consentement analytics avant collecte, export/suppression cohérents avec la politique et rétention NF525 explicitée.

## Prochaine action recommandée

Créer un cycle de correction dédié, hors modification produit improvisée : (a) rendre le harness E2E déterministe sur une base dédiée, (b) fermer les exigences iOS/App Store ci-dessus sur un premier build TestFlight, puis (c) relancer E2E mobile signé et audit Apple final avec preuves App Store Connect.

## Sources Apple consultées

- https://developer.apple.com/help/app-review/guideline-reference/5-1-1-account-deletion/
- https://developer.apple.com/help/app-store-connect/manage-app-information/manage-app-privacy
- https://developer.apple.com/app-store/review/guidelines/uk/
- https://developer.apple.com/app-store/review/
- https://developer.apple.com/design/human-interface-guidelines/sign-in-with-apple/

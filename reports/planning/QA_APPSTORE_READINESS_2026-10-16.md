# QA App Store readiness — 2026-10-16

## Verdict

**NO-GO / NEEDS_FIX.** Le parcours mobile fidélité est maintenant portable et
testé, mais le dépôt ne contient toujours pas de cible iOS signable. La
soumission Apple n'est donc pas techniquement démontrée.

## Evidence locale

- La version testable est une web app mobile sous `mobile/index.html`.
- Aucun `*.xcodeproj`, `*.xcworkspace`, `Info.plist`, `project.pbxproj`,
  `Podfile`, `capacitor.config.*`, Expo ou React Native n'est présent.
- `xcodebuild` est visible mais Xcode n'est pas installé/configuré : le Mac
  utilise uniquement `/Library/Developer/CommandLineTools`.
- Le dépôt n'a aucune dépendance Capacitor, Expo ou React Native.
- `mobile/index.html` force `onlineCardEnabled: false` et
  `mobile/api/client.js` envoie `payment_method: 1`; le checkout Mollie mobile
  n'est donc pas câblé.
- `POST /api/auth/delete-account` existe côté backend, mais aucun écran/appel
  mobile de suppression de compte n'existe.
- Aucune politique backend de restauration fidélité sous 30 jours n'a été
  trouvée; la désactivation actuelle efface le solde local.

## Apple requirements to close

1. Créer une cible iOS signable (choix Capacitor ou autre, bundle ID fourni par
   le propriétaire, `Info.plist`, entitlements, privacy manifest, signature,
   build Release et archive TestFlight).
2. Ajouter dans l'app le parcours visible de suppression de compte, avec
   confirmation, révocation de session et message sur les données conservées
   légalement.
3. Décider puis implémenter sous gate la politique fidélité 30 jours :
   désactivation vs suppression de compte, durée, conservation des points,
   réauthentification et synchronisation serveur.
4. Activer Mollie uniquement après gate propriétaire, secrets hors dépôt,
   idempotence, webhook et E2E de paiement réel/test.
5. Publier une URL HTTPS de confidentialité et compléter App Privacy,
   notamment pour les données téléphone/commandes/paiement et les partenaires.
6. Rejouer l'E2E sur le binaire signé, accessibilité native, rotation,
   Dynamic Type, réseau dégradé et instructions App Review.

## Sources Apple

- Account deletion : https://developer.apple.com/go/?id=ar-ad-support
- App Privacy : https://developer.apple.com/help/app-store-connect/manage-app-information/manage-app-privacy/
- Paiement de biens physiques : https://developer.apple.com/app-store/review/guidelines/uk/

`AUDIT_CHANNEL: fallback-read-only`
`AUDIT_HUMAN_CLAUDE: NOT_RUN — binaire claude absent`
`GPT_FINAL_AUDIT: NOT_RUN`

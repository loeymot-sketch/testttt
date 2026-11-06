# Méga correction — application mobile Le Cayenne

## État

La validation a été relancée sur le bon dossier mobile :

`/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile`

Le port `8087` servait auparavant un autre worktree (`mobile-image-convergence`). Cette
collision expliquait les anciens résultats : Tacos XL absent, anciens textes et attributs
`data-testid` manquants. Le serveur local a été arrêté puis relancé depuis le dépôt courant.

## Corrections validées

- accueil compact : `Prêt quand tu l'es.` ;
- logo agrandi et statut placé dessous ;
- nouveautés et images du wizard chargées depuis le code courant ;
- synchronisation fidélité après commande réussie déjà présente via `refreshFromServer()` ;
- fixture mobile `Tacos L` alignée sur le catalogue canonique : 8,90 €, total 25,70 €, 257 points estimés.

## Tests

- `mobile/tests/mobile-ui-excellence.spec.js` : PASS
- `mobile/tests/mobile-catalog-parity.spec.js` : PASS
- `mobile/tests/mobile-identity.spec.js` : PASS
- `mobile/tests/ordersParity.spec.js` : PASS
- Playwright mobile critique : **11/11 PASS**

Scénarios passés : OTP local, catalogue visuel, nouveautés navigables, personnalisation avec
images, hero/catégories, earn de fidélité, QR, redeem, Apple Wallet notice et opt-out RGPD.

## Blockers App Store restant

Ces éléments ne sont pas déclarés corrigés car ils nécessitent des systèmes externes et des
gates dédiées :

1. Paiement en ligne / Apple Pay : `onlineCardEnabled=false`, intégration Wallet encore V0.
2. Promotions : le mobile conserve une validation locale de codes ; le calcul métier doit
   être branché au endpoint backend de validation/pricing sans calcul frontend.
3. Notifications : bouton encore « bientôt disponibles » ; APNs, tokens appareil et campagnes
   serveur ne sont pas configurés.
4. Quatre packshots boissons restent neutres faute d’assets propriétaires validés : Orangina,
   Fuze Tea, Hawaï et Perrier.
5. Aucun build iOS signé/TestFlight n’a encore validé Apple Pay, permissions, push et paiement
   sur appareil réel.

## Invariants

Backend pricing SSOT, aucun prix métier ajouté au frontend, branch isolation, OrderStatus,
dispatch après commit, zones frozen et parité OrderService/FrontendOrderService : inchangés.

## Verdict

`NEEDS_EXTERNAL_GATES` — l’application mobile et ses E2E locaux sont propres, mais elle n’est
pas encore prête à être soumise à l’App Store tant que paiement, notifications, assets boisson
et validation TestFlight ne sont pas finalisés.

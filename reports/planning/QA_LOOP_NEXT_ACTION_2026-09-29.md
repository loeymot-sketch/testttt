# QA loop — prochaine action (29/09/2026)

## État vérifié

- Le dernier artefact Playwright archivé dans `reports/antigravity/` est
  `va-sys-05-central-management.json` (05/05/2026, `PASS_RUNTIME_LOCAL`) : il
  ne couvre pas la disponibilité de la borne VPS actuelle.
- Le test réel d’une nouvelle session Chrome sur `/kiosk/idle` redirige encore
  vers `/kiosk/login`; le bouton « Réessayer » conserve « Borne momentanément
  indisponible ».
- Le HTML de production contient `kioskAutoLogin: null`. La cause est la gate
  `KioskAutoLoginGate` : en production, les credentials ne sont injectés que
  si `KIOSK_AUTO_LOGIN_TRUSTED_IPS` ou `?machine_key=` est valide, en plus de
  `KIOSK_MACHINE_USERNAME/PASSWORD` et de la machine DB active.

## Validation exécutée

- PHPUnit auth/gate borne : **10 tests, 28 assertions, 0 échec**
  (`KioskAutoLoginGateTest`, `KioskAutoLoginGateResolverTest`,
  `KioskLoginApiTest`, `MultiKioskMachineLoginTest`).
- Vitest `tests/js/KioskLogin.spec.js` : **2/2 tests**, avec le warning attendu
  indiquant que les identifiants machine sont absents.

## Domaine et routage

- Domaine affecté : **déploiement/authentification machine de la borne**.
- Stratégie requise : `playwright-critical-flow` après correction de
  configuration, précédée d’un smoke HTTP du payload et d’un test auth ciblé.
- Implémentation à router vers le canal de correction déploiement/ops : aucun
  secret ne doit être ajouté au dépôt et aucune IP de proxy/LB ne doit être
  allowlistée comme si elle était la borne.

## Prochaine action bornée

1. Sur le VPS, vérifier la présence et la cohérence de la machine DB active,
   `KIOSK_MACHINE_USERNAME/PASSWORD`, puis choisir le chemin sécurisé
   `KIOSK_AUTO_LOGIN_SECRET` (`machine_key`) ou le CIDR réel de la borne.
2. Purger/reconstruire la configuration Laravel, puis vérifier que le HTML
   porte un payload non nul uniquement depuis le contexte autorisé.
3. Rejouer `/kiosk/idle` dans Chrome : idle visible, catégories accessibles,
   wizard et récapitulatif accessibles; conserver la vérification des prix
   côté serveur.
4. Exécuter `playwright-critical-flow` et consigner le rapport dans
   `reports/antigravity/latest.md` avant toute clôture.

## Blocage actuel

La correction nécessite l’accès de déploiement au VPS et le secret/choix
d’exploitation de la borne. Aucun correctif de code ne doit contourner cette
gate de sécurité; sans cette configuration, la borne restera indisponible.

## Recheck live complémentaire (29/09/2026)

- Deux nouveaux onglets Chrome, avec et sans `machine_key=invalid-test`,
  affichent le même écran d’indisponibilité; aucun payload auto-login n’est
  injecté (`kioskAutoLogin: null`).
- `POST /api/auth/kiosk-login` répond correctement `422` pour payload vide et
  `400` pour des identifiants invalides : l’API est joignable et fail-closed,
  mais la borne ne reçoit simplement aucun credential autorisé.

## Résilience idle/offline (29/09/2026)

- 10 suites Vitest ciblées (`kioskAuthInterceptor`, erreurs globales, démarrage
  clavier idle, warning idle, timeouts, référence offline, file offline,
  migration, race de synchronisation et V2) : **64 tests, 0 échec** sous Node
  `v20.20.2`.
- Les contrats locaux couvrent bien la reprise réseau, les races de file et les
  erreurs globales; ils ne peuvent toutefois pas rendre l’auto-login production
  disponible tant que le gate VPS n’est pas provisionné.

## PHPUnit complet (29/09/2026)

- Suite complète : **6 123 tests, 24 726 assertions**.
- Résultat : **1 échec**, 36 tests ignorés et 6 incomplets.
- L’unique échec est `FrozenZoneSha256BaselineSentinelTest` sur
  `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` : hash
  attendu `fcbe3755…ee256ac`, hash actuel `f8ecb111…e06465`. Cette zone est
  gelée; aucune modification ni mise à jour de baseline ne doit être faite sans
  LOCK/gate contresigné. Le résultat ne révèle pas un nouvel échec fonctionnel
  de prix ou de commande.

## Garde-fous release (29/09/2026)

- `pos:lint:pricing` : **OK**, 86 fichiers analysés; avertissement connu
  `signoff-pending` jusqu’au 27/10/2026.
- `pos:lint:status` : **OK**, 38 fichiers analysés.
- `perf:bundle-check` : **OK**, bundles kiosk/admin dans leurs budgets.
- `composer validate --strict --no-check-publish` : **OK**.

## Smoke Playwright critique (29/09/2026)

- `npm run test:e2e:smoke` sous Node 20 : **22 scénarios, 21 passés, 1 échec**
  (durée 1m55).
- Auth refresh POS, cycle caisse cash complet, navigation borne, login KDS,
  chargement KDS et synchronisation rupture de stock passent.
- L’échec unique est `04-kds-status.spec.js` : après le clic sur « Prêt » de
  la commande `7519`, le test attend à tort le texte « Commande N°7519 servie ».
  La capture montre que la commande reste `En cours` avec bouton « Prêt » et que
  la bande « Récemment servies » contient `7518`; le défaut est donc dans le
  contrat/assertion E2E (PREPARED ≠ DELIVERED), pas une preuve de panne prix ou
  d’affichage KDS. À corriger dans le test avant de relancer la gate.

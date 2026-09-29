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

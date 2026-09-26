# QA cross-surface — suivi 2026-09-25

Dernière vérification smoke : 2026-09-26 (Europe/Paris).

## Périmètre vérifié

- Caisse : supplément libre fiscalisé et conservation de deux sauces après modification.
- Borne : solde réel, code inconnu, inscription rapide et clavier numérique.
- KDS : accès chef, toolbar, légende HH/X et transition d'une commande vers les commandes servies.

## Résultat

**VERDICT: PASS — 11/11 scénarios Playwright Chromium.**

La campagne smoke complémentaire est également verte : **22/22 scénarios
Playwright Chromium**, zéro skip et zéro flaky, couvrant F5/auth POS, paiement
espèces complet, borne, KDS et rupture de stock multi-branche.

Commande exécutée sur la base E2E dédiée, avec un seul worker et sans retry :

```sh
E2E_BACKEND_AVAILABLE=1 FOODKING_E2E_DEDICATED_DB=1 \
PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 PLAYWRIGHT_NO_WEB_SERVER=1 \
node ./node_modules/@playwright/test/cli.js test \
  tests/Playwright/pos-manual-supplement-e2e.spec.js \
  tests/Playwright/pos-two-sauces-edit-e2e.spec.js \
  tests/Playwright/kiosk-loyalty-inscription-rapide-2026-09-25.spec.js \
  tests/Playwright/kiosk-loyalty-check-reel-2026-09-25.spec.js \
  tests/Playwright/kiosk-loyalty-register-e2e.spec.js \
  tests/e2e/04-kds-status.spec.js \
  --workers=1 --retries=0 --timeout=120000
```

Sortie : `11 passed (38.0s)`.

Smoke officiel : `22 passed (1.5m)` sur la même base E2E dédiée.

## Ajustements des fixtures de test

1. Le KDS V2 rend les cartes actives avec `.kds-card`; l'ancien attribut
   `data-kds-order-card` appartenait au layout legacy. Après le bump, la carte
   est volontairement détachée de la file active et apparaît dans
   « Récemment servies » : le test vérifie désormais cet état métier réel.
2. Le produit Cayenne impose explicitement le support et deux viandes. Le test
   renseigne ces choix au lieu de dépendre d'une viande fantôme ou d'un défaut
   de wizard. Il vérifie ensuite les deux sauces Andalouse et Algérienne après
   réouverture et confirmation.
3. Le parcours espèces ferme le dialogue « Session active », ouvre Sandwichs,
   compose Cayenne (pain, deux viandes, Andalouse), puis vérifie le paiement
   espèces réel et l'état métier final.
4. La découverte des fixtures stock repose sur `is_available` et non sur une
   ancienne valeur numérique de `status`. Le helper PHP transmet le code à
   `php artisan tinker` comme argument séparé, ce qui préserve les exceptions
   et JSON des scénarios défensifs.

Ces changements ne modifient ni le calcul du prix, ni la fiscalité, ni les
services de commande : ils rendent les preuves E2E conformes aux flux actuels.

## Couverture complémentaire déjà passée dans cette itération

- Vitest borne fidélité : `22` assertions, `4` fichiers passants.
- Contrats PHP fidélité : `27` tests passants.
- Smoke E2E global : `22/22` passants, dont `7/7` rupture stock (isolation,
  réactivation, quota auto-86 et rejet défensif).
- Build production : `npm run production` compilé avec succès.
- Contrats backend complémentaires : `MollieStructureTest` `22/22` et
  `AvailabilityServiceTest` `12/12`.
- Supervision caisse : `CashSessionReportControllerTest` `10/10` et
  `cashSessionReportStaleBadge.spec.js` `5/5`.
- Test production antérieur de la borne : ouverture de `/kiosk/login` suivie
  automatiquement de `/kiosk/idle` avec le menu affiché.

## Risques et suite

- **BLOCAGE SÉCURITÉ PRODUCTION (26/09/2026)** : une sonde HTTPS anonyme sur
  `https://vps-418872ac.vps.ovh.net/kiosk/login` reçoit encore un objet
  `kioskAutoLogin` non nul dans le HTML public. Les valeurs ont été
  volontairement masquées dans ce rapport et ne sont pas reproduites ici.
  Cela contredit le contrat de `KioskAutoLoginGate` (payload nul pour une IP
  publique non approuvée). Les tests locaux de garde restent verts
  (`KioskAutoLoginGateTest` 10/10, `KioskAutoLoginGateResolverTest` 17/17,
  `KioskMachineAndTerminalIndexGatedTest` 6/6) : le signal pointe donc vers
  une dérive de configuration/cache ou de déploiement sur le VPS, pas vers un
  échec de la logique testée. **Ne pas déclarer la production conforme ni
  fermer le gate avant correction côté environnement** : vérifier
  `APP_ENV`, `KIOSK_AUTO_LOGIN_TRUSTED_IPS` et, si nécessaire,
  `KIOSK_REQUIRE_MACHINE_LOGIN=true`, puis purger/reconstruire le cache de
  configuration selon la procédure de déploiement sans exposer de secrets.

- La protection `throttle:10,1` de vérification fidélité demeure active : le
  test du numpad isole sa réponse afin de ne pas masquer un 429 légitime de la
  route réellement testée dans le scénario dédié.
- Aucun nouveau changement applicatif n'est requis par cette passe QA.
- La clôture formelle du cycle complet reste soumise aux audits/gates déjà
  ouverts ; ce rapport atteste uniquement la campagne fonctionnelle ci-dessus.

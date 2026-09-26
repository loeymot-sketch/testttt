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

- **VÉRIFICATION PRODUCTION — garde réseau (26/09/2026)** : le VPS est en
  `APP_ENV=staging`, sans cache de configuration, avec une seule plage IPv6
  de confiance. La sonde HTTPS forcée en IPv4 reçoit `kioskAutoLogin: null`;
  la même sonde forcée en IPv6 (depuis la plage autorisée) reçoit un payload
  non nul. Les valeurs ont été volontairement masquées et ne sont pas
  reproduites ici. La garde fonctionne donc comme codée, mais la borne reste
  indisponible si elle sort de cette plage IPv6 ou arrive en IPv4. Pour une
  borne distante, utiliser le lien `machine_key` secret prévu par le runbook
  (ou ajouter son IP/CIDR réel à l’allowlist) puis refaire le smoke sur la
  borne; ne pas élargir l’allowlist à `0.0.0.0/0` ou `::/0`.
  Les tests locaux restent verts (`KioskAutoLoginGateTest` 10/10,
  `KioskAutoLoginGateResolverTest` 17/17,
  `KioskMachineAndTerminalIndexGatedTest` 6/6).
  Vérification complémentaire depuis le VPS : l’URL `machine_key` configurée
  produit bien un payload (clé non imprimée), tandis qu’une requête IPv4 sans
  clé produit `null`. Le correctif restant est donc opérationnel côté borne
  (URL de démarrage/liaison réseau), pas un changement de calcul de commande.

- **Déploiement** : avant la synchronisation contrôlée, le VPS servait le
  commit applicatif `c3dafb06` sur la branche attendue; l’arbre distant
  comportait 43 fichiers de sauvegarde/temporaire
  non suivis : aucun `reset`, nettoyage ou redéploiement forcé n’a été lancé.
  Les deux familles IPv4/IPv6 de `/api/healthz` répondent `status=ok` avec
  chaîne fiscale et dépendances vertes.

- **Synchronisation contrôlée (26/09/2026)** : le VPS a été fast-forwardé sur
  `763fe5e13`; PHP-FPM est actif et `/api/healthz` reste vert. Deux processus
  `mix --production` concurrents étaient suspendus; le processus lancé pour
  cette vérification a été interrompu sans toucher à l’autre ni aux fichiers
  temporaires. Aucun reset destructif ni purge de sauvegardes n’a été exécuté.

- **Build et smoke post-déploiement** : `npm run production` distant a compilé
  avec succès (Laravel Mix, 78,9 s), aucun processus de build ne reste actif,
  puis PHP-FPM a été rechargé. Les sondes finales restent conformes : IPv4
  public → `kioskAutoLogin: null`, IPv6 autorisée → payload présent, URL
  `machine_key` → payload présent, `/api/healthz` → `status=ok`.

- **Re-smoke E2E après déploiement** : le premier lancement local a été refusé
  avant exécution par Node `18.20.7` (Playwright exige Node `>=20`). Avec le
  runtime Node `20.20.2` déjà installé, la même commande officielle a produit
  **22/22 pass**, zéro skip et zéro flaky en 1,5 min : auth/F5 (2), POS cash
  complet (4), borne (5), KDS (4), rupture stock multi-branche (7).

- **Audit indépendant Claude terminal** : les preuves fonctionnelles et le
  déploiement sont confirmés, mais le verdict de clôture reste **NEEDS_FIX /
  ESCALATE** pour la gouvernance du cycle : `ACTIVE_CYCLE.md` et le rapport
  historique portent des phases contradictoires, et une mission
  `DRAWER-BRIDGE-VISIBILITY-20260917` touche une zone gelée sans gate
  propriétaire. Aucun fichier de gouvernance ni zone gelée n’a été modifié
  dans cette passe.

- **Contrats backend/UI revalidés** : `MollieStructureTest` **22/22**,
  `AvailabilityServiceTest` **12/12**, `CashSessionReportControllerTest`
  **10/10**, et `cashSessionReportStaleBadge.spec.js` **5/5**.

- **Gouvernance mesurée** : `check-execute-delegation.sh` trouve
  `45/209` rapports `RUN_*.md` avec la sentinelle (`21%`, seuil d’alerte
  `<50%`). `ACTIVE_CYCLE.md` contient toujours l’en-tête `CLOSED` mais une
  table méta `PHASE: EXECUTE`; le rapport historique garde
  `AUDIT_VERDICT: PENDING_EXTERNAL_REVIEW`. Ces écarts sont documentés comme
  réserves d’audit, sans modification automatique des artefacts de cycle.

- **Lisibilité cuisine / suppléments revalidée** :
  `KitchenTicketSymbolicFormatterTest` **20/20**,
  `KitchenTicketVirguleDuPrixTest` **7/7**,
  `KitchenFormuleVisibleTest` **20/20** et
  `KitchenSymbolPhpJsParityTest` **5/5**. Les assertions couvrent notamment
  Harissa → `HH`, les sauces multiples, l’absence de sauce, les sauces frites,
  les suppléments payants et le libellé tacos.

- **Campagne transverse post-déploiement** : **11/11 Playwright pass** en
  37,5 s (KDS 4, fidélité borne réelle/erreur/inscription 5, supplément libre
  POS 1, deux sauces conservées après modification 1). Aucun mock réseau pour
  les parcours fidélité et aucune page blanche observée.

- **Parité KDS JS finale** : un test historique attendait encore `TAC` dans
  le champ de surface `buildSymbolic().produit`, alors que le contrat courant
  rend explicitement `Tacos` (le code interne PHP bas niveau reste `TAC`).
  L’assertion de test a été réalignée, puis `kdsCustomization`,
  `kdsSymbolicViandeName` et `kdsSymbolicKidsMenu` passent **56/56**; le
  contrat PHP `KitchenTicketBolBaseTest` passe **4/4**.

- **État panier / édition revalidé** : `kioskWizardEditRestore` (7),
  `posCart` (3), `multiSauceNames` (9), `kioskModifierDepuisRecap` (11) et
  `kioskExtrasPartition` (17) passent, soit **47/47**. La conservation des
  sauces, la séparation sauce/supplément et la restauration après modification
  restent couvertes.

- **Prix / total backend revalidé** : `PricingIntegrityTest` **1/1**,
  `PosPricingSsotProofTest` **1/1**, `PosKioskPricingParityTest` **4/4**,
  `KioskQuoteIntegrityTest` **2/2**, `PricingServiceTest` **23/23** et
  `PricingServiceMultiQtyTest` **12/12** : **43/43**. Cela couvre le prix
  SSOT serveur, suppléments et quantités, deux/trois/quatre viandes, devis
  scellé, anti-tampering et supplément libre fiscalisé.

- **Emporter / livraison revalidé** : `checkoutTakeawayCopy` (2),
  `posDeliveryFlag` (6), `posOrderShowLabelWithoutValue` (9),
  `kioskIdleKeyboardStart` (3) et `posOrderShowComposition` (9) passent
  **29/29**. Les libellés « Confirmer à emporter » et « Livraison par nos
  livreurs bientôt. » sont présents, et la livraison désactivée reste masquée.

- **Fidélité revalidée** : six suites Vitest passent **52/52** (consentement,
  solde/remise, inscription rapide sans page blanche, rattachement POS,
  rachat), puis `KioskRegisterKeepsEmailTest` **6/6**,
  `LoyaltyRegisterNeRemetPasLeSoldeAZeroTest` **3/3** et
  `LoyaltyRegisterNoLeakTest` **4/4**. Total de cette passe : **65/65**.

- **Contrôle final de livraison (26/09/2026)** : `git diff --check` est
  propre; le VPS sert `763fe5e1`, PHP-FPM est actif, aucun build concurrent ne
  reste actif et `/api/healthz` répond `status=ok` (DB, Redis, WebSocket,
  chaîne fiscale).

- **Sonde HTTP finale IPv4** : `/` redirige normalement (**302**), `/login`,
  `/kiosk/login`, `/admin/dashboard` et `/api/healthz` répondent **200**.
  La réponse santé confirme encore DB, Redis, WebSocket et chaîne fiscale OK.

- **Robustesse borne/API backend** : `KioskAutoLoginGateTest` **10/10**,
  `KioskLoginEnumerationTest` **4/4**, `KioskPaymentConfirmAmountTest`
  **6/6**, `PaymentReconcileTest` **9/9**, `RevocationJetonBorneTest` **5/5**,
  `SsotInjectionHardeningTest` **6/6**, `KioskMachineTokenProfileBlockTest`
  **5/5** et `KioskTokenAdminBlockSentinelTest` **2/2** : **47/47**.

- **Build final local** : `npm run production` avec Node `20.20.2` compile
  sans erreur (Laravel Mix, 20,9 s); `git diff --check` reste propre et aucun
  fichier applicatif ciblé n’est laissé non committé.

- **Sécurité HTML/CSP/CORS** : `DemoCredentialsNotServedInHtmlTest` **4/4**,
  `AucunIdentifiantEnDurDansLeFrontTest` **2/2**,
  `ContentSecurityPolicyHeaderTest` **6/6** et `CorsTest` **4/4** :
  **16/16**. Aucun identifiant de démonstration n’est servi en production et
  les en-têtes de sécurité restent conformes.

- **Suite sécurité Feature complète** : `php artisan test
  tests/Feature/Security --no-coverage` passe **221/221 en 68,86 s**.
  Couverture : authz admin/POS, tokens borne, IDOR, rate limits, CORS/CSP,
  uploads, secrets, PII fidélité, anti-SSRF mail/impression et protections
  d’installation.

- **Suite Feature `KioskPhase1`** : **93 tests passés**, **1 skip documenté**
  (SQLite ne rejoue pas `ON DELETE SET NULL` sur une FK ajoutée par
  `ALTER TABLE`). Les endpoints menu/preview, prix SSOT, isolation de branche,
  cache, événements, consentement fidélité, allergènes, migrations et upsell
  sont verts; le skip est environnemental et ne masque pas un échec applicatif.

- **Suite Feature `Kiosk` complète** : **62/62 pass** en 12,18 s. Couverture
  du nettoyage/remboursement fidélité, promotion finale, garde auto-login,
  disponibilité/upsell, paiement exact et réconciliation idempotente,
  révocation de jeton, images du wizard et performance N+1 du menu.

- La protection `throttle:10,1` de vérification fidélité demeure active : le
  test du numpad isole sa réponse afin de ne pas masquer un 429 légitime de la
  route réellement testée dans le scénario dédié.
- Aucun nouveau changement applicatif n'est requis par cette passe QA.
- **Suite Feature `Pos` complète (26/09/2026)** : `php artisan test
  tests/Feature/Pos --no-coverage` passe **386/386 en 98,50 s**. La passe
  couvre les encaissements cash/carte et mixtes, devis scellés et suppléments
  libres fiscalisés, stock, tiroir et clôture, fidélité/points, reçus,
  commandes web/téléphone/livraison, tickets cuisine, remboursements,
  autorisations et isolation de branche. Aucun échec ni skip dans cette suite.
- La clôture formelle du cycle complet reste soumise aux audits/gates déjà
  ouverts ; ce rapport atteste uniquement la campagne fonctionnelle ci-dessus.

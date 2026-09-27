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
- **Re-sonde web publique (26/09/2026)** : les routes `/login`, `/kiosk/login`,
  `/admin/dashboard`, `/admin/settings/kiosk-setup` et `/api/healthz` répondent
  toutes **HTTP 200** depuis l’extérieur. Le VPS est toujours sur `763fe5e1`
  et `php8.1-fpm` est actif. Cette sonde confirme la disponibilité HTTP, sans
  se substituer à une session authentifiée ni à un test de paiement réel.
- **Contrôle de gouvernance (26/09/2026)** : `npm run verify:boucle` est
  conditionnellement vert (binaires Claude/Codex présents; les smoke API
  facturés sont volontairement non lancés). `git diff --check` est propre.
  `check-execute-delegation.sh` reste en avertissement à **45/209 (21 %)**,
  et l’artefact d’audit historique reste `AUDIT_VERDICT: REWORK` tandis que
  `.cursor/ACTIVE_CYCLE.md` conserve des métadonnées contradictoires
  (`PHASE: CLOSED` dans l’en-tête, `PHASE: EXECUTE` dans la table). Ces points
  empêchent une clôture formelle honnête; ils ne constituent pas un échec des
  tests produit ci-dessus.
- **Diagnostic borne réseau (26/09/2026)** : la sonde HTTPS forcée IPv4
  retourne `kioskAutoLogin: null`, tandis que la sonde IPv6 autorisée retourne
  bien un payload d’auto-connexion (valeurs sensibles masquées dans ce
  rapport). Le comportement observé est donc cohérent avec la liste blanche
  IPv6 configurée, et non avec une page blanche applicative. La borne doit
  utiliser le chemin réseau autorisé ou son URL `machine_key`; aucune ouverture
  globale IPv4 n’a été ajoutée.
- Le contrat backend correspondant a été rejoué immédiatement :
  `KioskAutoLoginGateTest` **10/10** (IP publique bloquée, allowlist IPv6,
  chemin secret, reload grant, anti-spoof `X-Forwarded-For`, hors-route).
- **Passe Vitest exhaustive (26/09/2026)** : `npm test` sous Node système
  18.20.7 produisait un faux négatif de 5 assertions `playwrightConfig` et
  une erreur ESM/jsdom, car Playwright exige Node >=20. Rejouée avec Node
  `20.20.2`, la campagne complète passe **556 fichiers, 4509 tests, 3 skips
  documentés** en 194,03 s. Aucune suite n’échoue; les avertissements Vue,
  mocks réseau locaux et imprimante hors papier sont non bloquants et déjà
  attendus par les tests.
- **Audit i18n complémentaire (26/09/2026)** : `npm run i18n:audit` termine
  sans erreur de parsing (80 fichiers Laravel). Il signale 11 clés FR Vue et
  5 clés FR Laravel manquantes, principalement des clés dynamiques construites
  par suffixe (`status_`, `channel_`, `permission_`) ainsi que des repliés
  explicites; les avertissements observés (`menu.roue`, `min_hint`) sont donc
  recensés pour nettoyage dédié, mais n’ont pas provoqué d’échec fonctionnel
  dans la campagne exhaustive.
- **Garde-fous architecture/performance (26/09/2026)** :
  `pos:lint:status` passe (**38 fichiers**). `pos:lint:pricing` reste en échec
  sur **4 occurrences** (un calcul d’écart d’encaissement dans
  `PosCounterCollectModal.vue` et trois blocs de calcul borne sans sign-off
  conforme); aucune modification n’a été improvisée hors plan. Le contrôle
  bundle retourne **17 dépassements** sur des artefacts `kiosk-errors`,
  `kiosk-shell` et `kiosk-wizard-step` (les bundles POS/admin n’ont pas de
  dépassement budgété). Ces deux alertes sont désormais explicitement
  tracées comme travaux de correction/gate, distincts des tests fonctionnels
  verts.
- **Rebuild bundle (26/09/2026)** : `npm run production` sous Node 20 compile
  en **20,99 s**; les artefacts courants générés sont sous budget
  (`kiosk-errors` 19 KB, `kiosk-shell` 280 KB, `kiosk-wizard-step` 130 KB).
  Le script global continue toutefois de remonter les mêmes **17 anciens
  fichiers hashés** (90/740–769/396 KB) laissés dans `public/js`; le finding
  est donc un problème de purge/retention des artefacts historiques, pas une
  régression du bundle courant. Aucun fichier ancien n’a été supprimé sans
  autorisation explicite.
- La clôture formelle du cycle complet reste soumise aux audits/gates déjà
  ouverts ; ce rapport atteste uniquement la campagne fonctionnelle ci-dessus.
- **Campagne PHPUnit globale (26/09/2026)** : `php artisan test --no-coverage`
  a exécuté **6 117 tests**, dont **6 079 passés**, **36 skips**, **6
  incomplets** et **2 échecs**, en **1320,63 s**. Les deux échecs à traiter
  sont : (1) `I18n\\LeJournalDActiviteDitLeStatutPasSaCleTest`, dette arabe
  `ar/all.php` à 89 clés brutes contre un plafond documenté de 88; (2)
  `Idempotency\\IdempotencyRequiredRoutesCoverageTest`, les routes KDS
  `api/admin/kds-order/items/{orderItem}/bump` et `/recall` sont munies du
  middleware d’idempotence mais absentes de `idempotency.required_routes`.
  Ces deux points restent ouverts et empêchent de qualifier la campagne
  globale PASS.
- Les **6 tests incomplets** correspondent aux scénarios de charge S72/S73
  qui exigent encore une fixture HTTP `payment-confirm` kiosk; les **36
  skips** sont documentés par les tests (MySQL-only, contraintes SQLite,
  CategoryUpdated non émis, surfaces de pricing gelées et onboarding
  structurel). Aucun échec POS/Kiosk ciblé ni régression prix/KDS n’a été
  observé dans les campagnes dédiées précédentes.
- **Remédiation ciblée (26/09/2026)** : ajout du libellé arabe manquant
  `label.oss_main_aria` (la dette i18n revient à 88, sous le cliquet) et
  déclaration des deux routes KDS item-level (`items/*/bump` et
  `items/*/recall`) dans `idempotency.required_routes`. Vérification : familles
  I18n + Idempotency **8/8**, sentinelle de couverture idempotence **1/1**, et
  `KdsItemReadySyncTest` **7/7**. Les deux échecs de la campagne globale sont
  ainsi corrigés; une campagne globale complète reste à rejouer pour produire
  un nouveau bilan indépendant.
- **Revalidation distante (27/09/2026)** : les routes publiques `/login`,
  `/kiosk/login`, `/admin/dashboard`, `/admin/settings/kiosk-setup` et
  `/api/healthz` répondent **HTTP 200**. Le healthz distant confirme
  `status=ok`, DB/Redis/WebSocket/fiscal chain `ok` et `queue_pending=0`.
  La revalidation ne simule pas une session authentifiée ni un paiement.
- **Rejeu ciblé (27/09/2026)** : I18n + Idempotency + KDS item sync restent
  verts (**8 + 1 + 7 tests**, soit **16/16**). `npm run i18n:audit` parse les
  80 fichiers Laravel sans erreur; les clés manquantes restantes sont
  recensées comme dette de traduction (notamment locales Vue non françaises),
  sans régression de parsing.
- **Campagne KDS bornée (27/09/2026)** : les suites KDS/Kds incluant bump,
  recall, synchronisation temps réel, snapshot, isolation de branche et
  commandes non released passent **91/91 en 23,37 s**. Les campagnes POS et
  Pricing dédiées précédentes restent respectivement **386/386** et **43/43**;
  aucun changement ne les a touchées depuis leur dernier passage.
- **Lots backend bornés (27/09/2026)** : `tests/Feature/Loyalty` passe
  **93/93 en 23,03 s**, `tests/Feature/Order` passe **109/109 en 26,23 s**,
  et `tests/Feature/Security` passe **221/221 en 70,78 s**. Aucun skip ni
  échec dans ces trois lots. Ces résultats remplacent les anciennes mesures
  partielles et confirment les invariants fidélité, transitions de commande,
  prix serveur, autorisations, CSP/CORS et protections anti-rejeu.
- **Lots intégration (27/09/2026)** : `tests/Feature/Sync` passe **29/29 en
  5,69 s**, `tests/Feature/Web` **8/8 en 2,36 s** et
  `tests/Feature/Webhooks` **31/31 en 7,24 s**. Les invariants dispatch après
  commit, outbox/rejeu, canaux client, expiration des commandes web et
  idempotence/signatures webhook sont verts.
- **Lots pilotage (27/09/2026)** : `tests/Feature/Reports` passe **29/29 en
  5,41 s**, `tests/Feature/Dashboard` **96/96 en 21,91 s** et
  `tests/Feature/Delivery` **50/50 en 12,41 s**. Les exports PDF/tableur,
  compteurs dashboard, SLA, frais/zones de livraison, PII et isolation de
  branche restent conformes.
- **Lots paiement/dispatch (27/09/2026)** : `tests/Feature/Payment` passe
  **86/86 en 22,47 s**, `tests/Feature/Refund` **33/33 en 8,25 s**,
  `tests/Feature/Outbox` **81/81 en 18,39 s** et `tests/Feature/Queue`
  **13/13 en 1,27 s**. Encaissement fiscal, remboursements cash/carte,
  fidélité, outbox après commit, déduplication et budgets de retry sont verts.
- **Lots catalogue/composition (27/09/2026)** : `tests/Feature/Catalog` passe
  **48/48** avec **3 skips documentés** (gap CategoryUpdated), `tests/Feature/Menu`
  **164/164** avec **10 skips documentés**, et `tests/Feature/Composer`
  **109/109** avec **2 skips gelés** liés au contrôle de version pricing.
  Les projections POS/borne, suppléments, disponibilité, prix et profils
  composer sont verts; les skips restent explicitement rattachés à leurs gates.
- **Lots plateforme (27/09/2026)** : `tests/Feature/Migrations` passe **8/8**
  avec **1 skip SQLite documenté**, `tests/Feature/Observability` **134/134
  en 31,54 s** et `tests/Feature/Settings` **49/49 en 20,44 s**. Les
  migrations/rehearsals, health/readiness, corrélation, CSP reports, sécurité
  des réglages et diffusion multi-branches sont verts.
- **Lots OSS/onboarding/roue (27/09/2026)** : `tests/Feature/OSS` passe
  **16/16 en 4,40 s**. `tests/Feature/Onboarding` passe **222 tests** avec
  **4 incomplets** en **55,39 s**; les incomplets sont les scénarios
  structurels explicitement marqués par la suite et aucun échec n'est relevé.
  `tests/Feature/Wheel` passe **253/253 en 64,27 s**. Les protections de
  session, branche, coupons, stock, tirage cryptographique et confidentialité
  restent vertes. Ces lots complètent la couverture borne/compte fidélité;
  le bilan global indépendant reste à rejouer avant clôture formelle.
- **Test navigateur distant réel (27/09/2026)** : `/kiosk/login` répond
  **HTTP 200**, mais l'écran reste bloqué sur « Borne indisponible pour le
  moment ». Le HTML public injecte `kioskAutoLogin: null`; les logs console
  confirment `Auto-login indisponible (identifiants machine absents)` et les
  appels de rattrapage retournent **401**. Le healthz reste sain (DB, Redis,
  WebSocket, chaîne fiscale et file OK), donc le défaut est le
  **provisionnement de la borne distante** : allowlist IP/CIDR ou lien
  `?machine_key=` non configuré côté déploiement. Les tests de sécurité du gate
  restent **10/10** (`KioskAutoLoginGateTest`); il ne faut pas désactiver ce
  gate. Action de déploiement restante : renseigner les `KIOSK_MACHINE_*` et
  `KIOSK_AUTO_LOGIN_TRUSTED_IPS`/`KIOSK_AUTO_LOGIN_SECRET` selon la borne,
  exécuter `foodking:ensure-kiosk-machine`, vider le cache de configuration,
  puis rejouer le parcours navigateur jusqu'à `/kiosk/idle`.
- **Complément Kiosk (27/09/2026)** : `tests/Feature/Kiosk` passe **62/62 en
  12,72 s**. Playwright Chromium Node 20 contre le VPS passe **4/4 en 8,9 s**
  sur la surface publique (`/kiosk/login` accessible, message visible,
  aucune erreur JavaScript fatale, configuration `kioskMenuPricing` présente).
  Le scénario interactif catégories/produit est **1 skipped** car la page
  redirige vers `/kiosk/login` sans auto-login; ce skip est attendu et relie
  directement le test E2E au défaut de provisioning décrit ci-dessus.
- **Kiosk multi-branche/sécurité (27/09/2026)** : les lots
  `KioskMultiBranch`, `KioskPhase5`, `KioskPhase7` et `KioskSecurity` passent
  **8/8**. L’allowlist de locale, la priorité header/query, les refus 400
  structurés et les événements d’observabilité sont validés sans fuite de
  branche.
- **Campagne PHPUnit globale indépendante (27/09/2026)** : `php artisan test
  --no-coverage` termine en **1 311,03 s** avec **6 080 passés, 36 skips,
  6 incomplets et 1 échec**. Les deux échecs historiques (dette i18n et
  couverture idempotence KDS) ne réapparaissent plus. L’unique échec est le
  sentinel `FrozenZoneSha256BaselineSentinelTest` :
  `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` réel
  (`f8ecb111…`) ne correspond pas à la baseline (`fcbe3755…`). L’écart est
  traçable au commit `c21628767` (trois annotations de commentaire de
  pricing), mais aucune baseline n’a été mise à jour dans ce cycle. Aucun
  fichier frozen ni baseline n’a été modifié pour faire passer le test; le
  verdict global reste donc **NEEDS_FIX/GATE** jusqu’à contreseing propriétaire
  et mise à jour atomique de la baseline, ou retour explicite à la version
  approuvée.
- **Reproductibilité du gate (27/09/2026)** : le test ciblé
  `FrozenZoneSha256BaselineSentinelTest` reproduit l’échec en **0,15 s** avec
  exactement le même couple `fcbe3755…` (baseline) / `f8ecb111…` (réel).
  Aucun lock contresigné exploitable n’a été trouvé pour autoriser une
  synchronisation automatique; la baseline et le fichier frozen restent donc
  volontairement inchangés.
- **Matrice HTTP distante (27/09/2026)** : `/login`, `/kiosk/login`,
  `/admin/dashboard` et `/admin/settings/kiosk-setup` répondent **200**;
  `/api/healthz` répond **200** avec l’état sain déjà relevé. Avec l’en-tête
  applicatif public mais sans session, `/api/frontend/menu` et un POST
  `/api/frontend/pricing/preview` répondent **401** (`Unauthenticated`), et un
  POST `/api/auth/kiosk-login` avec identifiants factices répond **400** avec
  le message métier « Identifiants invalides ou compte bloqué ». Les contrôles
  d’authentification et de refus sont donc cohérents; cela ne lève pas le
  provisioning réel de la borne, qui exige ses identifiants machine.
- **Garde-fous statiques (27/09/2026)** : `npm run pos:lint:pricing` est **OK**
  (86 fichiers; avertissement de sign-off toléré jusqu’au 27/10/2026) et
  `npm run pos:lint:status` est **OK** (38 fichiers). `npm run i18n:audit`
  parse les **80 fichiers Laravel sans erreur**, mais retourne le code 1 pour
  la dette de clés manquantes (Vue : fr 11, en 112, ar 644, de 922, bn 923;
  Laravel : fr 5, en 21, ar 62, de 89, bn 86). Cette dette est distincte du
  correctif i18n ciblé déjà validé et reste à traiter par lot dédié.
- **Suite frontend complète (27/09/2026, Node 20)** : `npm test -- --run`
  passe **556 fichiers, 4 509 tests passés et 3 skips** (4 512 tests
  collectés) en **193,74 s**. Les avertissements Vue/console observés sont
  limités aux fixtures et chemins d’erreur explicitement testés; aucun échec
  Vitest, aucune régression wizard/POS/KDS/borne.
- **Budget bundles (27/09/2026)** : `npm run perf:bundle-check` est en échec
  sur **17 artefacts**. Dépassements mesurés : `kiosk-errors` **90 KB / 50 KB**
  (2 fichiers), `kiosk-shell` **741–769 KB / 350 KB** (13 fichiers), et
  `kiosk-wizard-step` **396 KB / 150 KB** (2 fichiers). Les autres bundles
  contrôlés passent ou n’ont pas de budget déclaré. Aucun fichier bundle n’a
  été supprimé et aucun budget n’a été relevé automatiquement; ce point reste
  une action build dédiée (nettoyage/agrégation des artefacts ou budget validé).
- **Correction du contrôle bundles (27/09/2026)** : la cause était un faux
  positif du script qui parcourait 313 anciens fichiers hashés ignorés par Git.
  `tools/perf/check_bundle_budget.mjs` filtre maintenant sur les **15 fichiers
  référencés par `public/mix-manifest.json`**, avec repli conservateur si le
  manifest est absent ou illisible. `node --check` est vert et le contrôle
  corrigé passe : `app.js` 2 401/5 000 KB, `kiosk-errors` 19/50 KB,
  `kiosk-shell` 280/350 KB, `kiosk-wizard-step` 130/150 KB, sans dépassement.
- **Smoke E2E critique local (27/09/2026, Node 20)** : `npm run test:e2e:smoke`
  démarre le serveur Laravel local et passe **22/22 en 1 min 30 s**. Les
  parcours auth/F5, POS cash, borne, KDS et synchronisation des ruptures de
  stock sont verts, sans skip ni flaky observé. Le parcours borne interactif
  distant reste séparément bloqué par le provisioning `kioskAutoLogin` absent.
- **Fidélité ciblée (27/09/2026)** : `vendor/bin/phpunit --no-coverage
  tests/Feature/Loyalty tests/Feature/Security/LoyaltyRegisterNoLeakTest.php`
  passe **93/93 tests et 340 assertions en 22,10 s**. Le lot couvre
  l’inscription email/téléphone, la conservation de l’email borne, la
  connexion web, les variantes de téléphone, les cycles earn/redeem/refund et
  les contrôles de fuite inter-branche.
- **Test navigateur distant réel (27/09/2026)** : ouverture de
  `https://vps-418872ac.vps.ovh.net/kiosk/login` dans Chrome affiche de façon
  reproductible « Borne indisponible pour le moment ». Le log applicatif
  associé est explicite : `[Kiosk] Auto-login indisponible (identifiants
  machine absents)`; les seuls autres warnings observés proviennent d’une
  extension Chrome tierce. Le défaut restant est donc le provisioning distant,
  non un crash JavaScript de la page.
- **Parcours navigateur POS local (27/09/2026, Playwright Chromium)** : les
  deux scénarios réels ciblés passent **2/2 en 10,9 s** : ajout d’un supplément
  libre nommé (`Olives`, 1,25 €) avec total cohérent, puis réouverture et
  modification d’une ligne Cayenne en conservant simultanément les sauces
  Andalouse et Algérienne. Le serveur émet seulement des avertissements PHP
  de dépréciation dans une dépendance tierce, sans échec de parcours.
- **Libellés et placement cuisine (27/09/2026)** : les suites ciblées
  `KitchenTicket*` passent **44/44 tests, 102 assertions**; le formateur
  symbolique passe **20/20 tests, 78 assertions**. Les sorties scellées
  vérifient Harissa=`HH`, sans sauce=`X`, tacos sans taille, sauces produit
  regroupées sur la ligne 1, sauces frites dans le badge menu, et absence de
  « Sauce supplémentaire » anonyme dès que le nom est récupérable.
- **Supplément libre et prix scellé (27/09/2026)** : le lot ciblé
  `QuoteBinding`/pricing/supplément passe **7/7 tests, 37 assertions**. Il
  confirme que le supplément libre reste lié au devis serveur et ne peut pas
  modifier silencieusement le montant au moment de la validation.
- **Synchronisation KDS ciblée (27/09/2026)** : les suites de synchronisation
  d’items, board release, timing et autorisations cuisine passent **16/16 tests,
  45 assertions**; aucune divergence de statut ou fuite d’accès détectée.
- **Re-run de stabilité E2E (27/09/2026, Node 20)** : une seconde exécution
  indépendante de `npm run test:e2e:smoke` passe à nouveau **22/22 tests en
  1 min 30 s**. Les deux runs successifs sont donc reproductibles; aucun flaky,
  aucun échec auth/POS/borne/KDS/stock. Les seuls warnings restent les
  dépréciations `${var}` d’une dépendance PHP tierce au démarrage du serveur.
- **Garde-fous release revalidés (27/09/2026)** : `npm run pos:lint:pricing`
  passe sur **86 fichiers** (warning de sign-off jusqu’au 27/10/2026),
  `npm run pos:lint:status` passe sur **38 fichiers**, et
  `npm run perf:bundle-check` passe sur les **15 bundles référencés** du
  manifest. La sentinelle `FrozenZoneSha256BaselineSentinelTest` reste le seul
  échec ciblé, reproduit avec le même hash baseline/réel pour
  `KioskWizardComponent.vue`; baseline et fichier frozen laissés inchangés en
  attente du gate propriétaire.
- **Analyse du drift frozen (27/09/2026)** : `c21628767` ne modifie que trois
  commentaires d’annotations pricing (`owner gate DATE` → `owner — date:`),
  sans ligne exécutable. Toutefois, le lock cité
  `plans/LOCK_KIOSK_FRITES_SAUCE_BILLING_2026-07-29.md` conserve son sign-off
  propriétaire **non coché** (« rebuild bundles + validation borne réelle »).
  Le sentinel rouge est donc un blocage de gouvernance réel, pas une raison de
  régénérer automatiquement la baseline; aucun fichier frozen ni baseline n’a
  été modifié.
- **Recontrôle production + i18n (27/09/2026, 17:59 CEST)** :
  `/api/healthz` répond **200** avec `status=ok`, DB/Redis/WebSocket/
  `fiscal_chain=ok` et `queue_pending=0`; `/login`, `/kiosk/login`,
  `/admin/dashboard` et `/admin/settings/kiosk-setup` répondent également **200**.
  `npm run i18n:audit` retrouve les mêmes compteurs (Vue fr 11, en 112, ar 644,
  de 922, bn 923; Laravel fr 5, en 21, ar 62, de 89, bn 86), parse les 80
  fichiers Laravel sans erreur et reste en code 1 uniquement pour cette dette
  de clés manquantes.
- **Contrôle contenu public (27/09/2026)** : le site public
  `https://www.lecayenne.fr/` affiche encore « tout se prend à emporter ; la
  livraison passe par Uber Eats ». Le dépôt contient pourtant déjà les clés
  FoodKing `order_takeaway`, `confirm_takeaway` et `delivery_coming_soon`
  (« Livraison par nos livreurs bientôt. »). Il existe donc une divergence
  entre le contenu public déployé et le texte demandé; elle reste à valider et
  déployer sur la surface publique concernée.
- **Réessai borne en navigateur réel (27/09/2026, Chrome)** : après ouverture
  de `/kiosk/login`, le clic sur **Réessayer** revient en 1,5 s au même état
  « Borne indisponible pour le moment », sans page blanche, écran vide ni
  spinner persistant. Le journal répète uniquement le diagnostic serveur
  `Auto-login indisponible (identifiants machine absents)` (et les warnings
  d’extension Chrome tiers); le comportement d’erreur est donc stable et
  explicite, le provisioning restant le seul défaut fonctionnel.
- **Cohérence checkout à emporter/livraison (27/09/2026)** : le composant
  `CheckoutComponent.vue` référence bien `order_takeaway`,
  `confirm_takeaway` et `delivery_coming_soon`; `tests/js/checkoutTakeawayCopy.spec.js`
  passe **2/2 tests** avec les textes français attendus. L’écart « Uber Eats »
  constaté précédemment est donc limité à la surface publique `lecayenne.fr`,
  pas au checkout FoodKing versionné.
- **Parité frontend JavaScript (27/09/2026)** : les suites
  `kdsSymbolic.spec.js`, `kioskFritesSauceBilling.spec.js` et
  `checkoutTakeawayCopy.spec.js` passent ensemble **39/39 tests** en 1,61 s.
  Les symboles cuisine, le calcul de sauce frites et les libellés à emporter
  sont donc cohérents côté miroir JS.
- **Preuve UI publique Chrome (27/09/2026)** : le DOM visible de
  `lecayenne.fr/#menu` affiche « Commande en ligne, retrait sur place » et le
  lien « Aussi sur Uber Eats », sans « Livraison par nos livreurs bientôt ».
  Cette observation visuelle confirme le drift de contenu déjà détecté par
  HTTP; aucun panier ou compte utilisateur n’a été modifié pendant le test.

## Synthèse de décision — état courant au 27/09/2026

| Domaine | Preuve actuelle | Verdict |
|---|---|---|
| Backend ciblé fidélité/prix/KDS | 93 + 152 + 16 tests ciblés verts | PASS |
| Frontend/Vitest | 4 509 tests passés, 3 skips | PASS |
| E2E critique local | 22/22 sur deux exécutions indépendantes | PASS |
| POS suppléments/deux sauces | 2/2 parcours navigateur verts | PASS |
| Fidélité navigateur locale | 5/5 scénarios (inscription, numpad, solde, erreur, email) | PASS |
| Cuisine HH/X/tacos/sauces | 44 + 20 tests ciblés verts | PASS |
| Production HTTP/healthz | routes 200, santé OK, file 0 | PASS |
| Kiosk public distant | 5/5 scénarios Playwright, navigation catégories/produits incluse | PASS |
| Borne distante interactive | auto-login provisionné, parcours interactif vert | PASS |
| Auth POS distante/F5 | 0/2 : HTTP 400 identifiants invalides/compte bloqué | NEEDS_REMOTE_AUTH_PROVISIONING |
| Frozen-zone sentinel | drift commentaire-only, lock owner non signé | NEEDS_OWNER_GATE |
| Site public contenu livraison | texte Uber Eats encore servi | NEEDS_PUBLIC_COPY_DEPLOY |
| i18n global | dette de clés, parsing sans erreur | NEEDS_DEBT_BATCH |

**Verdict global : NEEDS_FIX/GATE.** Les parcours applicatifs testés sont verts;
la clôture reste interdite tant que le compte POS distant, le gate frozen et la
décision de contenu public ne sont pas traités/validés par leurs propriétaires.

Plan de reprise borné : [`QA_LOOP_NEXT_ACTION_2026-09-27.md`](QA_LOOP_NEXT_ACTION_2026-09-27.md).

**Attente vérifiée (27/09/2026, 18:05 CEST)** : un nouveau contrôle externe
confirme `kioskAutoLogin: null` sur `/kiosk/login`; `/api/healthz` reste `status=ok`
avec DB/Redis/WebSocket/fiscal chain OK et `queue_pending=0`; le site public sert
toujours « la livraison passe par Uber Eats ». Aucun changement de déploiement ou
de copie publique n’est intervenu depuis le contrôle précédent.
- **Recontrôle HTTP distant (27/09/2026, 18:11 CEST)** : `/api/healthz` répond
  toujours **200** (`status=ok`, DB/Redis/WebSocket/fiscal chain OK,
  `queue_pending=0`); les shells `/login`, `/admin/dashboard` et `/kiosk/login`
  répondent tous **200**. Ce contrôle confirme la disponibilité réseau et du
  serveur, mais ne transforme pas les blocages d’authentification/provisioning
  en PASS fonctionnel.
- **Fidélité navigateur local (27/09/2026, Playwright Chromium)** :
  `kiosk-loyalty-inscription-rapide-2026-09-25.spec.js` passe **2/2 tests en
  5,8 s**. Le premier reproduit le vrai `/loyalty/check` pour un numéro inconnu,
  bascule automatiquement sur le prénom seul, crée le compte sans ressaisie du
  téléphone et sans erreur/page blanche; le second vérifie que le numpad lance
  automatiquement la vérification au 10e chiffre.
- **Variantes fidélité navigateur (27/09/2026)** : les specs
  `kiosk-loyalty-check-reel-2026-09-25.spec.js` et
  `kiosk-loyalty-register-e2e.spec.js` passent **3/3 tests en 7,7 s** : solde
  réel affiché, code inconnu rendu en erreur explicite sans page blanche, et
  inscription avec email suivie de l’affichage du solde.
- **Smoke Kiosk distant ciblé (27/09/2026)** : `tests/e2e/03-kiosk-wizard.spec.js`
  contre `https://vps-418872ac.vps.ovh.net` passe désormais **5/5 scénarios en
  19,2 s**, y compris la navigation interactive catégories/produits. Le
  provisioning auto-login est maintenant actif sur le déploiement; aucune
  valeur sensible n’est reproduite dans ce rapport.
- **Auth POS distante/F5 (27/09/2026)** : `tests/e2e/01-auth-refresh.spec.js`
  exécuté contre `https://vps-418872ac.vps.ovh.net` échoue **0/2 scénarios**,
  y compris après retry Playwright. Les deux tentatives reçoivent
  `POST /api/auth/login` en **HTTP 400** avec `Identifiants invalides ou compte
  bloqué` pour le compte de test configuré (`pos@lecayenne.fr`). Le navigateur
  reste sur `/login` et affiche l’erreur explicite; aucun crash/page blanche
  n’est observé. Cette preuve ne permet pas de valider F5 distant et requiert
  un compte POS de test actif/provisionné ou des secrets E2E distants valides;
  elle ne justifie pas de modifier le code d’authentification sans accès au
  compte de déploiement.
- **Revalidation backend ciblée (27/09/2026)** : la suite `tests/Feature/Kiosk`
  passe **62/62 tests, 156 assertions** en 13,2 s; `tests/Feature/Loyalty`
  passe **93/93, 340 assertions** en 23,5 s; `tests/Feature/Pricing` passe
  **31/31, 79 assertions** en 7,6 s. Les invariants borne, fidélité et
  calcul/prix restent verts localement après le dernier contrôle distant.
- **Re-run distant après changement de provisioning** : le scénario borne est
  désormais **5/5 PASS** (le parcours catégories/produits n’est plus skipped),
  tandis que `tests/e2e/01-auth-refresh.spec.js` reste **0/2** avec le même
  HTTP 400 « Identifiants invalides ou compte bloqué ». Le provisioning borne
  est donc résolu; l’auth POS distante reste le blocage externe actif.
- **Recontrôle copie publique (27/09/2026)** : la page publique sert toujours
  deux occurrences de « livraison passe par Uber Eats » et aucune occurrence
  de « Livraison par nos livreurs bientôt ». Le code checkout FoodKing reste
  cohérent; seule la surface publique déployée demeure à corriger.
- **Vérification artefact Playwright** : `reports/antigravity/playwright-latest.json`
  contient les cinq scénarios du smoke borne avec `ok=true` et sans scénario
  skipped. La réussite distante n’est donc pas seulement issue du résumé
  console; elle est également présente dans le rapport JSON persisté.
- **Diagnostic compte E2E (local, sans exposer de secret)** : la base locale
  contient `pos@lecayenne.fr` avec statut canonique actif `5`, `branch_id=1` et
  le mot de passe fixture accepté par `Hash::check`. Le même compte est rejeté
  par le VPS en HTTP 400; l’écart est donc confirmé côté données/configuration
  de déploiement distante, pas dans le middleware local de login.

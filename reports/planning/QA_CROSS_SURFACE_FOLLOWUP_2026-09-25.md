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
- **Revalidation livraison/checkout (27/09/2026)** : les tests Vitest
  `checkoutTakeawayCopy`, `checkoutGeocodeError`, `posDeliveryFlag` et
  `deliveryCharge` passent **24/24 tests**; la suite backend
  `tests/Feature/Delivery` passe **50/50 tests, 135 assertions**. Les libellés
  à emporter, erreurs de géocodage, indicateurs livraison et calculs de frais
  restent cohérents côté frontend et serveur.
- **Smoke E2E local complet revalidé (27/09/2026, Node 20)** :
  `npm run test:e2e:smoke` passe **22/22 tests en 1 min 30 s**, sans retry,
  couvrant auth/F5 POS, caisse cash, borne, KDS et synchronisation des
  ruptures avec isolation inter-branche. Les seuls messages non bloquants sont
  les dépréciations PHP d’une dépendance tierce au démarrage du serveur local.
- **Garde-fous release revalidés (27/09/2026)** : `pos:lint:pricing` reste OK
  sur **86 fichiers** (warning sign-off prévu jusqu’au 27/10/2026),
  `pos:lint:status` reste OK sur **38 fichiers**, et `perf:bundle-check` reste
  OK sur les **15 bundles** du manifest, dont `kiosk-shell` 280 KB et
  `kiosk-wizard-step` 130 KB sous leurs budgets.
- **Re-run auth POS distant (27/09/2026)** : le premier essai retourne toujours
  HTTP 400 « Identifiants invalides ou compte bloqué »; les retries suivants
  sont maintenant correctement arrêtés par le rate-limit distant en HTTP 429
  avec une fenêtre de 600 s. Cela confirme qu’il ne faut pas boucler sur le
  compte fixture : attendre la fenêtre ou provisionner un compte POS E2E dédié,
  puis rejouer une seule fois.
- **Revalidation borne + santé distante (27/09/2026, 18:29 CEST)** :
  `tests/e2e/03-kiosk-wizard.spec.js` passe à nouveau **5/5 en 19,7 s**, avec
  navigation catégories/produits; `/api/healthz` répond **200** et confirme
  DB, Redis, WebSocket, chaîne fiscale OK et `queue_pending=0`. La borne reste
  stable malgré le rate-limit qui protège séparément l’auth POS.
- **Parité frontend cuisine/suppléments revalidée (27/09/2026, 18:30 CEST)** :
  `kdsSymbolic.spec.js`, `kioskFritesSauceBilling.spec.js` et
  `checkoutTakeawayCopy.spec.js` passent **39/39 tests**. Les symboles HH/X,
  l’affectation sauce-produit/sauce-frites, le calcul supplément frites et les
  libellés à emporter restent alignés côté miroir JavaScript.
- **Revalidation serveur KDS/pricing (27/09/2026)** : le lot combiné KDS,
  pricing, régression supplément borne et preuve de prix serveur passe
  **91/91 tests, 305 assertions**. Le scénario bump d’une commande non
  libérée reste bien bloqué en HTTP 422, sans changement d’état (contrôle
  d’autorisation attendu).
- **Fidélité borne revalidée (27/09/2026, Playwright Chromium)** : les trois
  specs inscription/check/register passent **5/5 en 10,4 s**. Le solde réel,
  l’erreur claire pour code inconnu, l’inscription téléphone sans ressaisie,
  l’auto-check au 10e chiffre et l’affichage post-register restent verts, sans
  page blanche.
- **Sortie cuisine et supplément libre revalidés (27/09/2026)** : le lot
  combiné formatter/Kitchen/liaison quote passe **126/126 tests, 228
  assertions**. Les libellés HH/X/tacos, le placement des sauces par produit
  ou frites et l’impression du supplément libre restent conformes.
- **Re-run navigateur POS des cas signalés (27/09/2026)** : les deux scénarios
  ciblés passent **2/2 en 11,3 s** : supplément libre nommé ajouté au total,
  puis réouverture d’une ligne Cayenne en conservant les sauces Andalouse et
  Algérienne après confirmation. Aucun écrasement de sauce ni divergence de
  montant n’est observé.
- **Contrats auth borne/fidélité revalidés (27/09/2026)** :
  `KioskLoginApiTest` passe **2/2 tests, 9 assertions**; `KioskAuthTest` passe
  **2/2, 5 assertions**; `LoyaltyRegisterNoLeakTest` passe **4/4, 13
  assertions**. Les réponses d’authentification et d’inscription restent
  explicites et aucune fuite inter-branche n’est détectée.
- **Contrats frontend serveur revalidés (27/09/2026)** : la suite
  `tests/Feature/Frontend` passe **58/58 tests, 122 assertions**, couvrant
  catalogue public, disponibilité, livraison, checkout, sécurité et surface
  OSS. Aucun écart backend n’est introduit par les libellés ou règles de
  livraison contrôlés précédemment.
- **Cycle commande revalidé (27/09/2026)** : le lot `tests/Feature/Order`
  passe **109/109 tests, 328 assertions**. Les transitions de statut, calculs
  de ticket, annulation/remboursement et contrôles d’intégrité de commande
  restent verts, sans régression détectée.
- **Hardware/KDS revalidé (27/09/2026)** : la suite `tests/Feature/Hardware`
  passe **164/164 tests, 483 assertions**. Les formats de ticket, symboles,
  imprimante et contrats d’affichage cuisine restent conformes aux attentes
  borne/caisse/KDS.
- **Sécurité et isolation revalidées (27/09/2026)** : `KioskMultiBranch` passe
  **8/8 tests, 24 assertions**, `KioskSecurity` **10/10, 36 assertions**, et
  `tests/Feature/Security` **221/221, 3 583 assertions**. L’isolation
  `branch_id`, les contrôles d’accès et les protections anti-fuite restent
  verts.
- **Dispatch/outbox revalidé (27/09/2026)** : le lot Outbox, sémantique de
  livraison et KDS passe **81/81 tests, 248 assertions**. Les événements et
  jobs restent émis après commit, avec idempotence et synchronisation attendues.
- **Paiement/fiscalité/caisses revalidés (27/09/2026)** : le lot combiné
  `tests/Feature/Fiscal`, `Payment` et `Pos` termine **315 tests, 1 006
  assertions**, sans échec (8 tests explicitement skipped). Le seeder de menu
  affiche seulement ses avertissements de fixtures/catégories inconnues,
  sans échec de test ni erreur de chaîne fiscale.
- **Contrat clé API revalidé (27/09/2026)** : `ApiKeyRotationTest` passe
  **7/7 tests, 11 assertions**, confirmant le rejet des clés absentes/invalides
  et l’acceptation contrôlée de la rotation. Le probe curl sans en-tête observé
  pendant l’audit distant est donc un rejet de sécurité attendu, pas un défaut
  d’authentification utilisateur.
- **Retest auth POS après expiration du rate-limit (27/09/2026, sans retry)** :
  le scénario unique `login POS → F5` reçoit toujours HTTP 400
  `Identifiants invalides ou compte bloqué`. Le compte distant n’a donc pas
  été réactivé/provisionné depuis le dernier audit; aucun nouveau rate-limit
  n’a été déclenché par ce contrôle isolé.
- **Audit i18n revalidé (27/09/2026)** : les 80 fichiers Laravel sont parsés
  sans erreur; la dette connue reste inchangée (Vue fr 11/en 112/ar 644/de
  922/bn 923; Laravel fr 5/en 21/ar 62/de 89/bn 86). Le code de l’audit reste
  non vert uniquement à cause des clés manquantes, pas d’une erreur de parsing.
- **Suite frontend complète revalidée (27/09/2026, Vitest)** : `npm test --
  --run` termine **556 fichiers, 4 511 tests passés, 3 skipped** en 192,0 s.
  Les avertissements observés (stubs Vue, clés i18n de fixtures et appels
  happy-dom vers `localhost:3000`) sont non bloquants et aucun test n’échoue.
- **Revalidation web distante borne (27/09/2026, 18:49 CEST)** :
  `tests/e2e/03-kiosk-wizard.spec.js` passe encore **5/5 en 19,7 s** sur
  `https://vps-418872ac.vps.ovh.net`, y compris le parcours catégories/produits.
  Le probe simultané `/api/healthz` répond **200** avec DB, Redis, WebSocket,
  chaîne fiscale OK et `queue_pending=0`. La copie publique reste séparément
  non conforme : le HTML de `lecayenne.fr` mentionne toujours Uber Eats.
- **Contrôle réel Chrome du panier public (27/09/2026)** : ajout d’un Tacos M
  affiché à **6,90 €** dans un panier contenant déjà un Tacos L à **17,30 €**;
  le panier affiche **sous-total/total 24,20 €**, soit la somme exacte des deux
  lignes. Le contrôle s’est arrêté avant « Passer commande »; aucune commande
  réelle ni paiement n’a été déclenché.
- **Variation de quantité vérifiée dans Chrome (27/09/2026)** : passage du
  Tacos M de quantité 1 à 2 recalculé à **13,80 €**, avec total **31,10 €**;
  retour à quantité 1 restauré à **24,20 €**. Aucun écart de prix ni perte de
  personnalisation n’a été observé.
- **Checkout public vérifié dans Chrome (27/09/2026)** : l’étape
  `#payment` affiche bien le mode **« À emporter »**, le créneau de retrait,
  le paiement au comptoir et le bouton « Confirmer ma commande 24,20 € ».
  Le mode livraison pointe encore explicitement vers **Uber Eats**; le texte
  cible « livraison par nos livreurs bientôt » n’est donc pas déployé. Le test
  s’est arrêté avant saisie d’email, confirmation ou paiement.
- **Smoke E2E complète revalidée localement (27/09/2026, 18:54 CEST)** :
  `npm run test:e2e:smoke` termine **22/22 tests passés en 1,5 min** couvrant
  auth/F5 POS, caisse, borne, KDS et synchronisation de rupture de stock
  multi-branche. Les avertissements PHP de dépréciation restent non bloquants.
- **Fidélité/inscription revalidée localement (27/09/2026, 18:54 CEST)** : les
  deux specs Playwright dédiées terminent **4/4 en 7,3 s**. Solde réel, code
  inconnu avec erreur claire, inscription téléphone sans ressaisie et
  vérification automatique au 10e chiffre passent; aucune page blanche.
- **Contrats backend sensibles revalidés (27/09/2026)** : les suites
  `Feature/Kiosk`, `Feature/Loyalty` et `Feature/Pricing` terminent
  respectivement **62/62**, **93/93** et **31/31 tests passés**. Les garanties
  de paiement au centime, suppléments/sauces, fidélité (inscription, solde,
  remboursement/idempotence) et isolation/auto-login borne restent vertes.
- **Inscription fidélité complète revalidée (27/09/2026, 18:56 CEST)** :
  `kiosk-loyalty-register-e2e.spec.js` passe **1/1 en 4,0 s**; la réponse
  `register` rend bien le solde et aucun écran blanc n’apparaît après création.
- **Sécurité/isolation backend revalidée (27/09/2026)** : `Feature/Security`
  passe **221/221**, `KioskSecurityTest` **6/6** et `Feature/KioskMultiBranch`
  **8/8**. Les autorisations, clés API, anti-fuite fidélité, tokens borne,
  `branch_id` et allowlist de locale restent conformes.
- **Commande et dispatch revalidés (27/09/2026)** : `Feature/Order` passe
  **109/109 tests** et `Feature/Outbox` **81/81 tests**. Les snapshots de prix,
  transitions `OrderStatus`, parité OrderService/FrontendOrderService,
  idempotence et dispatch strictement après commit restent verts.
- **Reprobe distant (27/09/2026, 18:59 CEST)** : `/api/healthz` répond encore
  **200** avec DB, Redis, WebSocket, fiscalité OK et `queue_pending=0`. Le HTML
  public n’a pas changé : le checkout et le footer mentionnent toujours Uber
  Eats; aucun déploiement de la copie « livreurs bientôt » n’est constaté.
- **Frontend et hardware revalidés (27/09/2026)** : `Feature/Frontend` passe
  **58/58 tests** et `Feature/Hardware` **164/164 tests**. La source serveur
  reste l’autorité du total attendu, tandis que tickets cuisine/client,
  sauces HH/X, viandes, frites et largeur ESC/POS restent conformes.
- **Fiscalité, paiement et caisse revalidés (27/09/2026)** : `Feature/Fiscal`
  passe **307 tests** avec **8 skips MySQL explicites**, `Feature/Payment`
  **86/86** et `Feature/Pos` **386/386**. Les contrôles NF525, paiements
  idempotents, split tender, tiroir, fidélité caisse et totaux serveur restent
  verts; les skips dépendent uniquement de MySQL/MariaDB non utilisé localement.
- **Garde-fous release revalidés (27/09/2026)** : pricing lint (**86 fichiers**)
  et status lint (**38 fichiers**) sont verts; le bundle check valide **15/15
  bundles**. L’audit i18n parse **80 fichiers Laravel sans erreur**; la dette de
  clés manquantes reste connue. Le pricing lint conserve l’avertissement de
  sign-off jusqu’au **27/10/2026**, sans échec actuel.
- **Suite backend globale revalidée (27/09/2026)** : `php artisan test` termine
  **6 080 tests passés, 36 skipped, 6 incomplets, 1 échec** en 1 301,71 s.
  L’unique échec est le sentinel frozen-zone sur
  `KioskWizardComponent.vue` (hash baseline inchangé, drift déjà documenté);
  les incomplets correspondent aux gates/owner-finalize explicites du plan.
  Aucun baseline frozen n’a été modifié sans sign-off humain.
- **Forensic frozen recheck (27/09/2026)** : le hash réel de
  `KioskWizardComponent.vue` reste `f8ecb111…06465`, contre la baseline
  `fcbe3755…256ac`. L’écart provient uniquement du commit documentaire
  `c21628767` (normalisation de trois marqueurs `@pricing-allowed-block`), sans
  changement de logique métier ni de fichier de baseline. Le lock
  `LOCK_KIOSK_FRITES_SAUCE_BILLING_2026-07-29.md` conserve son sign-off owner
  non coché; le sentinel reste donc **NEEDS_OWNER_ACTION** et ne doit pas être
  “réparé” par une mise à jour automatique de baseline.
- **Reprobe runtime VPS (27/09/2026, 19:28 CEST)** : `/api/healthz` répond
  toujours HTTP **200** avec DB, Redis, WebSocket et chaîne fiscale à `ok`.
  `queue_pending` est toutefois à **1** sur trois mesures espacées de 2 s
  (auparavant 0) : ce n’est pas une panne HTTP, mais cela doit être surveillé
  côté worker/outbox avant de conclure à un retour à zéro.
- **Reprobe copie publique (27/09/2026, 19:28 CEST)** : le HTML contient
  toujours « la livraison passe par Uber Eats » et « Pour être livré… Uber
  Eats ». Le texte validé « livraison par nos livreurs bientôt » n’est donc
  toujours pas déployé.
- **Contrat healthz local (27/09/2026)** : `HealthzEndpointTest` passe **7/7**;
  la forme JSON, l’énumération d’état, le compteur de queue et la commande
  CLI restent conformes.
- **Revalidation borne distante (27/09/2026, 19:29 CEST)** : après activation
  explicite de Node **20.20.2** (Node 18 est refusé par Playwright),
  `03-kiosk-wizard.spec.js` repasse **5/5 en 19,9 s** sur le VPS, sans crash
  JavaScript et avec navigation catégories/produits fonctionnelle.
- **Queue après revalidation borne (27/09/2026, 19:30 CEST)** : `/api/healthz`
  reste HTTP 200, mais `queue_pending=2` (stable sur quatre mesures puis après
  le test). La borne est fonctionnelle; le backlog outbox/worker devient une
  action de supervision distincte et ne doit pas être purgé manuellement.
- **Readiness/queue cross-check (27/09/2026, 19:31 CEST)** : `/api/health/live`
  répond 200, `/api/health/ready` répond 200 avec DB/Redis/worker/scheduler OK,
  mais signale `restore_drill=degraded` (aucune restauration de vérification
  mesurée). `/api/health` rapporte simultanément `total_size=0`, alors que
  `/api/healthz` rapporte 2 jobs : écart de sondes à faire corréler côté
  déploiement, sans conclure à une purge ou à une panne de file.
- **Fidélité borne locale (27/09/2026, 19:31 CEST)** : les specs solde réel,
  code inconnu, inscription sans ressaisie et auto-vérification au 10e chiffre
  passent **4/4 en 6,8 s**; l’inscription post-register reste **1/1**.
- **Surfaces frontend et tickets revalidées (27/09/2026)** :
  `tests/Feature/Frontend` passe **58/58 en 11,48 s** et
  `tests/Feature/Hardware` **164/164 en 12,24 s**. Les contrôles couvrent
  l’autorité du total serveur, l’emporter/livraison, les tickets client/cuisine,
  sauces multiples et frites, viandes supplémentaires, libellés tacos sans
  taille, largeur ESC/POS et tiroir caisse.
- **Restore-drill contract revalidé localement (27/09/2026)** :
  `SystemHealthRestoreDrillTest` **17/17**, `RestoreDrillAttesteFichierCourantTest`
  **5/5** et `CockpitEtReadinessMemeAgeSauvegardeTest` **5/5** passent.
  Le code distingue correctement drill absent, périmé, empreinte/fichier
  différent et preuve fraîche. En production, `/api/health/ready` reste
  `restore_drill=degraded` car aucune preuve fraîche n’est enregistrée; aucune
  restauration n’a été exécutée automatiquement.
- **Release guards revalidés (27/09/2026, 19:34 CEST)** : pricing lint
  (**86 fichiers**) et status lint (**38 fichiers**) sont verts; le pricing
  lint conserve seulement l’avertissement de sign-off jusqu’au **27/10/2026**.
  Le contrôle de budget valide **15/15 bundles**. L’audit i18n parse **80
  fichiers Laravel sans erreur**; les clés manquantes connues restent la dette
  déclarée (Vue fr 11/en 112/ar 644/de 922/bn 923; Laravel fr 5/en 21/ar
  62/de 89/bn 86).
- **Smoke E2E complet revalidé (27/09/2026, 19:34–19:36 CEST)** :
  `npm run test:e2e:smoke` passe **22/22 en 1,5 min** avec Node 20.20.2;
  auth/F5 POS, caisse, borne, KDS et synchronisation rupture multi-branche
  sont verts. Les avertissements PHP de dépréciation restent non bloquants.
- **Healthz après smoke (27/09/2026, 19:36 CEST)** : HTTP 200 avec DB, Redis,
  WebSocket, fiscalité et `queue_pending=0`.
- **Backend invariants revalidés séparément (27/09/2026)** : Kiosk **62/62**,
  Loyalty **93/93**, Pricing **31/31**, Order **109/109**, Outbox **81/81** et
  Security **221/221** passent. Les tests couvrent prix scellés au centime,
  fidélité/inscription et idempotence, statuts/OrderService parity,
  dispatch post-commit, isolation `branch_id` et protections d’accès.
- **Suite frontend complète revalidée (27/09/2026, 19:38–19:41 CEST)** :
  `npm test -- --run` termine **556 fichiers, 4 511 tests passés, 3 skipped
  (4 514 total)** en **193,29 s**. Les warnings Vue/i18n et tentatives
  happy-dom vers `localhost:3000` restent non bloquants; aucun test n’échoue.
- **Healthz après Vitest (27/09/2026, 19:41 CEST)** : HTTP 200, services
  critiques OK et `queue_pending=0`.
- **Écart copie public revalidé (27/09/2026, 19:42 CEST)** : le test local
  `checkoutTakeawayCopy.spec.js` passe **2/2**, mais le HTML réellement servi
  par `lecayenne.fr` contient encore plusieurs mentions « Uber Eats » et
  « la livraison passe par Uber Eats ». C’est une divergence de déploiement /
  surface externe, pas un échec du composant FoodKing versionné; elle reste
  ouverte jusqu’à identification et déploiement de la copie propriétaire.
- **Healthz public associé (27/09/2026, 19:42 CEST)** : VPS HTTP 200 et
  `queue_pending=0`.
- **Source du site public identifiée (27/09/2026, 19:43 CEST)** : la page
  provient du dépôt externe `~/Downloads/lecayenne-web-deploy/Site lecayenne`
  (remote `loeymot-sketch/Site-lecayenne`), pas de ce dépôt FoodKing. Les
  mentions Uber Eats sont présentes dans `index.html` et `commander.html`.
  L’arbre externe contient déjà des modifications non committées; aucune
  édition n’a été faite par cet audit.
- **Audit SEO local de la surface externe (27/09/2026, 19:43 CEST)** :
  `node tests-e2e/verif-seo.mjs` obtient **17 contrôles réussis, 1 échec**.
  L’échec est la similarité `bol-frites.html ↔ bol-riz.html` (81%); les pages,
  sitemap, 41 URLs, 39 prix et numéro public unique passent. Ce point et la
  copie Uber Eats restent à traiter dans le dépôt externe propriétaire.
- **Comportement site/app externe vérifié (27/09/2026, 19:44 CEST)** : en
  servant l’arbre externe localement, `tools/verify-app-behaviour.mjs` passe
  **13/13 contrôles** : pont natif iOS, paiement coupé dans l’app, paiement
  actif dans le navigateur, API HTTPS, absence d’erreurs JS et fournisseurs
  sociaux correctement séparés. Ce contrôle ne modifie aucun fichier.
- **Publication HTTP externe (27/09/2026)** : `/`, `/commander.html`,
  `api.js`, `compiled/racine.js` et `sw.js` répondent tous **200** avec les
  types MIME attendus; HTTP→HTTPS répond **308**. Les en-têtes CSP, HSTS,
  `nosniff`, `frame-ancestors` et permissions sont présents. Le CDN sert une
  copie Vercel en cache (`x-vercel-cache: HIT`, âge observé 6 330 s), ce qui
  renforce le constat de décalage de déploiement de la copie Uber Eats.
- **Diagnostic cache/déploiement final (27/09/2026)** : la réponse normale et
  la réponse cache-bustée de `index.html` ont le même SHA-256
  `91ea556e…332c48bf`, le même ETag et la même date `last-modified`; les deux
  restent `x-vercel-cache: HIT` et contiennent quatre mentions Uber Eats.
  Le dépôt externe local et `origin/main` pointent tous deux sur
  `b7bc1763181e…`, tandis que l’arbre local comporte des modifications non
  committées : la copie corrigée n’est donc pas poussée/déployée. Aucun push
  externe n’a été effectué par l’audit.
- **Inspection du diff externe (27/09/2026)** : les changements présents dans
  les trois pages ciblées sont des ajustements de navigation/accessibilité,
  `dateModified` et cache-bust des scripts; le texte de livraison reste
  explicitement Uber Eats. Le dépôt externe étant déjà dirty, l’audit s’arrête
  avant toute sélection, commit ou déploiement propriétaire.
- **Recontrôle live (27/09/2026, 19:48 CEST)** : la sonde VPS reste saine
  (HTTP 200, DB/Redis/WebSocket/fiscalité OK, queue à 0), mais le site public
  renvoie encore quatre occurrences « Uber Eats » et zéro occurrence des
  formulations de livraison/emporter demandées. Ce contrôle confirme que la
  correction n’est pas publiée, malgré le code FoodKing local validé.
- **Rejeu navigateur VPS (28/09/2026, 02:00 CEST)** : les quatre contrôles
  exécutables de la suite kiosk distante passent; le scénario de navigation
  reste ignoré car le provisioning borne manque. `/admin/dashboard` renvoie
  vers `/login` sans erreur JavaScript quand aucune session n’est présente.
  La borne live affiche encore son écran d’indisponibilité, ce qui reste un
  défaut de disponibilité à résoudre hors simple santé API.
- **Readiness sémantiquement trompeuse (28/09/2026)** : la sonde `/api/health/ready`
  conserve HTTP 200/status `ok` malgré `restore_drill=degraded`. Le rapport
  classe ce point comme risque opérationnel distinct : la supervision peut
  annoncer « prêt » alors que la restauration de secours n’a jamais été
  mesurée.
- **Contrôle anti-régression pricing/paiement (28/09/2026)** : les huit suites
  frontend critiques passent **51/51**; le sous-ensemble backend pricing passe
  **31/31**. Aucun écart de total ou de supplément n’est reproduit localement,
  mais ce résultat ne remplace pas une transaction réelle sur caisse avec un
  compte POS provisionné.
- **Vérification post-rapport (28/09/2026, 02:01 CEST)** : aucune publication
  externe n’est intervenue; `HEAD` local et `origin/main` restent identiques,
  l’arbre externe conserve 58 changements non committés, et le site live sert
  toujours quatre mentions Uber Eats. Les en-têtes de sécurité sont conformes,
  mais le cache Vercel stable sert encore l’ancienne copie.
- **Scan supply-chain (28/09/2026)** : l’audit npm révèle **21 vulnérabilités**
  (dont 3 critiques et 11 hautes) et l’audit Composer **8 advisories**. Ce
  nouveau risque n’est pas couvert par les tests fonctionnels verts; il faut
  une mission sécurité dédiée, avec matrice d’impact et mise à niveau testée,
  avant de lancer `npm audit fix --force` ou une mise à jour Laravel majeure.
- **Versions à prioriser (28/09/2026)** : le scan rattache le risque aux
  dépendances directes `firebase@9.23.0`, `swiper@11.2.10`, `vue3-quill@0.3.1`
  / `quill@1.3.7`, et aux paquets PHP `laravel/framework v9.52.21`,
  `maatwebsite/excel 3.1.67`, `spatie/laravel-medialibrary 10.15.0` et
  `firebase/php-jwt v6.11.1`. Cette cartographie fournit au développeur les
  points d’entrée sans modifier le lockfile pendant l’audit.
- **Sécurité/routage live (28/09/2026)** : la suite Security locale est verte
  à **221/221**. En revanche, des chemins API inexistants sondés sans session
  renvoient le shell HTML avec HTTP 200 (`/api/orders`, `/api/settings`,
  `/api/kiosk/config`) au lieu d’un statut API explicite; observation à
  traiter comme défaut de contrat/monitoring, sans preuve actuelle de fuite de
  données.
- **Fallback API confirmé (28/09/2026)** : les chemins non déclarés dans
  `route:list --path=api` retournent toujours le shell SPA en HTTP 200, même
  avec `Accept: application/json`. Ce n’est pas une fuite démontrée, mais une
  réponse non typée qui peut provoquer des erreurs silencieuses côté clients et
  monitoring.
- **Revalidation métier (28/09/2026)** : Order **109/109**, Loyalty **93/93**
  et Outbox **81/81** passent sur des exécutions séparées. Les invariants
  critiques restent verts en local, sans preuve équivalente d’un encaissement
  réel sur le compte POS de production encore manquant.
- **E2E critique frais (28/09/2026, 02:51–02:53 CEST)** : **22/22** scénarios
  passent en 1,5 min sur POS/F5, caisse, borne, KDS et rupture multi-branche.
  Aucune régression fonctionnelle détectée; les avertissements observés sont
  des dépréciations PHP non bloquantes.
- **A11y/guards/i18n (28/09/2026)** : A11y ciblé **27/27**, guards pricing/status
  et bundles **15/15** passent. En revanche, l’audit i18n échoue sur une dette
  connue (Vue jusqu’à 923 clés manquantes selon langue, Laravel jusqu’à 89);
  les tests A11y montrent aussi des appels vers `localhost:3000` refusés,
  masqués par les mocks. À traiter comme dette de qualité d’environnement et
  de traduction, pas comme un faux PASS complet.
- **Kiosk/restore (28/09/2026)** : Kiosk backend **62/62** et contrats santé/
  restauration frontend **18/18** passent. Les scénarios couvrent paiement,
  réconciliation, revocation de jeton, auto-login et isolation; la disponibilité
  réelle de la borne reste cependant non prouvée tant que le provisioning VPS
  n’est pas activé.
- **Fiscalité/branches/deploy (28/09/2026)** : Fiscal **307 passés / 8 skipped**,
  Branch **20/20**, Deploy **5/5**. Les skips sont explicitement MySQL-only et
  un scénario de split tender reste verrouillé par M6-002. La seed menu signale
  en outre des catégories inconnues et des libellés anglais : dette catalogue
  à traiter séparément malgré les tests verts.
- **Sonde post-rapport (28/09/2026, 02:57 CEST)** : aucune publication externe
  depuis le dernier audit; SHA/ETag du HTML restent inchangés, Uber Eats est
  toujours présent quatre fois, et la readiness VPS reste dégradée sur le
  restore drill malgré HTTP 200.
- **Régression Vitest complète (28/09/2026, 02:58–03:01 CEST)** : la suite
  globale termine **NEEDS_FIX** avec **554 fichiers passés, 1 fichier en échec**;
  **4 497 tests passent, 5 échouent, 3 sont ignorés**, et Vitest signale **1
  erreur non gérée**. Les cinq échecs sont tous dans
  `tests/js/playwrightConfig.spec.js` : l’exécution globale utilise Node
  **18.20.7**, alors que Playwright exige Node **20+**. L’erreur non gérée est
  un conflit CommonJS/ESM de `html-encoding-sniffer` → `@exodus/bytes` via
  jsdom. Les autres tests fonctionnels restent verts, mais les warnings
  répétés (router-link non résolu, `ECONNREFUSED 127.0.0.1:3000`, props KDS
  invalides et clés i18n absentes) confirment des risques de harness et
  d’environnement à traiter séparément.
- **Rejeu global sous Node 20 (28/09/2026, 03:02–03:06 CEST)** : après
  alignement explicite sur Node **20.20.2**, la suite complète passe
  **556/556 fichiers, 4 511/4 514 tests**, avec **3 skips et zéro erreur non
  gérée**. Les cinq échecs précédents et le conflit jsdom ESM/CJS ne se
  reproduisent donc pas sous le runtime supporté. Des warnings demeurent
  (router-link/vue-select non résolus, appels `localhost:3000` refusés,
  actions Vuex inconnues et clés i18n absentes) : ils ne cassent pas les tests,
  mais justifient une passe de nettoyage du harness et une vérification avec
  backend réellement démarré.
- **Sonde live API (28/09/2026, 03:06 CEST)** : `/api/healthz` reste HTTP
  200 avec DB/Redis/WebSocket/fiscal chain OK et queue à 0. `/api/health/ready`
  reste HTTP 200 mais expose explicitement `restore_drill=degraded` (« jamais
  mesurée »). Les chemins `/api/orders`, `/api/settings` et
  `/api/kiosk/config`, absents du routage Laravel, répondent encore HTTP 200
  `text/html` (shell SPA) même avec `Accept: application/json`; le contrat API
  live reste donc ambigu et doit être corrigé ou surveillé explicitement.
- **Rejeu surface publique (28/09/2026, 03:07 CEST)** : `lecayenne.fr` répond
  HTTP 200 avec cache Vercel HIT et les headers CSP/HSTS/nosniff/
  frame-ancestors/Permissions-Policy. Le HTML contient encore **4** mentions
  « Uber Eats » et **0** occurrence des formulations emporter/livraison
  demandées : le défaut de contenu publié est directement observable malgré
  la santé technique du domaine.
- **Garde-fou invariants (28/09/2026, 03:08 CEST)** : `bash
  scripts/check-invariants.sh -v` échoue sur **3/6 invariants, 19 occurrences
  brutes**. Le signal le plus concret est `FrontendOrderService.php:175`, qui
  lit `branch_id` depuis la requête pour le namespace d’idempotence : c’est une
  dérogation documentée pour les guests mais un risque réel d’isolation à
  revalider côté métier. Les 3 hits de dispatch catalogue dans `ItemService`
  sont entourés par `DispatchableAfterCommit` (risque principalement de faux
  positif du guard); les 15 hits audit incluent imports/commentaires et des
  chemins qui écrivent déjà `AuditLogService`/`ActionLog`, mais le guard ne
  distingue pas ces cas. La CI ne peut donc pas être considérée propre sans
  soit corriger le code branch-id, soit formaliser les exceptions et améliorer
  le guard.
- **Suite PHPUnit Feature complète (28/09/2026, après 03:08 CEST)** : **5 709
  tests passent**, **1 échoue**, **4 sont incomplets** et **36 sont ignorés**.
  L’échec est le sentinel de baseline frozen :
  `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` diverge du
  SHA autorisé (`fcbe3755…` attendu, `f8ecb111…` observé). Aucun revert ni
  mise à jour de baseline n’a été effectué : le fichier est hors périmètre
  sans lock/sign-off propriétaire. Les incomplets/skips confirment les limites
  déjà vues (couverture MySQL/MariaDB absente sous SQLite, harness websockets,
  gates frozen coupon/composer/category et onboarding réel).
- **Contrôle métier ciblé dans la même suite** : les preuves de prix backend
  restent vertes (`PosKioskPricingParity`, `KioskFritesSauceBilling`,
  `PricingService`, supplément manuel fiscalisé), ainsi que les parcours de
  fidélité/signup et les protections branch/auth. Cela ne remplace pas un
  encaissement réel sur le POS distant, toujours non provisionné.
- **Suite PHPUnit Unit (28/09/2026, 03:32 CEST)** : **367/367 tests passent**
  en 8,61 s. Le contrôle bundle passe aussi : 15 bundles dans les budgets,
  dont `kiosk-shell` 280 KB/350 KB et `kiosk-wizard-step` 130 KB/150 KB.
- **Requête live avec la clé publique exposée par `/login` (28/09/2026,
  03:31 CEST)** : les routes déclarées API (`guest-signup`, quote POS,
  loyalty config) répondent **302 `/login` en HTML**, même avec
  `Accept: application/json` et `X-API-Key` extrait de la page. Le code local
  prévoit au contraire un `400` JSON pour clé absente/invalide et un `503` JSON
  pour installation incomplète. Cela révèle un écart de déploiement/routage
  (route cache ou middleware réellement servi) à diagnostiquer sur le VPS;
  un client SPA peut donc recevoir une page de login au lieu d’une erreur API.
- **Suite Load/Rush midi (28/09/2026, 03:34 CEST)** : **4/6 scénarios
  passent**, **2 restent incomplets**. Les trous ne sont pas des faux tests :
  `s72` ne couvre pas encore le parcours HTTP réel kiosk `/payment-confirm`
  avec `source_surface`/`transaction_id`, et `s73` ne prouve pas encore la
  monotonie mélangée POS+kiosk via HTTP. Les invariants POS, multi-branches,
  outbox et clôture Z restent verts, mais la couverture de concurrence réelle
  borne→paiement reste incomplète.
- **Dépendances (28/09/2026, 03:33 CEST)** : `composer audit` signale **8
  advisories** (dont Laravel, Laravel Excel et Media Library) et `npm audit
  --omit=dev --audit-level=high` **21 vulnérabilités** (**3 critiques, 11
  hautes, 5 modérées, 2 basses**). Plusieurs correctifs npm nécessitent des
  upgrades majeurs et un paquet Quill reste sans correctif : pas de mise à
  niveau automatique appliquée.
- **Dérive d’artefacts live (28/09/2026, 03:36 CEST)** : le HTML VPS sert
  `app.js?id=274114…`, `vendor.js?id=f9fe8…`, `manifest.js?id=e6b5…` et
  `app.css?id=b919…`, alors que le `public/mix-manifest.json` local pointe
  vers `ae40…`, `293c…`, `a68c…` et `561b…`. Les dates live sont également
  hétérogènes (vendor du 02/09, CSS du 17/09, app du 27/09). Les tests locaux
  ne valident donc pas exactement le bundle servi par le VPS; une incohérence
  de déploiement/cache peut expliquer les pages blanches et contrats API
  divergents.
- **Correction du contrat API live (28/09/2026, 03:40 CEST)** : re-test avec
  la clé réellement extraite de `/login` et la méthode correcte : guest signup
  sans email renvoie **422 JSON**, loyalty config **200 JSON**, et quote POST
  sans session renvoie **401 JSON**. La conclusion précédente « ces routes
  renvoient toutes 302 » est donc supersédée. Le défaut reproductible restant
  est plus ciblé : un GET sur une route POST API renvoie **200 HTML SPA** au
  lieu d’un 405/JSON explicite, ce qui peut masquer une erreur de méthode au
  monitoring ou à un client mal configuré.
- **E2E Chromium réel VPS — borne (28/09/2026, 03:45 CEST)** : sous Node
  20.20.2, `tests/e2e/03-kiosk-wizard.spec.js` donne **4 passés / 1 skipped**.
  Login borne, rendu visible, absence d’erreur JS fatale et configuration
  `kioskMenuPricing` passent. Le parcours tactile catégories→produit reste
  skip car aucune `KioskMachine` provisionnée n’active l’écran idle; ce n’est
  pas une preuve de fonctionnement de la borne en production.
- **E2E Chromium surface publique (28/09/2026, 03:50 CEST)** : homepage HTTP
  200, menu hash `/#menu` fonctionnel, zéro erreur console/page et zéro
  réponse réseau ≥400 sur le parcours observé. Les pages statiques
  `/carte.html`, `/horaires.html`, CGV et confidentialité répondent 200.
  En revanche, `/menu` en URL directe répond **404** : ce n’est pas le chemin
  utilisé par la navigation actuelle (hash + `carte.html`), mais c’est une
  faiblesse de lien profond/SEO à corriger ou documenter.
- **CTA commande public (28/09/2026, 03:55 CEST)** : Chromium a cliqué les
  cinq boutons visibles libellés « Commander »/« Voir le menu ». **Tous**
  aboutissent à `https://www.lecayenne.fr/#menu`; aucun n’ouvre un checkout,
  un choix emporter ou une étape de paiement. Le panier s’ouvre mais reste
  vide. C’est un défaut fonctionnel direct : le CTA promet de commander mais
  ne lance qu’une navigation catalogue.
- **Page dédiée commande/livraison (28/09/2026, 04:00 CEST)** :
  `/commander.html` et `/livraison-henin-beaumont.html` répondent 200, mais
  leurs liens « Commander en ligne » ont tous `href="/"`; ils renvoient à la
  vitrine au lieu d’un formulaire/checkout. Le texte promet pourtant une
  composition en ligne et un paiement carte. La livraison est correctement
  expliquée comme Uber Eats, mais aucun parcours de commande à emporter n’est
  réellement raccordé.
- **Correction après parcours complet (28/09/2026, 04:08 CEST)** : le CTA
  homepage qui scrolle vers `/#menu` n’est pas un checkout mort : Chromium a
  réellement ajouté un Tacos M (Mexicanos + Harissa), vérifié le total **6,90
  €**, ouvert le panier, traversé upsell boisson/dessert et atteint l’écran
  retrait/paiement sans erreur JS ni réponse réseau ≥400. Aucun email/code ni
  commande n’a été soumis. Le défaut reste limité aux pages dédiées dont le
  CTA `href="/"` boucle vers l’accueil au lieu de conserver le contexte
  `/commander.html`; l’alerte « parcours public totalement absent » est donc
  supersédée.
- **Fidélité/inscription publique (28/09/2026, 04:15 CEST)** : depuis le
  bouton Fidélité → « Créer mon compte », Chromium affiche bien l’étape 1/2,
  le champ email et la promesse de code. Une adresse invalide affiche une
  erreur française explicite (« il faut un @ et un point »), sans page blanche,
  exception JS ni appel réseau ≥400. Le code réel/email n’a volontairement pas
  été demandé pour éviter un envoi ou une création de compte externe.
- **Scénario prix adversarial public (28/09/2026, 04:22 CEST)** : Tacos M +
  Mexicanos + Harissa + Andalouse supplémentaire + Cheddar. Le total passe
  de **6,90 € → 7,40 € → 8,30 €** aux étapes attendues et reste **8,30 €**
  dans le panier; la ligne conserve les quatre choix et affiche **+83 pts**.
  Aucun log JS ni réseau ≥400. Le défaut de variation de prix signalé par le
  propriétaire n’est pas reproduit dans ce cas réel, mais le paiement effectif
  n’a pas été envoyé.
- **Revalidation KDS/cuisine ciblée (28/09/2026, 03:42 CEST)** : les règles
  demandées sont couvertes et vertes : JS symbolique **48/48** (Harissa→HH,
  sans sauce→X, sauces frites multi-choix, quantités de suppléments), PHP
  cuisine **26/26** (double sauce, placement produit/frites, tacos sans taille,
  absence de ligne « supplément sauce » fantôme). Cela confirme la logique
  locale; la sortie imprimée sur matériel réel reste à vérifier après
  provisioning de la borne/imprimante.
- **Paiement public — modes visibles (28/09/2026, 03:52 CEST)** : l'écran live
  `/#payment` montre `Payer sur place` et `Carte bancaire (en ligne)` comme
  radios distinctes; le total reste **24,20 €** et le bouton de confirmation
  est visible. Le clic automatisé carte n'a pas été validé car Chrome a
  signalé l'élément hors viewport; aucune commande ni paiement n'a été soumis.
  Refaire ce cas sur une session fraîche avec viewport maîtrisé avant de
  déclarer la branche carte E2E verte.
- **Wave B public historique (28/09/2026, 03:57 CEST)** : le test Chromium
  legacy échoue car les sélecteurs hero/Facebook/galerie ne trouvent aucun
  élément sur le site actuel; aucune erreur console ou HTTP 4xx/5xx n'est
  observée. À classer comme test obsolète ou contenu public retiré après
  validation produit, pas comme preuve de panne du checkout.
- **Dashboard admin VPS (28/09/2026, 03:59 CEST)** : le smoke login/dashboard
  n'a pas terminé après plus de quatre minutes et a été interrompu; aucune
  assertion de rendu ou de non-doublage `/api/api/` ne peut être déclarée
  verte. Le VPS `/api/health` répond toutefois 200 JSON avec DB/Redis/queue OK
  et `version: dev`, ce qui laisse un risque de configuration de déploiement.
- **Matrice HTTP publique (28/09/2026, 04:00 CEST)** : les pages vitrines,
  commande, livraison, carte, horaires et légales sont HTTP 200; le deep-link
  `/menu` reste HTTP 404. Les CTA `Commander en ligne` des pages dédiées
  ciblent `/`, ce qui confirme la dette de routage/contexte déjà observée.
- **Assets et sécurité HTTP (28/09/2026, 04:03 CEST)** : scan direct des 35
  assets de la vitrine : **35/35 HTTP 200**, aucun asset transformé en HTML.
  La vitrine envoie HSTS/CSP/nosniff; le VPS `/login` n'envoie pas HSTS, CSP
  ou nosniff et son `/api/health` n'envoie pas HSTS/CSP/Permissions-Policy.
  C'est un écart de hardening à corriger côté reverse-proxy/déploiement.
- **Cookies VPS (28/09/2026, 04:04 CEST)** : sous HTTPS, les cookies de session
  et XSRF ont `SameSite=Lax` mais pas `Secure`; seul le cookie de session est
  `HttpOnly`. À corriger côté configuration Laravel/proxy, sans reproduire ni
  stocker les valeurs sensibles dans le rapport.
- **Bundles/CORS live (28/09/2026, 04:07 CEST)** : les replis localhost restent
  présents dans `api.js`/`menu.js`, mais les metas live pointent bien vers le
  backend HTTPS et les assets relatifs, donc aucun mixed-content runtime n'a
  été observé. Le CORS accepte l'origine publique attendue; son préflight
  répond toutefois `Allow-Methods: POST` pour tous les endpoints, y compris
  santé GET, avec `text/html` sur 204 : dette de contrat à corriger/monitorer.
- **Garde pricing/status/i18n (28/09/2026, 04:12 CEST)** : pricing et
  OrderStatus sont verts, avec un warning de sign-off pricing daté du
  27/10/2026; budgets bundles **15/15**. L'audit i18n est rouge : nombreuses
  clés manquantes (Vue FR 11, EN 112, AR 644, DE 922, BN 923; Laravel FR 5,
  EN 21, AR 62, DE 89, BN 86). À traiter avant d'affirmer la couverture
  multilingue complète.
- **Routes auth live (28/09/2026, 04:16 CEST)** : les endpoints réellement
  utilisés par le frontend (`/api/auth/guest-signup/email-otp|email-login|verify`)
  renvoient bien 422 JSON sur payload vide; loyalty config est 200 JSON et
  quote POST sans session est 401 JSON. L'ancien test d'un chemin
  `/api/frontend/loyalty/guest-signup` a été reclassé comme route erronée,
  pas comme défaut du signup.
- **Readiness et realtime live (28/09/2026, 04:20 CEST)** : le VPS renvoie
  `/api/health/ready` HTTP 200/`ok` malgré `broadcast=log` et un
  `restore_drill=degraded`; `/api/healthz` renvoie websocket `ok` avec le même
  driver. Cela masque un risque réel de synchronisation KDS/POS et de reprise
  après sinistre. `/api/health/live` répond seulement `OK` en HTML, contrat à
  uniformiser.
- **Rate-limit/CORS live (28/09/2026, 04:24 CEST)** : le signup email invalide
  est limité après cinq essais (429 + `Retry-After: 59`) sans envoi réel;
  les origines étrangères n'obtiennent pas de header CORS lisible. Les
  réponses health sont privées et non mises en cache. Ces contrôles de défense
  passent.
- **Mobile public (28/09/2026, 04:30 CEST)** : viewport Chromium 390×844 :
  homepage HTTP 200, aucun console/page error ni HTTP >=400, aucun débordement
  horizontal; Menu ouvre bien `/#menu` et rend 9 catégories/39 produits.
- **Personnalisation mobile (28/09/2026, 04:34 CEST)** : Tacos M → viande
  Mexicanos → étape sauce fonctionne sur 390×844; choix et prix 6,90 € visibles,
  zéro erreur JS/HTTP >=400 et aucun overflow. La suite panier multi-sauce reste
  à exécuter séparément.
- **Deux sauces mobile (28/09/2026, 04:39 CEST)** : Mexicanos + Harissa +
  Andalouse conserve `2 sélectionnés`, facture une sauce supplémentaire à
  **+0,50 €** (total **7,40 €**) et ouvre l'étape suppléments sans overflow ni
  erreur. La suite a été arrêtée avant soumission après un sélecteur emoji trop
  strict; aucun effet externe.
- **Panier mobile multi-sauce complet (28/09/2026, 04:45 CEST)** : après la
  vérification serveur, le panier conserve 1 Tacos M avec `Mexicanos, Harissa,
  Andalouse`; total **7,40 €**, **+74 pts**, bouton `Passer commande` présent.
  Aucun crash/réseau >=400 et aucune commande envoyée.
- **Modification article (28/09/2026, 04:52 CEST)** : la conservation des
  deux sauces est prouvée avant édition dans le panier. Le contrôle `MODIFIER`
  visible n'est pas exposé par un rôle/bouton stable au harnais Playwright;
  l'édition réelle et le risque de suppression de sauce restent non vérifiés,
  donc non classés comme corrigés.

# Audit nettoyage legacy Bangladesh / demo / fausses donnees - 2026-04-27

## Verdict court

Verdict: NEEDS_SCOPED_CLEANUP_MISSION.

Je n'ai supprime aucun fichier et je n'ai purge aucune ligne de base de donnees pendant cette passe. Le risque de casser la caisse, la borne, les tests sentinels ou les artefacts d'audit est trop eleve tant que le cycle Caisse V1 / Train A est actif et que le safety hook bloque encore sur une zone frozen staged.

Ce qui est confirme:

- La capture admin montrant `Turcotte, Pagac and Sauer Branch` vient de la base locale, table `branches`, ligne `id=2`.
- Ce n'est pas une traduction Bangladesh: c'est une donnee Faker/demo encore visible dans le selector de filiale admin.
- Les donnees principales `Le Cayenne`, `Paris`, `+33`, `Francais` sont deja en place pour la branche principale.
- Il reste des residus Bangladesh/demo dans le code et les seeders: `lang/bn`, `resources/js/languages/bn.json`, `BDT`, `Dhaka Bangladesh`, `+880`, `Bulksmsbd`, `bkash`, `sslcommerz`, `paytm`, etc.
- Le runtime charge encore trop large cote i18n: `resources/js/i18n.js` importe `ar`, `bn`, `de`, `en`, `fr`, alors que la V1 client doit etre francaise.
- Le routeur charge automatiquement `app/Http/PaymentGateways/Routes/senangpay.php`, mais la classe `App\Http\PaymentGateways\Gateways\Senangpay` n'existe pas. Cela casse `php artisan route:list --path=payment/senangpay-webhook` avec `ReflectionException`.

## Contraintes appliquees

- Pas de suppression large: demande utilisateur = nettoyer sans affecter 1% du projet.
- Pas de purge DB sans confirmation explicite: les branches, users, items, categories peuvent etre references par tests, tickets, KDS, audit fiscal ou sentinels.
- Pas d'edition produit hors mission: `ACTIVE_CYCLE` indique Caisse V1 / Train A actif.
- Pas de zones frozen: `bash .cursor/hooks/safety-check.sh` bloque encore:
  `HALT Frozen zone staged: app/Services/OrderService.php`.

## Etat base locale observe

### Branches

| id | name | statut | conclusion |
| --- | --- | --- | --- |
| 1 | Le Cayenne (principal) | 5 | OK, branche principale francaise |
| 2 | Turcotte, Pagac and Sauer Branch | 1 | Faux seed Faker visible dans l'admin |

Probleme technique: `BranchService::list()` ne filtre pas les branches actives par defaut, et `BackendNavbarComponent.vue` charge les branches avec seulement `paginate=0`, `order_column=id`, `order_type=asc`. Donc une branche inactive/invalide/factice peut apparaitre dans le header admin.

### Users

| id | name | email | branch_id | country_code |
| --- | --- | --- | --- | --- |
| 1 | Admin Le Cayenne | admin@lecayenne.fr | 0 | +33 |
| 2 | Client passage | walkingcustomer@example.com | 0 | +33 |
| 3 | Caissier Le Cayenne | pos@lecayenne.fr | 1 | +33 |
| 4 | Chef Le Cayenne | chef@lecayenne.fr | 1 | +33 |
| 5 | Clark Blanda | joelle.heathcote@example.org | 2 | null |

Conclusion: il reste un faux utilisateur rattache a la fausse branche `id=2`. Aucune commande ne doit supprimer ce user sans verifier les FK, roles, orders et tests.

### Langues

DB `languages`:

| id | name | code | status |
| --- | --- | --- | --- |
| 1 | Anglais | en | 5 |
| 2 | Francais | fr | 5 |

Fichiers presents:

- Backend Laravel: `lang/ar`, `lang/bn`, `lang/de`, `lang/en`, `lang/fr`.
- Frontend Vue: `resources/js/languages/ar.json`, `bn.json`, `de.json`, `en.json`, `fr.json`.

Conclusion: la DB n'expose pas `bn`, mais les fichiers Bangladesh existent encore et le bundle Vue les importe.

### Devise

DB `currencies`:

| id | name | symbol | code |
| --- | --- | --- | --- |
| 1 | Dollars | $ | USD |

Settings site:

- `site_default_currency = 1`
- `site_default_currency_symbol = €`
- `config/menu.php` force `currency = EUR`
- `OrderQuoteService::currencyCode()` retombe sur `config('menu.currency', 'EUR')` si aucun setting `site_default_currency_code` n'existe.

Conclusion: affichage et quote tendent vers EUR, mais la table `currencies` reste incoherente (`id=1 = USD`). C'est une dette de nettoyage importante parce que le Dashboard "devise par defaut" peut afficher USD alors que l'interface montre euro.

### Catalogue

Categories:

- 13 categories actives FoodKing/Le Cayenne.
- 1 categorie factice inactive/invalide: `id=14`, `Voluptate`, `status=1`.

Items:

- 63 items actifs globalement coherents avec le menu francais.
- 1 item factice inactive/invalide: `id=64`, `ipsum animi`, rattache a `Voluptate`, `status=1`.

Conclusion: le menu principal est majoritairement francais et correct. Les traces Faker restantes doivent etre masquees/filtrees puis purgees sous migration/data-repair controlee.

### Paiements / SMS legacy

Payment gateways DB actifs:

- `cash-on-delivery`
- `credit`
- `paypal` et `stripe` desactives (`status=10`)

Fichiers legacy presents:

- Payment requests: `Bkash`, `Sslcommerz`, `Paytm`, `Razorpay`, `Phonepe`, `Iyzico`, `Senangpay`, `Flutterwave`, `Mercadopago`, etc.
- Route dynamique legacy: `app/Http/PaymentGateways/Routes/senangpay.php`.
- SMS legacy Bangladesh: `app/Http/SmsGateways/Gateways/Bulksmsbd.php`.

Finding bloquant:

```bash
php artisan route:list --path=payment/senangpay-webhook
```

echoue avec:

```text
ReflectionException: Class "App\Http\PaymentGateways\Gateways\Senangpay" does not exist
```

Conclusion: le nettoyage paiement ne doit pas etre "supprimer tout" mais il faut desactiver le chargement automatique des routes de gateways absentes/non-France. C'est un vrai bug technique, pas seulement du rangement.

## Fichiers / dossiers identifies

### A garder pour l'instant

- `reports/`, `missions/`, `plans/`, `docs/gates/`, `memory/episodes/`: historique, gouvernance, audits et preuves. A ne pas supprimer maintenant.
- `kiosk_implementation/` et `borne (Remix)/`: archives marquees par `ARCHIVE_BANNER.md`, non runtime, protegees par lint. Elles prennent de l'espace mais elles sont deja quarantainees.
- `public/js/*`: bundles construits actuellement utilises. Ne pas supprimer sans refaire `npm run production` et verifier le manifest.
- `lang/en`: fallback Laravel encore configure (`fallback_locale = en`).

### Candidats nettoyage sous mission dediee

- `lang/bn/`
- `resources/js/languages/bn.json`
- imports `bn` et `de` dans `resources/js/i18n.js`
- `database/seeders/UserTableSeeder.php` bloc `DEMO` avec `+880` / `Dhaka Bangladesh`
- `database/seeders/OrderAddressTableSeeder.php`, `OrderTableSeederVersionTwo.php`, `KdsOrderTableSeeder.php` contenant `Dhaka Bangladesh`
- `database/seeders/CurrencyTableSeeder.php` qui seed `Dollars` par defaut et `BDT/INR/NGN/ARS` en demo
- `app/Http/PaymentGateways/Routes/senangpay.php`
- `app/Http/PaymentGateways/PaymentRequests/*` et `Requests/*` pour gateways non retenues France
- `app/Http/SmsGateways/Gateways/Bulksmsbd.php` et request associee
- `resources/views/paymentGateways/razorpay/`
- `.DS_Store` ignore files presents localement, mais ignores par `.gitignore`

### Dettes deja quarantainees

Commandes lancees:

```bash
bash scripts/lint-fk-archive-banner.sh
bash scripts/lint-fk-legacy-imports.sh
bash scripts/scan-bundle-legacy.sh
```

Resultats:

- Archive banners OK pour `kiosk_implementation/` et `borne (Remix)/`.
- Aucun import legacy depuis `resources/js`.
- Warning restant: references `pos-wizard` dans `public/js/kiosk.js`. En release strict, `FK_LEGACY_STRICT_POS_WIZARD=1` doit bloquer tant que le cutover n'est pas tranche.

## Plan de nettoyage recommande

### Phase 1 - Correction runtime sans suppression

Objectif: retirer ce que l'utilisateur voit sans detruire d'historique.

Actions:

1. Filtrer le select de branche admin sur `Status::ACTIVE` ou ajouter `status=5` au chargement du header.
2. Creer une commande data-repair dry-run `foodking:cleanup-demo-data --dry-run` qui liste:
   - branches avec statut hors enum ou nom Faker,
   - users sur branches demo,
   - categories/items status hors enum,
   - devises non EUR,
   - langues hors V1.
3. Ne pas supprimer: mettre en `Status::INACTIVE` ou renommer proprement uniquement apres preview.
4. Corriger la table `currencies`: `id=1` doit etre `Euro`, `€`, `EUR`.
5. Verrouiller le runtime V1 sur FR:
   - `site_language_switch = DISABLE` pour V1, ou
   - liste de langues admin/client reduite a `fr`, avec fallback technique `en` conserve.

### Phase 2 - Nettoyage seeders

Objectif: empecher le retour des donnees Bangladesh apres `migrate:fresh --seed`.

Actions:

1. Supprimer ou convertir les blocs `DEMO` qui creent `+880` / `Dhaka Bangladesh`.
2. Remplacer les noms demo par jeux de donnees francais (`Client test`, `Adresse Paris`, `Le Cayenne demo`) si DEMO reste utile.
3. Modifier `CurrencyTableSeeder` pour seed `EUR` en premier.
4. Ajouter un test sentinel:
   - aucun `Dhaka Bangladesh`,
   - aucun `+880`,
   - aucune devise `BDT/INR/NGN/ARS` en seed V1,
   - aucune branche Faker visible dans l'admin.

### Phase 3 - Quarantaine gateways non-France

Objectif: ne garder runtime que ce qui est utilise.

Actions:

1. Arreter le chargement automatique de toutes les routes `app/Http/PaymentGateways/Routes/*.php`.
2. Autoriser uniquement les gateways existantes et supportees par config explicite.
3. Supprimer ou archiver `senangpay.php` apres test route-list.
4. Garder `Stripe` uniquement si le gate web/CB le demande; sinon desactive mais pas casse.
5. Ajouter sentinel `PaymentGatewayRoutesDoNotReferenceMissingClassesTest`.

### Phase 4 - Suppression physique optionnelle

Objectif: recuperer de l'espace sans casser l'historique.

Ne faire qu'apres GO humain sur une allowlist exacte.

Candidats taille:

- `litellm-bedrock-cursor/` environ 432M: outil externe, pas runtime Laravel.
- `borne (Remix)/` environ 53M: archive visuelle.
- `reports/` environ 77M: historique; ne pas supprimer, eventuellement archiver hors repo apres release.
- `missions/` environ 24M: historique de cycle; ne pas supprimer pendant Caisse V1.

## Mission Codex proposee

TASK_ID: `CLEANUP-FR-RUNTIME-DEMO-DATA-2026-04-27`

Allowlist conseillee:

- `app/Services/BranchService.php`
- `resources/js/components/layouts/backend/BackendNavbarComponent.vue`
- `database/seeders/CurrencyTableSeeder.php`
- `database/seeders/UserTableSeeder.php`
- `database/seeders/OrderAddressTableSeeder.php`
- `database/seeders/OrderTableSeederVersionTwo.php`
- `database/seeders/KdsOrderTableSeeder.php`
- `app/Console/Commands/CleanupDemoDataCommand.php`
- `routes/console.php` ou `app/Console/Kernel.php` selon pattern repo
- `tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php`
- `tests/Feature/Sentinels/PaymentGatewayRoutesNoMissingClassesSentinelTest.php`
- `reports/audit/CLEANUP_FR_RUNTIME_DEMO_DATA_2026-04-27.md`

Interdictions:

- Pas de `rm -rf`.
- Pas de purge directe de `branches`, `users`, `items`, `item_categories`, `orders`.
- Pas de suppression de `reports/`, `missions/`, `docs/gates/`, `memory/`.
- Pas de changement pricing frontend.
- Pas de changement `OrderService` / `FrontendOrderService` dans cette mission.

Validation:

```bash
php artisan route:list --path=payment/senangpay-webhook
php artisan test tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php
php artisan test tests/Feature/Sentinels/PaymentGatewayRoutesNoMissingClassesSentinelTest.php
npm run production
bash scripts/lint-fk-archive-banner.sh
bash scripts/lint-fk-legacy-imports.sh
bash scripts/scan-bundle-legacy.sh
```

## Decision demandee avant suppression

Pour corriger sans risque, je recommande d'abord l'option A:

- Option A: masquer/corriger runtime + commande dry-run + tests sentinels, aucune suppression physique.
- Option B: option A + purge DB locale de la fausse branche `id=2`, fake user `id=5`, categorie `id=14`, item `id=64`, apres backup SQL.
- Option C: option B + suppression physique des locales `bn/de/ar` et gateways non-France, seulement apres audit complet routes/tests/build.

Recommandation: Option A maintenant. Option B seulement apres backup DB. Option C apres release Caisse V1 ou gate humain dedie.

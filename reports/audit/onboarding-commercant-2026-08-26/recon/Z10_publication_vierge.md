# Z10 — Publication vierge : inventaire de la marque « Le Cayenne » dans le code (ONB-12, W1, lecture seule)

- Date : 2026-08-27 · Méthode : lecture de code exclusivement — aucune requête mutante contre :8800, aucun `migrate`/`db:seed`/`artisan`, aucune écriture. `grep -rn`/`grep -rli` + `Read` file:line sur `app/ config/ resources/js/ database/ routes/ resources/views/`.
- Question directrice : *si un autre restaurant installe ce logiciel demain, que verra-t-il de « Le Cayenne » ?* Réponse courte : **tout**, y compris le nom, l'adresse, le numéro de téléphone, le mot de passe de l'admin par défaut, et la palette de couleurs — parce que l'unique chemin d'installation (`/install` → `InstallerService::databaseSetup()`) exécute `migrate:fresh` + `db:seed`, et `db:seed` (= `DatabaseSeeder`) est câblé sur Le Cayenne de bout en bout.
- Règle anti-fiction appliquée : chaque ligne citée a été ouverte via `Read` ou vue dans une sortie `grep -n` (donc avec son contenu réel, pas seulement son numéro). Un écart avec le plan GOAL est signalé explicitement quand mesuré (voir §1.0).

---

## §1.0 — Compte total (recompté indépendamment le 2026-08-27)

`grep -rli cayenne <dossier>` (insensible à la casse, mot « cayenne » seul — n'inclut PAS les couleurs de marque, voir §1.3) :

| Dossier | Fichiers | Classification par défaut |
|---|---|---|
| `database/` (25 seeders + 12 migrations) | **37** | **DONNÉE** |
| `config/` | **16** | **CONFIGURATION** |
| `app/` | **59** | **CODE EN DUR** |
| `resources/js/` | **33** | **CODE EN DUR** |
| `routes/` | **2** | **CODE EN DUR** (commentaires seuls, non affichés) |
| **Total (= plan §0.6)** | **147** | — |
| `resources/views/` + `resources/lang/` + `resources/js/languages/` | **8** | **CODE EN DUR** (vues) — écart mesuré : le plan §0.6 annonçait 11 pour ce groupe ; `resources/lang/` est vide et `resources/js/languages/*.json` ne contient aucune occurrence de « cayenne » aujourd'hui (vérifié : `default_restaurant_name` y vaut déjà « Notre restaurant » / « Our restaurant », générique). Écart signalé, pas corrigé silencieusement. |

**CODE EN DUR = 94 fichiers sur 147** (59 + 33 + 2), soit 64 %. **DONNÉE = 37** (25 %). **CONFIGURATION = 16** (11 %).

Sur les 94 « CODE EN DUR », une bonne partie (surtout dans `app/Console/Commands/`) est du **commentaire ou du docblock** (traçabilité de décision owner, pas de sortie visible) — je ne les confonds pas avec du texte réellement affiché/imprimé/envoyé ; les deux sont listés séparément en §1.2/§1.3, et seuls les seconds comptent pour C3 du plan (« zéro marque dans le code affiché »).

**Sortie non couverte par le mot « cayenne » : la palette de couleurs.** `grep -rl "#F4501E" resources/js` → **33 fichiers .vue** contiennent le orange de marque en CSS scoped en dur (voir §1.3) — un ensemble presque disjoint des 33 fichiers `resources/js` du tableau ci-dessus (recoupement partiel seulement). Aucune de ces couleurs n'est stockée dans `theme_settings` (`ThemeTableSeeder.php`, 24 lignes, vérifié : aucune valeur hex). **C'est la plus grande surface de marque-en-dur du projet, et elle n'apparaît dans aucun grep « cayenne ».**

---

## §1.1 — DONNÉE (37 fichiers `database/`) — se remplace en changeant la donnée

Les seeders qui écrivent réellement une valeur « Le Cayenne » dans une table (vs. simple commentaire) :

| Seeder | file:line | Valeur écrite |
|---|---|---|
| `CompanyTableSeeder.php:21-24,34` | `Settings::group('company')->set(['company_name'=>'Le Cayenne', 'company_email'=>'contact@lecayenne.fr', 'company_website'=>'https://lecayenne.fr', ...])` **et** `EnvEditor->addData(['APP_NAME'=>'Le Cayenne'])` | Réglage `company` **+ réécrit `.env`** |
| `BranchTableSeeder.php:29-48` | `Branch::create(['name'=>'Le Cayenne (principal)', 'email'=>'contact@lecayenne.fr', 'city'=>'Hénin-Beaumont', 'address'=>'437 Rue Élie Gruyelle, 62110 Hénin-Beaumont', 'zip_code'=>'62110', lat/lng réels du restaurant])` | Table `branches` |
| `UserTableSeeder.php:32-44,80-96,100-116` | Admin `'name'=>'Admin Le Cayenne','email'=>'admin@lecayenne.fr'` ; caissier `'Caissier Le Cayenne'/'pos@lecayenne.fr'` ; chef `'Chef Le Cayenne'/'chef@lecayenne.fr'` | Table `users` (voir §5) |
| `KioskMachineTableSeeder.php:29-53` | `username='kiosk-lecayenne'`, cherche l'owner par `admin@lecayenne.fr` | Table `kiosk_machines` |
| `MailTableSeeder.php:30,41` | `'mail_from_name' => 'Le Cayenne'` (si `DEMO`) | Réglage `mail` + `.env MAIL_FROM_NAME` |
| `PageTableSeeder.php:25,64,92` | Textes CGU/Cookies/À-propos mentionnant « Le Cayenne » | Table `pages` |
| `DeliveryConfigSeeder.php:44,48` | `$branch->address = '437 Rue Élie Gruyelle, 62110 Hénin-Beaumont'` | Table `branches` (réécrit l'adresse) |
| `SimulatedTpeTerminal20260708Seeder.php:28,47` | `'TPE Le Cayenne #1'`, `'serial_number'=>'SIM-CAYENNE-1'` | Table `payment_terminals` |
| `OwnerMenuUpdate20260623Seeder.php:126` | Article `'Cayenne'` (id 22, 7,40 €) | Table `items` |
| `RestoreLeCayenneItemImagesSeeder.php:58-75` | Chemins d'images `sandwich_cayenne.png`, `big_cayenne_2v_avec_oeuf_cheddar.png`, `galette-tondory-cayenne.png` | Media (Spatie) |
| `WizardCayenneAndBolsCorrectionsSeeder.php:43-52` | Constantes d'IDs items 22/24/36 (Cayenne/Galette Cayenne/Big Cayenne) | `item_variations` |
| `SiteTableSeeder.php:44` | `'© Le Cayenne 2026, Tous Droits Réservés'` (footer, si copyright activé) | Réglage `site` |
| `LeCayenneAllergenSeeder.php` | Allergènes des 45 items « Le Cayenne » | `item_allergen` |
| `MenuSeeder.php` (845 lignes) | Le menu complet (catégories, items, prix, variations, extras, addons) — la vraie source du catalogue, quasi aucune occurrence littérale du mot « cayenne » (les noms d'articles sont ce qu'ils sont : « Tacos », « Galette »…) mais **c'est le seeder qui produit un restaurant non-générique** | Table `items` + tout le catalogue |

**12 migrations** (`database/migrations/2026_07_31_*`, `2026_08_01_*`, `2026_08_03_*`, `2026_08_19_*`…) appellent toutes `EnsureCayenneMixteCommand::ensure(false)` ou touchent directement l'item nommé `'Cayenne'` en base — ce sont des correctifs de production déjà appliqués, DONNÉE au sens strict (ils ne s'exécutent qu'une fois, au déploiement), mais ils prouvent que **le nom exact `'Cayenne'` est une clé de recherche en dur** dans plusieurs migrations (ex. `2026_08_01_140000_fix_borne_cayenne_viande_bloquee.php:44` : `->whereIn('item_id', DB::table('items')->where('name', 'Cayenne')->pluck('id'))`).

`LeCayenneRoleLandingUrlSeeder.php` (61 lignes) — **nom trompeur** : malgré son nom, le contenu (`§`role → landing_url) est déjà générique (Admin/Chef/POS Operator/Branch Manager/Waiter/Stuff/Customer → routes), aucune valeur « Cayenne » n'y est écrite. C'est un socle mal nommé, pas une fuite de marque.

---

## §1.2 — CONFIGURATION (16 fichiers `config/`) — se remplace par un réglage

Tous portent des valeurs par défaut `env('X', 'valeur-cayenne')` — modifiables sans toucher le code, via `.env` :

| Fichier:ligne | Défaut |
|---|---|
| `config/app.php:123,129` | `admin_email` → `admin@lecayenne.fr` ; `pos_operator_email` → `pos@lecayenne.fr` (démo, gaté par `demo_mode`) |
| `config/printing.php:83,109,185` | `RECEIPT_WEBSITE` → `lecayenne.fr` ; `RECEIPT_PHONE` → `03 65 67 82 91` ; `CUSTOMER_DISPLAY_WELCOME1` → `LE CAYENNE` |
| `config/wheel.php:55,117,260` | `WHEEL_PUBLIC_URL` → `https://www.lecayenne.fr` ; item de roue `'cost_item_name'=>'Cayenne'` ; `WHEEL_FACEBOOK_URL` → `facebook.com/LeCayenne` |
| `config/payment.php:124` | `MOLLIE_APPLE_PAY_DOMAIN` → `www.lecayenne.fr` |
| `config/services.php:114` | `APPLE_AUDIENCES` → `fr.lecayenne.app` |
| `config/uber.php:19` | commentaire « Store UUID Le Cayenne » sur `UBER_STORE_ID` (valeur elle-même vide) |
| `config/kiosk.php:180,275` | mapping `'cayenne' => 'sandwich'` (alias wizard) ; `$username = 'kiosk-lecayenne'` **en dur dans une branche `if (env('APP_ENV')==='local')`** — ceci est de la LOGIQUE exécutable dans un fichier config, pas une simple valeur ; nuance à noter pour ONB-05/BORNE |
| `config/menu.php` (772 lignes) | **restaurant.name = 'Le Cayenne'** (ligne 25), 11 catégories, sauces, viandes… **MAIS quasi jamais lu par le code** (voir ci-dessous) |
| `config/menu_images.php` | mapping slug→PNG « Le Cayenne owner-curated asset pack » (ligne 8) |
| `config/features.php`, `config/cash.php`, `config/cors.php`, `config/kds.php`, `config/security.php`, `config/pos.php`, `config/uber_menu_map.php` | commentaires de contexte uniquement, aucune valeur affichée |

**Découverte notable** : `config/menu.php` (772 lignes, contient le nom, l'adresse, les 11 catégories et les items « Le Cayenne ») est **quasiment mort** — recherche exhaustive de `config('menu.` dans `app/ resources/js/` : **une seule lecture réelle**, `OrderQuoteService.php:724` → `config('menu.currency', 'EUR')` (une clé générique). Les deux autres mentions (`TaxTableSeeder.php:93`, `MenuSeeder.php:486`) sont des **commentaires**, pas du code exécuté. Autrement dit : ce fichier de 772 lignes qui *ressemble* à la source de vérité du menu Le Cayenne **ne pilote rien en pratique** — le vrai menu vient de `MenuSeeder.php` (DB). Un développeur qui « nettoierait » `config/menu.php` en pensant dé-cayenniser l'app ne changerait rien à l'écran ; à l'inverse, en laisser le contenu ne pollue aucun écran. Point utile pour W3 (S3 dé-cayennisation) : ce fichier est un candidat sûr à vider en premier (risque quasi nul), mais ce n'est PAS le nœud du problème.

---

## §1.3 — CODE EN DUR : les 10 occurrences les plus gênantes (vérifiées file:line)

Classées par visibilité/impact pour un commerçant qui installerait le logiciel demain :

1. **`app/Services/InstallerService.php:40-41`** — `databaseSetup()` exécute `Artisan::call('migrate:fresh', ['--force'=>true])` puis `Artisan::call('db:seed', ['--force'=>true])`. **C'est la cause racine** : le seul chemin d'installation possible lance directement les seeders Le Cayenne. Rien dans l'installeur ne bifurque vers un jeu de données générique.
2. **`resources/views/installer/site.blade.php:36-48`** + **`app/Services/InstallerService.php:16-24`** — l'installeur demande un `app_name` à l'intégrateur (`siteStore()` écrit `.env APP_NAME = $request->app_name`) à l'étape *Site*, **avant** l'étape *Database*. Mais l'étape *Database* qui suit immédiatement (finding #1) exécute `db:seed` → `CompanyTableSeeder.php:33-35` **réécrit `.env APP_NAME` à `"Le Cayenne"` inconditionnellement**, sans lire ni préserver la valeur saisie par l'intégrateur. Le seul champ d'identité que l'installeur propose est donc **écrasé silencieusement 1 étape plus tard**.
3. **`resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue:346`** — `' <span class="cay-accent">Le Cayenne</span>'` : le nom en dur dans le HTML de l'écran d'accueil borne (surface la plus visible du client). Il existe pourtant un fallback générique fonctionnel juste au-dessus (ligne 422/519 : `$t('kiosk.idle_screen.default_restaurant_name')` = « Notre restaurant ») — mais cette ligne 346 ne l'utilise pas.
4. **`resources/js/components/frontend/auth/LoginComponent.vue:200-201`** — `this.form.email = demo.adminEmail || 'admin@lecayenne.fr'; this.form.password = demo.adminPassword || '123456';`. Le commentaire juste au-dessus (ligne 196-197) dit explicitement *« Never hardcode real restaurant credentials in the JS bundle »* — et la ligne suivante le fait quand même, en fallback. Le bouton qui déclenche cette fonction est gaté par `demo === 'true'` (ligne 69), mais le code (et donc les identifiants) est présent dans **tout bundle compilé, sur toute installation**, lisible en `view-source`.
5. **`resources/js/components/frontend/tracking/OrderTrackingPageComponent.vue:4`** — `<div class="ot-logo">Le Cayenne</div>` : logo textuel en dur, aucune variable, sur la page de suivi de commande cliente.
6. **`resources/js/components/admin/pos/PosComponent.vue:80`** — `<p class="pos-v5-operator-bar__eyebrow pos-v4-eyebrow">Caisse Le Cayenne</p>` : bandeau visible en permanence sur l'écran caisse (POS), une des surfaces les plus utilisées.
7. **`resources/js/components/admin/pos/FloorplanComponent.vue:6`** et **`PosOrdersTrackerComponent.vue:14`** — mêmes bandeaux « Caisse Le Cayenne » en dur.
8. **33 fichiers `.vue`** contiennent la couleur de marque **`#F4501E`** codée en dur dans des blocs `<style>` scoped (ex. `KioskIdleScreenComponent.vue:572-856`, tous les composants `kiosk/steps/*`, `KitchenDisplaySystemComponent.vue`, `RawMaterialAdjustComponent.vue:351`, `UnifiedStockViewComponent.vue:324` — liste complète obtenue par `grep -rl "#F4501E" resources/js`). Aucune de ces couleurs ne passe par `theme_settings` (DB) : changer la couleur de marque pour un autre restaurant exige d'éditer ~33 fichiers Vue, pas un réglage.
9. **`app/Services/Hardware/OrderReceiptEscPosRenderer.php:70`** — `EscPosCommandBuilder::textWrap(optional($branch)->name ?: 'LE CAYENNE', $w)` : fallback en dur sur le ticket de caisse imprimé si la branche n'a pas de nom (filet de sécurité voulu, mais le filet est nommé).
10. **`resources/views/admin/wheel/borne.blade.php:571,595,802`** — `alt="Le Cayenne"` sur le logo affiché à l'écran de la roue, et tableau de secours `LOTS = [..., 'Cayenne', 'Terminator']` codé en dur dans le `<script>` inline si l'API des lots échoue.

Complément vérifié (hors top 10 mais notable) : `app/Services/Promo/PromoFlyerService.php:43,63-64` — `DEFAULTS = ['headline'=>'LE CAYENNE', 'site_url'=>'www.lecayenne.fr', ...]`. Nuance en faveur du code : le commentaire ligne 38-39 dit vrai — ce sont des valeurs *par défaut*, « TOUTES surchargeables depuis l'admin » via les réglages `promo_flyer` ; un exploitant peut les changer sans développeur. Reste classé CODE EN DUR (constante PHP, pas `config/`) mais avec un chemin de sortie qui n'exige pas de code.

---

## §2 — Les seeders : `migrate --seed` sur une base neuve produit-il un restaurant générique ?

**Non — il produit Le Cayenne, intégralement.** `database/seeders/DatabaseSeeder.php:33-133` (134 lignes) appelle 36 seeders dans l'ordre, dont **au moins 9** écrivent des données de marque (voir §1.1) : `CompanyTableSeeder` (`:41`), `BranchTableSeeder` (`:47`), `UserTableSeeder` (`:48`), `LeCayenneRoleLandingUrlSeeder` (`:61`, nom trompeur — inoffensif, voir §1.1), `KioskMachineTableSeeder` (`:70`), `MenuSeeder` (`:100`, le catalogue complet).

Aucun de ces appels n'est conditionné à une variable du type `INSTALL_MODE` ou `TENANT_BRAND` — ils s'exécutent tous, toujours, dans l'ordre fixe. Il n'existe **aucun jeu de seeders « socle générique »** distinct : les 94 seeders du dossier ne sont pas classés (le plan §T-1.1.1 le demande, non fait — hors scope W1 lecture seule).

**Garde production, et pourquoi elle ne protège pas l'installeur** : `UserTableSeeder.php:26-29` et `KioskMachineTableSeeder.php:21-24` refusent de s'exécuter si `app()->environment('production')`. Mais `.env.example:15` et `.env:2` livrent `APP_ENV=local` par défaut, et `InstallerService::finalSetup()` (`app/Services/InstallerService.php:104-123`) ne bascule `APP_ENV=production` **qu'à la toute dernière étape** (`/install/final-store`), **après** que `databaseStore()` a déjà appelé `db:seed` (étape précédente, `/install/database`). Au moment où les seeders tournent pendant une installation réelle via `/install`, `APP_ENV` vaut encore `local` → **la garde ne se déclenche pas** → `admin@lecayenne.fr` / mot de passe `123456` et `kiosk-lecayenne` / `kiosk123` sont bien créés à l'installation.

---

## §3 — L'installateur : ce qui existe, ce qui manque

**Ce qui existe** : un installeur Blade complet à `/install` (`routes/web.php:22-33`, `app/Http/Controllers/Installer/InstallerController.php`, 153 lignes) avec un flux en 6 étapes (`welcome → requirement → permission → license → site → database → final`), une garde anti-réinstallation (`InstallerController.php:37-42`, fichier sentinelle `storage/installed`), et un service dédié (`app/Services/InstallerService.php`, 125 lignes).

**Ce qui manque** :
- **Aucun champ d'identité de restaurant** dans le formulaire d'installation, hormis `app_name`/`app_url` (`resources/views/installer/site.blade.php:33-48`) — pas d'adresse, pas de téléphone, pas de nom d'administrateur, pas de choix de mot de passe admin.
- Le seul champ saisi (`app_name`) est **écrasé** par `CompanyTableSeeder` une étape plus tard (finding #2 ci-dessus) — bug fonctionnel constaté, pas supposé.
- **Aucune commande artisan d'installation** dédiée à un établissement (le plan ONB-12 §3 T-1.1.3 en prévoit une, `foodking:installer --etablissement=...` — **elle n'existe pas** ; vérifié : `app/Console/Commands/` ne contient aucun fichier de ce nom, seulement des commandes de maintenance/correctif du menu Cayenne).
- **Aucune séparation socle / données de marque** dans `db:seed` (voir §2) : l'installeur Blade n'a pas d'option pour sauter les seeders Le Cayenne.
- Pas de choix de langue/devise/pays à l'installation (implicitement FR/EUR partout, cohérent avec le mandat V1 mais non explicite pour un futur second restaurant).

---

## §4 — La marque visible sur les pages principales, et sa source

| Page | Ce qui apparaît | Source |
|---|---|---|
| `/login` | Titre onglet = `Settings::group('company')->company_name` (DB, DONNÉE) ; fallback `config('app.name') ?: 'Le Cayenne'` (`master.blade.php:59`, CODE EN DUR) ; boutons démo → emails/mdp en dur (`LoginComponent.vue:200-201`, CODE EN DUR, finding #4) |
| `/kiosk/idle` (borne accueil) | Nom en dur `<span class="cay-accent">Le Cayenne</span>` (`KioskIdleScreenComponent.vue:346`, finding #3) ; couleurs `#F4501E/#FFB800/#1A1A1A` en dur dans le `<style>` (finding #8) |
| `/admin/pos` (Caisse) | Bandeau « Caisse Le Cayenne » en dur (`PosComponent.vue:80`, finding #6) ; `company.company_name` sur les tickets (`PosComponent.vue:3240`, `ReceiptComponent.vue:99,294,305` — DB, DONNÉE) |
| `/admin/items` (Catalogue) | Aucun texte de marque en dur trouvé dans `ItemListComponent.vue` (seule occurrence = un commentaire ligne 479) ; le catalogue lui-même vient de `MenuSeeder` (DONNÉE) |
| `/admin/stock/rupture` | Aucun texte de marque en dur ; seule mention = un commentaire de test (`StockRuptureDashboardComponent.vue:173`) faisant référence au nom d'un produit affiché depuis la DB |
| `/kds` (Kitchen Display) | Pas de texte « Cayenne » visible à l'écran (les occurrences dans `KitchenDisplaySystemComponent.vue`, `KdsOrderCard.vue`, `KdsV2Grid.vue`, `KdsHistoryDrawer.vue` sont des **commentaires CSS** documentant que le hex `#F4501E` est la couleur de marque — la couleur elle-même est bien en dur dans le CSS, mais aucun libellé texte) |
| `/admin/order-status-screen` (OSS) | `OrderStatusScreenOrderService.php:122` = commentaire uniquement, pas de sortie visible trouvée |
| Suivi de commande client | Logo texte en dur `Le Cayenne` (`OrderTrackingPageComponent.vue:4`, finding #5) |
| Confirmation borne | `lists?.company_name \|\| lists?.site_name \|\| 'Le Cayenne'` (`KioskConfirmationComponent.vue:251`) — DB d'abord, fallback en dur |
| Instruction paiement espèces borne | `let restaurantName = 'Le Cayenne';` (`KioskCashInstructionComponent.vue:193`) — valeur initiale en dur avant tentative de lecture du store |
| Écran roue (`admin/wheel/borne.blade.php`) | Titre `<title>Le Cayenne — Tourne la roue</title>` (`:47`), `alt="Le Cayenne"` sur le logo (`:571,595`), tableau de secours contenant `'Cayenne'` (`:802`) |
| Ticket de caisse imprimé (ESC/POS) | `branch->name` en priorité (DB, DONNÉE) sinon fallback `'LE CAYENNE'` en dur (`OrderReceiptEscPosRenderer.php:70`) ; téléphone/site/adresse via `config/printing.php` (CONFIGURATION, finding table §1.2) |

**Constat global** : sur 12 surfaces regardées, **6** affichent au moins un élément de marque codé en dur (borne accueil, POS x2, confirmation borne, cash instruction borne, suivi commande, roue), **aucune** n'affiche de texte « Cayenne » sourcé uniquement en base sans un fallback en dur quelque part à proximité — le pattern *DB d'abord, fallback Cayenne en dur* est systématique, ce qui est protecteur (jamais de plantage) mais garantit qu'un champ vide en base fait quand même apparaître « Le Cayenne » chez un autre restaurant.

---

## §5 — Le compte administrateur par défaut

**Vérifié `database/seeders/UserTableSeeder.php:32-44`** : à l'installation, un compte est créé —
- `name` = `'Admin Le Cayenne'`
- `email` = `'admin@lecayenne.fr'`
- `password` = `bcrypt('123456')` — **mot de passe en clair dans le code source**, 6 caractères, numérique
- `branch_id` = 0 (admin global), rôle `EnumRole::ADMIN` assigné ligne 44

**Aucun mécanisme de changement de mot de passe forcé n'existe** : recherche exhaustive de `must_change_password|force_password_change|password_change_required|change_password_at_login|first_login` sur `app/` et `database/migrations/` → **0 résultat**. Rien dans le modèle `User`, aucune migration, aucun middleware ne force un changement au premier login.

Deux comptes additionnels sont créés systématiquement (pas seulement en mode `DEMO`) : `pos@lecayenne.fr` (Caissier, `UserTableSeeder.php:80-92`) et `chef@lecayenne.fr` (Chef, `:100-116`) — même schéma de mot de passe `123456` en dur. Le mot de passe admin est de plus **exposé côté client** dans `config/app.php:123-124` (`demo_credentials.admin_password` défaut `'123456'`) et injecté dans `window.__FOODKING_RUNTIME__` (`master.blade.php:318-320`) — mais uniquement si `config('app.demo_mode')` (= `env('DEMO')`) est vrai ; par défaut `DEMO` n'est pas positionné dans `.env`/`.env.example` (non vérifié positivement — absence de ligne `DEMO=` trouvée dans les deux fichiers, donc `env('DEMO', false)` retombe sur `false`).

Comme établi en §2, la garde « jamais en production » de ce seeder ne protège pas le chemin d'installation réel (`/install`), parce que `APP_ENV` ne passe à `production` qu'après le `db:seed`. **Un intégrateur qui suit le flux `/install` obtient donc un compte admin nommé « Le Cayenne », avec un mot de passe public (visible dans ce dépôt), jamais forcé à changer.**

---

## Résumé pour la décision propriétaire (G0)

Le problème n'est pas seulement le nombre de fichiers (147 + 33 couleurs) : c'est que le **seul chemin d'installation existant** (`/install`) est **câblé en direct** sur les seeders Le Cayenne (§2), que le **seul champ d'identité proposé** à l'intégrateur est écrasé une étape plus tard (§1.3 finding #2), et que le **compte admin créé porte le nom et l'adresse email de la marque avec un mot de passe faible jamais forcé à changer** (§5). Dé-cayenniser ne se limite donc pas à renommer des chaînes : il faut (1) séparer `db:seed` en deux arbres (socle / marque), (2) faire respecter le nom saisi à l'installation au lieu de le réécrire, (3) revoir le compte admin par défaut. Ce sont exactement les tâches T-1.1.2/T-1.1.3 du plan ONB-12 — cet inventaire confirme qu'elles sont nécessaires et donne les 3 preuves concrètes ci-dessus pour la décision G0.

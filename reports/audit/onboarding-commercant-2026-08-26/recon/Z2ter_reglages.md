# Z2ter — Réglages sans développeur : ce qu'un commerçant peut régler lui-même
- W1 du GOAL ONB-05. Lecture seule stricte — aucune écriture DB/.env effectuée. HEAD `43b120c7d`, worktree `goal-onboarding-commercant-2026-08-26`.
- Sources lues intégralement : `plans/GOAL_ONB05_REGLAGES_SANS_DEVELOPPEUR_2026-08-26.md`, `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB05_REGLAGES_SANS_DEVELOPPEUR.md`, `app/Services/Pilotage/InterrupteurService.php` (166 lignes).

## 1. Le catalogue d'interrupteurs — EXACT, vérifié dans le code

`InterrupteurService::CATALOGUE` (`app/Services/Pilotage/InterrupteurService.php:43-90`) = **6 entrées, toutes booléennes**. Aucun seuil (nombre), délai ou plage horaire n'est réglable par ce mécanisme : le type retour de `valeur()` est `bool` (`:95`), `regler(string $nom, bool $actif)` n'accepte que `bool` (`:114`). Le commentaire `:56-65` le dit explicitement : les candidats numériques/texte/horaires (tolérance caisse, barème livraison, mention ticket, seuil stock, heures de service) sont **exclus par conception** « jusqu'à un mécanisme de réglages TYPÉS dédié (hors scope ici) ».

| Nom | Clé config | Fait quoi | Consommé où |
|---|---|---|---|
| `split_payment` | `split_payment.enabled` | Autorise le règlement en plusieurs fois (espèces+carte) | 8 appels `config('split_payment.enabled')` dont 5 dans le chemin de paiement (commentaire `:23-24`, non ré-audités ici) |
| `wheel` | `wheel.enabled` | Active la roue promo post-commande | `:50-55` |
| `remise_manuelle` | `pos.manual_discount_enabled` | Autorise la remise libre en caisse | `config/pos.php` (`manual_discount_enabled`, lu par `OrderService::assertPosManualDiscountAllowed` — non ré-vérifié ici) |
| `fidelite` | `pos.loyalty_enabled` | Active le rachat (dépense) de points fidélité | `config/pos.php:233-237` |
| `kiosk_promo` | `kiosk.promo_enabled` | Affiche le champ code promo sur la borne | `config/kiosk.php:70` |
| `impression_ticket_client_auto` | `printing.auto_print_client_receipt` | Impression auto du ticket client à l'encaissement | non ré-vérifié (hors scope W1) |

Exclusion volontaire et documentée : `idempotency.enabled` **n'est pas** dans le catalogue (`:27-33`) — protection NF525 sous garde de démarrage (`AppServiceProvider`), jamais un interrupteur.

API : `GET/PUT /api/admin/pilotage/interrupteurs[/{nom}]` (`routes/api.php:1669-1670`, contrôleur `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php`). **Constat de sécurité** : `InterrupteurController::index()` (`:30-33`) n'a **aucune** vérification de permission — seul `update()` vérifie `Admin`/`Tenant Admin` en dur (`:38`, pas de `permission:settings`). Ceci corrobore le P2 de la MISSION (« lecture ouverte au caissier ») directement sur ce contrôleur.

## 2. Les 22 (en réalité 23 actives) pages `settings.*` cachées — vérifiées une à une

`resources/js/config/v1-hidden-modules.js` contient 32 entrées actives au total : **9 « modules »** (non liés à Réglages) + **23 clés `settings.*` actives** (24 lignes, dont 1 commentée = `settings.loyalty-setup`, donc démasquée, `:32`). Le chiffre « 22 » du GOAL est légèrement daté : c'est 23 aujourd'hui.

Vérification croisée : `resources/js/router/modules/settingRoutes.js` (liste exhaustive lue en entier) + `resources/js/components/admin/settings/MenuComponent.vue` (mapping `HIDDEN_KEY_TO_LOCAL_SETTING:154-179`, guards `v-if` réels `:39-130`).

### 19 clés `settings.*` cachées → PAGE RÉELLE (route + composant existent, juste invisible dans le sous-menu Réglages)
| Clé | Route (`settingRoutes.js`) | Composant |
|---|---|---|
| `settings.mail` | `admin.settings.mail` | `Mail/MailComponent` |
| `settings.notification` | `admin.settings.notification` | `Notification/NotificationComponent` |
| `settings.theme` | `admin.settings.theme` | `Theme/ThemeComponent` |
| `settings.item-categories` | `admin.settings.itemCategory` | `ItemCategory/*` |
| `settings.item-attributes` | `admin.settings.itemAttribute` | `ItemAttribute/*` |
| `settings.role` | `admin.settings.role` | `Role/*` |
| `settings.tax` | `admin.settings.tax` | `Tax/*` |
| `settings.languages` | `admin.settings.language` | `Language/*` |
| `settings.otp` | `admin.settings.otp` | `Otp/OtpComponent` |
| `settings.notification-alert` | `admin.settings.notificationAlert` | `NotificationAlert/*` |
| `settings.social-media` | `admin.settings.socialMedia` | `SocialMedia/*` |
| `settings.cookies` | `admin.settings.cookies` | `Cookies/*` |
| `settings.analytics` | `admin.settings.analytic` | `analytics/*` |
| `settings.time-slots` | `admin.settings.timeSlot` | `TimeSlot/*` |
| `settings.sliders` | `admin.settings.slider` | `Slider/*` |
| `settings.pages` | `admin.settings.page` | `Page/*` |
| `settings.sms-gateway` | `admin.settings.smsGateway` | `SmsGateway/*` |
| `settings.payment-gateway` | `admin.settings.paymentGateway` | `PaymentGateway/*` |
| `settings.license` | `admin.settings.license` | `License/LicenseComponent` |

⚠️ **`settings.tax` confirmé cassé comme signalé** : `v1-hidden-modules.js:39` cache la clé, `MenuComponent.vue:103-106` porte bien un `v-if="!isSettingHidden('tax')"` (aucune ré-injection ailleurs, contrairement à Attributs ci-dessous) → la page Taxes existe (`admin.settings.tax`, composant `Tax/TaxComponent`) mais est **strictement inatteignable au clic**, seule une URL directe `/admin/settings/taxes` l'ouvre. Un commerçant ne peut pas créer/modifier une TVA sans connaître l'URL.

**Autre incohérence confirmée** (P2 déjà noté par Z2) : `settings.item-attributes` est caché du sous-menu Réglages (`v1-hidden-modules.js:36`, `MenuComponent.vue:99`) **ET** ré-injecté comme enfant virtuel de « Items » dans la barre latérale principale : `resources/js/components/layouts/backend/BackendMenuComponent.vue:94-99` (`VIRTUAL_CHILDREN_BY_URL.items` pousse `settings/item-attributes/list`). Donc Attributs reste atteignable (par un autre chemin), Taxes non — incohérence réelle, pas seulement crainte.

### 4 clés `settings.*` MORTES — aucune route, aucun composant, aucun effet
| Clé | Preuve d'absence |
|---|---|
| `settings.permission` | Aucune route dans `settingRoutes.js` ; aucun composant `Permission*` dans `resources/js/components/admin/settings/`. `MenuComponent.vue:161` la mappe à un local `permission` jamais utilisé dans le template (`grep` : 0 `v-if="!isSettingHidden('permission')"`). Cacher cette clé ne cache RIEN — la page « Rôle & Autorisations » unique existe déjà sous `settings.role`. |
| `settings.charge` | Idem : ni route, ni composant, ni référence `v-if` dans `MenuComponent.vue`. |
| `settings.translation` | Idem. |
| `settings.activity-log` | Idem : `grep -rn "activity-log\|activityLog\|ActivityLog"` sur `resources/js/router` et `resources/js/config` → seule occurrence est la ligne de la liste elle-même. |

**Conclusion §2** : cacher ces 4 clés est un artefact d'attente sans effet ; il ne reste donc que **19 pages réellement masquées** (et fonctionnelles) sur les 23 déclarées, dont **Taxes** est celle qui mérite priorité immédiate au vu de la demande explicite du propriétaire (une TVA se règle très tôt dans l'onboarding).

### 9 « modules » (hors Réglages) — tous VÉRIFIÉS existants (pas morts)
`customers`, `coupons`, `offers`, `waiters`, `delivery-boys`, `admin.order` (online orders), `admin.diningTable`, `table.*` (table orders — routes non-admin `table.menu.table` etc.), `creditBalanceReport` — chacun a un fichier `resources/js/router/modules/*Routes.js` avec des `name: "admin.…"` réels et redirect vers une liste (vérifié par grep direct, pas par le commentaire du fichier). ⚠️ Différence de nature avec les 19 pages `settings.*` ci-dessus : ce sont des **modules produit complets** (Clients, Coupons, Offres, Livreurs, Commandes en ligne/table, Serveurs, Tables, Rapport crédit), pas des écrans de configuration — leur statut « garder/cacher » relève d'une décision produit (V1 local vs modules V2), traité par le tableau G-CACHE du GOAL, pas détaillé ligne à ligne ici (hors du périmètre precis de cette recon centrée réglages).

## 3. Réglages sans écran — vérifiés file:line

| Réglage (phrase commerçant) | file:line | Détail |
|---|---|---|
| Tolérance d'écart de caisse tolérée avant justification obligatoire | `config/cash.php:31` `variance_threshold_eur` (env `CASH_VARIANCE_THRESHOLD_EUR`, défaut 2.00€), lu par `app/Services/Cash/CashDrawerService.php:266` | Au-delà, le caissier doit motiver l'écart et un Admin/Gérant doit approuver (`:267-268`, permission `cash.reconcile.variance.override`). Aucun formulaire ne l'expose ; recherche `grep threshold_low\|variance` dans `app/Http/Requests` = 0 résultat. |
| Seuil de stock bas (déclenche l'alerte rupture) | `stock_levels.threshold_low` / `raw_materials.threshold_low` (colonnes DB, `database/migrations/2026_07_23_140000_create_raw_material_tables.php:41`), lu par `app/Http/Controllers/Admin/StockRuptureDashboardController.php:99-100` et `app/Listeners/NotifyStockLowOnStockLevelChanged.php:50-55` | Aucune route d'écriture trouvée (`grep threshold_low routes/api.php` = 0 résultat) : valeur figée à 0 par défaut d'après le commentaire `NotifyStockLowOnStockLevelChanged.php:20-22` (« V1.0.2 admin UI... »). |
| Numéro de départ de la file borne chaque jour | `config/kiosk.php` (`$queueStartNumber = (int) env('KIOSK_QUEUE_START_NUMBER', 32)`, bloc commenté « [owner 2026-07-07] ») | Défaut A0032. Changer le numéro de départ = éditer `.env` et redémarrer. |
| Plafond de quantité par article à la borne | `config/kiosk.php` (`'max_item_qty' => (int) env('KIOSK_MAX_ITEM_QTY', 20)`) | Empêche un client de commander plus de 20 unités d'un même article. |
| Fenêtre et seuil des alertes SLA cuisine (« commande en retard ») | `config/dashboard.php:24` (`sla_alerts_window_hours`, défaut 24h) et `:29` (`sla_alerts_threshold_minutes`, défaut 15 min) | Contrôle quand une commande en préparation devient une alerte « retard » sur le tableau de bord. |
| (bonus, hors S3 mais même famille) Barème de frais de livraison | `branches.delivery_fee_base` / `_per_km` / `_minimum` / `free_km` (colonnes, pas de config) | `OrderSetupRequest.php:32-45` : les 3 champs équivalents ont été retirés du formulaire « Configuration des commandes » le 2026-08-14 car ils n'étaient lus par aucun code métier — le vrai calcul lit ces colonnes `branches` sans écran admin (territoire ONB-01). |

Réglage qui, lui, EST déjà réglable par écran (pour contraste) : `order_setup_food_preparation_time` — `app/Http/Requests/OrderSetupRequest.php:28` (« temps de préparation »), formulaire `OrderSetupComponent.vue`.

## 4. Propagation — vérifiée

- **Interrupteurs** : écriture immédiate. `InterrupteurService::regler()` (`:114-126`) fait `Settings::group('pilotage')->set()` PUIS `Config::set($cle, $actif)` (`:120-123`) dans la même requête — la réponse HTTP reflète déjà le nouvel état, sans attendre un redémarrage. Au prochain boot, `appliquerAuDemarrage()` (`:153-165`) rejoue toutes les valeurs stockées.
- **Réglages `settings/*` classiques** (Company, Site, etc.) : `App\Events\SettingsUpdated::dispatch(['company'])` déclenché dans `app/Http/Controllers/Admin/CompanyController.php:36` après chaque `update()`. `app/Http/Controllers/Frontend/SettingController.php:20-27` relit `SettingService->list()` à chaque appel — **aucun `Cache::` dans `app/Services/SettingService.php`** (grep confirmé) → pas de cache serveur à purger, lecture toujours fraîche.
- **Piège bundle** : `resources/views/master.blade.php:147` construit `window.foodkingConfig` avec des valeurs `config()` **au rendu Blade de la page** (ex. `posWizardComposerAware`, `kioskAutoLogin`). Un réglage qui alimente ce bloc n'est visible côté client qu'**après rechargement complet de la page** (borne/POS), jamais en live — ce n'est pas un bug, c'est la nature de l'injection server-side-au-chargement.
- **Non vérifié dans cette recon (hors scope W1 strict)** : le délai réel vu par KDS/borne pour les réglages `config/pos.php` / `config/kiosk.php` cités en §3 — ceux-là sont lus via `config()` PHP côté serveur à chaque requête (pas de cache trouvé), donc a priori immédiats aussi, mais aucun essai d'écriture n'a été fait ici (interdiction stricte de la mission).

## 5. Danger — à ne jamais exposer tel quel à un commerçant

- **`idempotency.enabled` / tout `fiscal.*`** — exclusion déjà actée et documentée en dur dans le code (`InterrupteurService.php:27-33`) ; boot guard production dans `AppServiceProvider` (CLAUDE.md §8). Aucune trace d'intention de les exposer dans ce GOAL (`§0.3` SCOPE-4, `§3` liste noire prévue).
- **`cash.variance_threshold_eur`** (tolérance d'écart de caisse) — c'est justement le contrôle anti-fraude NF525 : l'exposer SANS borne haute ni journal d'audit permettrait à un commerçant malhonnête de relever le seuil pour masquer un écart de caisse répété. Le GOAL le sait (`G-CAISSE-TOL`, gate propriétaire explicite, encore EN ATTENTE) — à n'exposer qu'avec bornes dures + journal, jamais en libre saisie.
- **`kiosk.payment_route_all_to_counter`** (Plan B : tous les paiements borne repassent par la caisse) — bascule qui change le flux d'encaissement complet borne→caisse ; le GOAL l'écrit explicitement : « ne pas exposer sans gate ».
- **`pos.walkin_route_to_counter`** — gate propriétaire déjà tranchée à `false` ; le GOAL demande de ne PAS changer la valeur en l'exposant par erreur dans le catalogue générique.
- **Toute clé absente de `InterrupteurService::CATALOGUE`** reste non modifiable par l'API actuelle par construction (liste blanche, pas un filtre — `:38-39`), donc aucun risque immédiat côté interrupteurs existants ; le risque se posera au moment où le futur mécanisme typé (S1 du GOAL) construira sa propre liste noire — à vérifier de nouveau à ce moment-là.
- Plus largement (déjà connu, hors du périmètre code de cette recon) : les 5 boot guards `AppServiceProvider.php:78-145` (`POS_SIMULATION_HARDWARE`, `IDEMPOTENCY_MIDDLEWARE_ENABLED`, `APP_DEBUG`, `APP_URL`, `CACHE_DRIVER`) sont des variables `.env`, jamais des « réglages » — aucun écran ne doit jamais les exposer.

---
Aucune écriture effectuée pendant cette recon (aucun POST/PUT/PATCH/DELETE, aucun test de bascule). Toutes les affirmations ci-dessus sont sourcées par lecture directe de fichier ou par `grep` reproductible cité en ligne.

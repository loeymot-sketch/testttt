# GOAL — ONB-05 RÉGLAGES SANS DÉVELOPPEUR
## FoodKing — Onboarding commerçant · interrupteurs typés, les 22 pages cachées tranchées, réglages métier (tolérance caisse, barème, seuils, mention ticket, heures de service) pilotés depuis le Dashboard

- **Slug** : `ONB05_REGLAGES_SANS_DEVELOPPEUR_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « réglages & visibilité du menu » ; **propriétaire unique** de `v1-hidden-modules.js`, `settings/MenuComponent.vue`, `BackendMenuComponent.vue` (visibilité)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB05_REGLAGES_SANS_DEVELOPPEUR.md`
- **Port de session** : **8805** · **Persona** : Nadia veut changer la tolérance d'écart de caisse à 5 €, un seuil de stock bas, autoriser la remise manuelle — sans appeler personne.

> **En cinq lignes.** Le problème : le seul mécanisme « sans déploiement » est `InterrupteurService::CATALOGUE` = **6 booléens**, booléen « par conception »
> (`:56-65`) ; **22 des 31 sous-pages Réglages sont cachées** par une liste codée en dur ; les réglages numériques/texte/horaires (tolérance d'écart 2 €, barème
> livraison, seuil stock bas, mention légale, temps de préparation, numéro de départ de file borne…) vivent dans `config/*.php`, `.env` ou des colonnes sans écran —
> « 45 réglages exigent un développeur » (15/08). Preuve : `recon/Z2_profil_reglages.md` (25 pages cachées ouvertes, 6 interrupteurs lus), `Z0_modele §C-D`.
> FINI = un catalogue **déclaratif de réglages typés** (bool/nombre/texte/horaire/choix) avec validation, aide FR, effet immédiat et journal ; les 22 pages
> tranchées (garder / cacher / retirer) par G-CACHE et exécutées ; C1..C6. Ce GOAL exécute les dé-cachages demandés par 01/02/06/09/10 et **ne touche pas**
> à `idempotency.enabled` ni à quoi que ce soit de fiscal. Premier geste : W0 puis lecture intégrale de `InterrupteurService.php` (165 lignes).

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb05-reglages`, branche `goal/onb05-reglages-2026-08-26`, depuis **HEAD** (jamais `origin/main`).
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8805` ; `.env.testing` ; `vendor/`+`node_modules/` en liens durs ; `ReflectionClass(App\Services\Pilotage\InterrupteurService::class)` → worktree ;
  `php artisan serve --port=8805` ; `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8805`.
- Base partagée : les réglages sont **globaux** (table `settings`, groupe `pilotage`) → tout essai d'écriture note la valeur AVANT et RESTAURE à l'identique (discipline Z2 mesurée) ;
  jamais `migrate:fresh` ; tests via `safe-test.sh --phpunit "Pilotage|Interrupteur|Settings|OrderSetup|KioskSetup"`.
- ⚠️ Ce GOAL possède les fichiers de visibilité du menu : **toute autre session qui voudrait les éditer entre en collision** — l'index l'interdit ; les demandes arrivent par fiches de renvoi (MISSION §8 des autres GOAL).
- Filet : `git branch backup/pre-onb05-2026-08-26` + `mysqldump foodking_e2e settings`.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Mécanisme de réglages typés | `app/Services/Pilotage/InterrupteurService.php`, `app/Http/Controllers/Admin/Pilotage/InterrupteurController.php`, nouveaux `app/Services/Pilotage/{ReglageCatalogue,ReglageType}.php`, `app/Http/Requests/Pilotage/ReglageRequest.php`, `resources/js/components/admin/settings/Business/**` (page « Réglages métier », nom à confirmer), `resources/js/components/admin/observability/SystemHealthComponent.vue` (section interrupteurs) |
| S2 Les 22 pages cachées | **`resources/js/config/v1-hidden-modules.js`**, **`resources/js/components/admin/settings/MenuComponent.vue`**, **`resources/js/components/layouts/backend/BackendMenuComponent.vue`** (visibilité, `VIRTUAL_CHILDREN_BY_URL`, `HIDDEN_KEY_TO_MENU_URL`), `settings/{Mail,Otp,Notification,NotificationAlert,SocialMedia,Cookies,analytics,SmsGateway,PaymentGateway,License}/**` et leurs contrôleurs/requêtes |
| S3 Réglages métier prioritaires | `settings/{OrderSetup,KioskSetup}/**`, `OrderSetupController`, `OrderSetupRequest.php`, `KioskSetupRequest.php`, clés de `config/{pos,kiosk,dashboard,features,printing}.php` **exposées** (pas leur logique consommatrice) |
| S4 Propagation, caches, journal | événement `SettingsUpdated`, caches settings/Spatie, `app/Services/Pilotage/*` (application au démarrage `:153-165`), branchement au journal de ONB-13 |

| HORS | Porté par |
|---|---|
| Identité (Entreprise/Site/Filiale/Thème/Langues/Pages/Créneaux : contenu des pages) | ONB-01 (ce GOAL ne fait que les **montrer** dans le menu) |
| Catégories/Taxes/Attributs (contenu) | ONB-02 (idem : visibilité seulement) |
| Rôle & Autorisations (contenu, libellés) | ONB-06 (visibilité ici) |
| Consommateurs des réglages (caisse : `CashDrawerService`, borne, KDS, impression) | voies CAISSE/BORNE/KDS — ce GOAL expose la valeur, prouve l'effet par test, ne modifie pas la logique métier |
| `config/idempotency.php`, `IdempotencyKeyMiddleware`, fiscal | jamais (`InterrupteurService.php:27-33` l'exclut déjà) |
| Journal « qui a changé quoi » (table, service) | ONB-13 (ce GOAL émet, ne stocke pas) |

Zones à coordonner : `routes/api.php` (routes réglages typés), `settingRoutes.js` (page Réglages métier), `fr.json` (bloc `menu.*` / `label.setting_*`), `DatabaseSeeder.php` (valeurs par défaut).

## §0.3 — Drapeaux d'expansion
SCOPE-1 fichier gelé · SCOPE-2 3 boucles · SCOPE-3 migration non prévue (une table `reglages` est PRÉVUE, gate G-DATA) · SCOPE-4 NF525 hors ajout · SCOPE-5 : si un réglage exige de changer la logique d'un consommateur (ex. `CashDrawerService`), STOP → fiche de renvoi à la voie CAISSE.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 + **convergence = deux cycles consécutifs P0+P1 = 0 aux constats identiques**.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Réglages typés opérationnels | catalogue déclaratif ≥ 12 réglages non booléens ; chaque type refuse une valeur hors borne (422 FR) | **VRAI** |
| C2 | Effet immédiat prouvé | pour 5 réglages témoins : PUT → lecture par le consommateur (config/API) en < 1 s, sans redémarrage ni cache stale | **5/5** |
| C3 | Les 22 pages tranchées | tableau G-CACHE rempli ligne par ligne ; menu conforme ; `v1-hidden-modules.js` = décision, pas héritage | **22/22** |
| C4 | Zéro incohérence de visibilité | « Attributs » caché ET réinjecté ; entrées mortes ; entrée sans permission Spatie | **0** |
| C5 | « 45 réglages exigent un développeur » → mesuré à nouveau | liste des réglages métier courants encore hors Dashboard | **≤ 10**, chacun avec motif |
| C6 | Journal | chaque changement de réglage émet un événement consommé par le journal ONB-13 (ou en attente documentée) | **100 %** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · `tests/Feature/Settings/` 7 (dont `OrphanSettingsRatchetSentinelTest`, `SettingsUpdatedBroadcastTest`) · interrupteurs = **6** (`split_payment`, `wheel`, `remise_manuelle`, `fidelite`, `kiosk_promo`, `impression_ticket_client_auto`), tous à leur défaut fichier (mesuré Z2/Z7) ·
menu Réglages = 11 visibles / 31 · `settings` : groupe `pilotage` = 2 lignes (`wheel.enabled` 08-13, `printing.auto_print_client_receipt` 08-26 restauré false).

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : paramétrer ≠ multi-tenant ; G0.
- **C-BOOL** — `InterrupteurService.php:56-65` : « catalogue booléen, réglages typés hors périmètre tant qu'un mécanisme n'existe pas » — c'est une **décision d'attente**, pas d'architecture : ce GOAL construit le mécanisme.
- **C-HIDE** — `v1-hidden-modules.js:6-9` : « routes enregistrées, modules cachés » = une liste d'attente jamais tranchée depuis mai ; `settings.item-attributes` caché ET réinjecté (`BackendMenuComponent.vue:97`) ; « État du système » cru orphelin par la carte Z0 mais présent en base (`menus` id 33) — tranché : **la visibilité devient une décision propriétaire enregistrée (G-CACHE), pas un fichier de circonstance**.
- **C-DOC** — `SYSTEM_MAP.md:95` « Settings cluster (~26 controllers) » vs réalité (32 dossiers Vue, contrôleurs `Admin/*` sans sous-dossier) — mise à jour de `SYSTEM_MAP.md` (sous-voies) en W6, coordonnée avec ONB-14.

## §0.8 — Le commerçant-type et ses questions
Nadia, seule, 21 h 30, tiroir avec 4,20 € d'écart bloqué par une tolérance à 2 €. 1. « Où je règle l'écart de caisse toléré ? » 2. « Comment j'autorise la remise manuelle à mon gérant seulement ? »
3. « Pourquoi il y a 22 pages que je ne vois pas mais qui existent ? » 4. « Si je change un réglage, c'est tout de suite ou après redémarrage ? » 5. « Qui a changé la TVA hier ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Interrupteurs | **BOOLÉEN, 6 entrées** | `app/Services/Pilotage/InterrupteurService.php` (165 lignes : `CATALOGUE :43-90`, exclusion idempotency `:27-33`, `regler() :114-126`, `appliquerAuDemarrage() :153-165`) · `Admin/Pilotage/InterrupteurController.php:38,49-55` (Admin/Tenant Admin, `Log::info`) · routes `routes/api.php:1669-1670` · UI `admin/observability/SystemHealthComponent.vue` · route `observabilityRoutes.js:26-35` | (À CRÉER) |
| S2 Pages cachées | **22/31 cachées, liste codée** | `resources/js/config/v1-hidden-modules.js:11-56,66` · `settings/MenuComponent.vue:9-139,146-201` · `BackendMenuComponent.vue:58,68-78,94-99,239-269,388,424-427` · seeder `MenuTableSeeder.php` · table `menus` | `tests/js/sentinels/*` (à identifier en W1) |
| S3 Réglages métier | **DISPERSÉS** | `config/pos.php:113-119,150-154,196-200,233-237,271-275,301-305,319` · `config/kiosk.php:16-19,31,54,70,102-106,120,127,134,343,347,348` · `config/dashboard.php:24,29` · `config/features.php:27,50` · `OrderSetupRequest.php:26-49` (`:32-45` frais de livraison hérités) · `KioskSetupRequest.php:16-24` · tolérance d'écart de caisse (voie CAISSE : `app/Services/Cash/CashDrawerService.php`, permission `cash.reconcile.variance.override` `PermissionTableSeeder.php:705`) | `tests/Feature/OrderSetupRequestNegativeValuesTest.php` (existant, cité 13/08 — vérifier `ls`) |
| S4 Propagation | **IMMÉDIATE (mesuré)** | `SettingsUpdated` (`CompanyController.php:36`), `GET /api/frontend/setting` sans cache (Z2), `Config::set` (`InterrupteurService::regler`) | `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` |

**Sortie d'ancrage brute** : `wc -l InterrupteurService.php` → 165 · `grep -c "cle" InterrupteurService.php` → 6 clés · `GET /api/admin/observability/interrupteurs` (Z2 `02-api.json`) → 6 entrées `actif = défaut` · `settings` colonnes `id, group, key, payload, settingable_type, settingable_id, created_at, updated_at` ·
`v1-hidden-modules.js` → 34 clés (9 modules + 25 `settings.*`) · `MenuComponent.vue` → 31 entrées, `isSettingHidden()` `:199-201` · `SELECT id,name,url FROM menus WHERE url LIKE '%observability%'` → `33, System Health, observability/system`.

# §2 — ÉTAT MESURÉ LE 2026-08-26 (`recon/Z2_profil_reglages.md`, `recon/Z7_equipement_ops.md`)
**Marche** : les 6 interrupteurs se lisent (description FR, état = défaut) ; PUT `impression_ticket_client_auto` true → relu → restauré false (Z7) ; `pos@` → 403 sur PUT ; propagation immédiate d'`order-setup` et `kiosk-setup` ; 25 pages cachées s'ouvrent par URL (200, 0 libellé brut).
**Constats** : [P1] page Licence = clé d'API en clair (Z2) · [P2] lecture des réglages ouverte au caissier (`company/site/order-setup/branch/otp/theme/interrupteurs` 200) · [P2] `settings.item-attributes` caché ET réinjecté · [P2] alertes permanentes non actionnables sur l'écran État du système (1 490 messages = file gelée volontairement, sauvegarde 21 j, planificateur muet) · [P2] PIN borne « 1234 » affiché par défaut · [P3] Site : 16 champs obligatoires à vocabulaire SaaS.
**Angles morts** : tolérance de caisse, barème, seuil stock, mention ticket, heures de service, numéro de départ de file, plafonds borne (`max_item_qty` 20), fenêtre SLA, `pos.walkin_route_to_counter` — **aucun écran**. Les 22 pages cachées : Mail, OTP, Notification, Alerte notification, Réseaux sociaux, Cookies, Analytique, Thème, Créneaux, Bannières, Catégories, Attributs, Taxes, Pages, Rôle & Autorisations, Langues, Passerelle SMS, Passerelle de paiement, Licence (+ Clients, Coupons, Offres, Livreurs, Serveurs, Tables, Commandes en ligne/table, Rapport crédit côté modules).

# §3 — SOUS-SYSTÈME 1 : MÉCANISME DE RÉGLAGES TYPÉS

### Contrat
Un réglage = une **déclaration** (clé config, type, bornes, libellé FR, aide, groupe, permission, valeur par défaut fichier, effet immédiat) ; jamais un `if` de plus dans un contrôleur.
Décisions fermes : types `bool | int | decimal | string | time_range | select` ; validation par type + bornes déclarées ; stockage `settings` groupe `pilotage` (mécanisme existant) — une table dédiée n'est créée que si la volumétrie l'exige (G-DATA) ; `idempotency.*`, `pos.simulation_hardware`, tout `fiscal.*` **inéligibles** (liste noire codée + test).

## Sub 1.1 — Catalogue déclaratif
**Ancrages** : `InterrupteurService.php:43-90` (forme actuelle : `nom → [cle, libelle, description, consequence, defaut]`), `:114-126`, `:153-165`.
**Tâches**
- **T-1.1.1** — Test de caractérisation ROUGE : les 6 interrupteurs existants continuent de fonctionner à l'identique (lecture, PUT, restauration, 404 hors liste, 403 `pos@`).
  • test : (À CRÉER à `tests/Feature/Pilotage/InterrupteursExistantsCaracterisationTest.php`)
- **T-1.1.2** — Étendre le catalogue : `type`, `min`, `max`, `step`, `options`, `groupe`, `permission`, `unite` ; `ReglageType` valide et normalise (virgule → point, heures `HH:MM-HH:MM`) ; liste noire testée.
  • ancrage : `InterrupteurService.php` (refactor interne, API publique conservée) · test : (À CRÉER à `tests/Feature/Pilotage/ReglagesTypesValidationTest.php`)
  • au-delà : valeur hors borne → 422 FR ; type incohérent en base (payload corrompu) → repli défaut fichier + avertissement ; deux onglets → dernier gagne, journalisé.
- **T-1.1.3** — API `GET/PUT /api/admin/pilotage/reglages[/{nom}]` (`permission:settings`, écriture Admin/Gérant selon la déclaration) ; l'ancienne route `observability/interrupteurs` reste (compatibilité).
  • ancrage : `routes/api.php` (append-coordination) · test : (À CRÉER à `tests/Feature/Pilotage/ReglagesApiAuthzTest.php`) · `pos@` → 403 ; lecture réservée à `settings` (correction du P2 « lecture ouverte au caissier » **pour cette API**).
**Acceptation** : 3 tests VERTS · C1 partiel (mécanisme) · aucun changement de comportement des 6 existants.

## Sub 1.2 — Page « Réglages métier »
**Tâches**
- **T-1.2.1** — Page sous Réglages (groupe Configuration) : sections par groupe (Caisse, Borne, Cuisine, Stock, Livraison, Tableau de bord), un contrôle par type, aide FR, valeur par défaut visible, bouton « rétablir ».
  • ancrage : nouveau `settings/Business/**` (nom à confirmer G-NOM) + `settingRoutes.js` + `MenuComponent.vue` · test : (À CRÉER à `tests/js/reglagesMetierPage.spec.js`) · visuel : `http://127.0.0.1:8805/admin/settings/reglages-metier` à 1366/1024/768
  • au-delà : annulation → valeurs inchangées ; rechargement pendant l'enregistrement ; double clic ; clavier.
- **T-1.2.2** — La section interrupteurs de `SystemHealthComponent.vue` renvoie vers la nouvelle page (ou l'intègre) ; « État du système » reste une page de santé, pas de réglages.
**Acceptation** : test VERT · captures lues · question 1 et 4 de Nadia = OUI.

# §4 — SOUS-SYSTÈME 2 : LES 22 PAGES CACHÉES — TRANCHER, PAS HÉRITER

## Sub 2.1 — Tableau de décision (G-CACHE)
**Ancrages** : `v1-hidden-modules.js:11-56`, `MenuComponent.vue:9-139`, `Z2 §3-4` (verdicts mesurés), `Z0_carte_dashboard.md §2, §4`.
**Tâches**
- **T-2.1.1** — Pour chacune des 22 sous-pages + 9 modules : état mesuré (s'ouvre, fonctionnelle, pertinente V1 FR locale), proposition **garder visible / cacher / retirer (route + composant archivés)**, motif, GOAL propriétaire du contenu.
  Proposition du chef de projet (à confirmer G-CACHE) : **visibles** — Taxes, Catégories, Attributs (→ hub Studio ONB-02), Rôle & Autorisations (ONB-06), Thème, Horaires/Créneaux, Pages (ONB-01), Coupons, Offres (ONB-09) ; **cachés conservés** — Langues (outil d'intégrateur), Notification/Alerte (jusqu'à décision push ONB-09), Clients/Livreurs/Serveurs/Tables/Commandes en ligne-table (modules V2) ; **retirés** — Licence (clé d'API en clair), Passerelle de paiement, Passerelle SMS, OTP, Cookies, Analytique, Réseaux sociaux, Bannières, Rapport solde crédit (SaaS-era, sans objet V1 locale).
  • livrable : tableau dans MISSION §6 · test : (À CRÉER à `tests/js/sentinels/menuVisibilityDecisionSentinel.spec.js` — chaque clé cachée porte un motif et une date)
- **T-2.1.2** — Exécuter les décisions : `v1-hidden-modules.js` devient une liste **documentée** (clé, motif, décision, date) ; `MenuComponent.vue` et `BackendMenuComponent.vue` alignés ; incohérence Attributs corrigée ; pages « retirées » : route supprimée + composant déplacé sous `resources/js/components/admin/_archive/` (jamais supprimé).
  • test : (À CRÉER à `tests/js/settingsMenuMatchesDecisions.spec.js`) · visuel : sous-menu Réglages avant/après, 3 gabarits
  • au-delà : URL directe d'une page retirée → 404 propre FR ; permission absente → entrée invisible (repli fail-closed, coordonné ONB-06 pour `router/index.js:106-110`).
- **T-2.1.3** — Demandes reçues des autres GOAL (fiches de renvoi MISSION §8 de 01/02/06/09/10) : les exécuter ici, une par une, avec preuve visuelle.
**Acceptation** : C3 = 22/22 · C4 = 0 · 2 tests VERTS · captures lues.

# §5 — SOUS-SYSTÈME 3 : RÉGLAGES MÉTIER PRIORITAIRES

## Sub 3.1 — Inventaire et migration vers le catalogue
**Ancrages** : `config/pos.php` (`manual_discount_enabled :196-200`, `loyalty_enabled :233-237`, `coupon_codes_enabled :271-275`, `auto_prepare_on_paid :150-154`, `walkin_route_to_counter :301-305`, `cash_session_stale_hours :319`, `featured_category_slugs :113-119`) ·
`config/kiosk.php` (`payment_route_all_to_counter :54`, `promo_enabled :70`, `loyalty_redeem_enabled :102-106`, `queue_start_number :134`, `stale_collect_ttl_minutes :120`, `stale_phone_collect_ttl_minutes :127`, `max_item_qty :343`, `order_rate_limit :347`, `quote_rate_limit :348`) ·
`config/dashboard.php` (`sla_alerts_window_hours :24`, `sla_alerts_threshold_minutes :29`) · `config/features.php` (`offers_enabled :27`, `staff_only_mode :50`) · `OrderSetupRequest.php:26-49` · tolérance d'écart de caisse (voie CAISSE) · seuil stock bas (`stock_levels.threshold_low`, voie ONB-08) · mention légale ticket (`branches.legal_footer`, ONB-01).
**Tâches**
- **T-3.1.1** — Inventaire écrit des réglages métier : où il vit (config / .env / settings / colonne), type, bornes, consommateur (fichier:ligne), effet, GOAL propriétaire du consommateur — reprend et re-mesure la liste « 45 » du 15/08.
  • livrable : MISSION §8 · test : (À CRÉER à `tests/Feature/Pilotage/ReglagesMetierInventaireSentinelTest.php` — cliquet : tout `env()`/`config()` métier nouveau doit être déclaré)
- **T-3.1.2** — Migrer dans le catalogue typé (par ordre d'impact commerçant) : tolérance d'écart de caisse (decimal, 0-50 €), remise manuelle (bool, existant), codes promo caisse/borne (bool), numéro de départ de file borne (int), plafond quantité borne (int 1-99), temps de préparation (int, déjà en settings), fenêtre/seuil SLA (int), `auto_prepare_on_paid` (bool), `walkin_route_to_counter` (bool, **gate propriétaire déjà ouverte** : ne pas changer la valeur), heures de service (time_range, consommé par ONB-01 horaires si créés).
  • test : (À CRÉER à `tests/Feature/Pilotage/ReglagesMetierEffetTest.php`) — pour 5 témoins : PUT → `config()` relu → comportement consommateur observé (ex. remise manuelle : bouton POS `pos.manual_discount_enabled`)
  • ⚠️ la **logique** du consommateur n'est pas modifiée ici : si la tolérance de caisse est codée en dur dans `CashDrawerService` (à établir W1), ce GOAL expose la valeur et **renvoie** à la voie CAISSE la lecture de `config('pos.cash_variance_tolerance')`.
- **T-3.1.3** — Retirer de « Configuration des commandes » les 3 champs de frais hérités (`OrderSetupRequest.php:32-45`) — demande de ONB-01 ; la source est la filiale.
**Acceptation** : C1 (≥ 12 non booléens) · C2 5/5 · C5 ≤ 10 · 2 tests VERTS.

# §6 — SOUS-SYSTÈME 4 : PROPAGATION, CACHES, JOURNAL

**Tâches**
- **T-4.1.1** — Cartographier les caches : settings (Smartisan), `Config::set` par requête, cache Spatie (`forgetCachedPermissions`), cache menu borne, `window.foodkingConfig` (bundle → nécessite rechargement de page) ; prouver le délai réel de chaque réglage témoin (borne, caisse ouverte, KDS).
  • test : (À CRÉER à `tests/Feature/Pilotage/ReglagesPropagationTest.php`) + `tests/Feature/Settings/SettingsUpdatedBroadcastTest.php` (existant)
- **T-4.1.2** — Émettre un événement `ReglageModifie(nom, avant, après, user_id, at)` à chaque écriture ; consommé par le journal de ONB-13 (si ONB-13 pas encore livré : `Log::info` structuré, comme aujourd'hui `InterrupteurController.php:49-55`).
  • test : (À CRÉER à `tests/Feature/Pilotage/ReglageModifieEventTest.php`) · C6
- **T-4.1.3** — Un réglage modifié pendant une commande en cours (borne au récapitulatif, caisse au paiement) : le devis signé en cours **n'est pas** affecté (NF525 : `composition_snapshot`, devis lié) — preuve.
  • test : (À CRÉER à `tests/Feature/Pilotage/ReglageNImpactePasDevisEnCoursTest.php`)
**Acceptation** : 3 tests VERTS · question 4 et 5 de Nadia = OUI (ou « en attente ONB-13 » écrit).

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur | données vides | volume | réseau coupé | effet borne/caisse/KDS | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Réglage typé | `reglagesMetierPage.spec.js` | idem | PUT idempotent (`ReglagesApiAuthzTest`) | dernier gagne + journal | `pos@` 403 lecture ET écriture | vide → défaut fichier | 40 réglages | — | `ReglagesMetierEffetTest`, `ReglagesPropagationTest` | « rétablir » | hors borne, virgule, `1e9`, texte dans nombre, `23:59-00:01` |
| Visibilité menu | N/A | menu recalculé au rechargement | N/A | N/A | entrée sans permission → invisible | — | 31 entrées | — | — | ré-afficher une page | permission inconnue (`router/index.js:106-110`) |
| Page retirée | N/A | 404 FR | N/A | N/A | 403/404 | — | — | — | — | restauration depuis `_archive/` | URL avec `..` |
| Interrupteur existant | `InterrupteursExistantsCaracterisationTest` | idem | idem | idem | 403 mesuré | — | 6 | — | `impression_ticket_client_auto` → impression (voie CAISSE, lecture) | restauré (mesuré) | nom hors liste → 404 (mesuré) |
| Devis en cours | `ReglageNImpactePasDevisEnCoursTest` | — | — | — | — | — | — | worker coupé : réglage écrit quand même (synchrone) | snapshot intact | — |

# §A — ARMÉE D'AGENTS
Architecte (catalogue déclaratif vs `if`, frontière exposition/consommation) · Sécurité (liste noire, lecture par `pos@`, injection dans `string`, page Licence) · UX/A11y (page par groupes, contrôles par type, 3 gabarits) ·
**Psychologie commerçant** (un réglage = une phrase compréhensible + sa conséquence, comme les 6 interrupteurs actuels ; peur de « casser la caisse » → bouton rétablir + aperçu) · DBA (payload `settings`, index, volumétrie) · SRE (caches, propagation, bundle) ·
Implémenteur unique · ROUGE (rejoue `GET/PUT interrupteurs` + navigation des 31 entrées après chaque vague) · QA visuel + ROUGE visuel · **Jalonneur**.
Discipline : 5 lecture seule en un message ; ROUGE avant « fini » ; disque `reports/test-e2e/ONB05_REGLAGES_SANS_DEVELOPPEUR/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases, gates | séquentiel | — |
| **W1** | Reconnaissance : lecture intégrale `InterrupteurService.php`, inventaire T-3.1.1, tableau T-2.1.1, cartographie caches T-4.1.1 | fan-out lecture seule | — |
| **W2** | S1 mécanisme typé + API (T-1.1.*) | séquentiel | — |
| **W3** | S3 migration des réglages prioritaires (T-3.1.2, T-3.1.3) + page (T-1.2.*) | séquentiel | G-NOM ; G-CAISSE-TOL pour la tolérance |
| **W4** | S2 exécution des décisions de visibilité (T-2.1.2, T-2.1.3) | séquentiel — **seul** sur les 3 fichiers de menu | **G-CACHE** |
| **W5** | S4 propagation, événement, devis en cours (T-4.1.*) | séquentiel | — |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Pilotage|Settings|OrderSetup|KioskSetup"`, Vitest, Playwright `tests/e2e/onb05-*.spec.js` (À CRÉER), `SYSTEM_MAP.md` sous-voies, BRAIN | séquentiel | — |
**§X.8** 6 points (Jalonneur) · **§X.9** STOP, `STUCK_*.md`, 4 options · **§X.10** `wip(<vague>)`, `INTERRUPT_*.md`, BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-CACHE** | Tableau des 22 pages + 9 modules : garder / cacher / retirer, ligne par ligne | Propriétaire | tableau signé | `MISSION_ONB05` §6 + `docs/gates/GATE_LOG.md` | EN ATTENTE — **bloque W4** (et les demandes de 01/02/06/09/10) |
| **G-NOM** | Nom et emplacement de la page (« Réglages métier » sous Configuration) | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-1.2.1 |
| **G-CAISSE-TOL** | Exposer la tolérance d'écart de caisse (2 € codés) comme réglage : la logique est en voie CAISSE | Propriétaire + session CAISSE | accord de coordination | MISSION §8 | EN ATTENTE — bloque ce témoin seulement |
| **G-DATA** | Table `reglages` dédiée si `settings` insuffisant | Propriétaire | accord | `GATE_LOG.md` | EN ATTENTE — non attendu |
| **G-LIC** | Retrait de la page Licence (clé d'API en clair) | Propriétaire | accord | MISSION §6 | EN ATTENTE — inclus dans G-CACHE |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `CONSTITUTION.md` · `SYSTEM_MAP.md §5-6` · `PARALLEL_PROTOCOL.md` · `CLAUDE.md §8-9` · mémoire `pilotage_sans_developpeur_2026-08-09` ·
`plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-05) · `recon/Z2_profil_reglages.md` · `recon/Z7_equipement_ops.md` · `recon/Z0_modele_catalogue_wizard_reglages.md §C-D` · `recon/Z0_carte_dashboard.md §2, §4, §6` ·
`PROJECT_BRAIN.md §4` (« 45 réglages exigent un développeur ») · `plans/GOAL_CONFORT_MAX_ET_BASE_PROUVEE_2026-08-15.md` (V5 : 2 → 6 interrupteurs) · `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (NAV1-04, SET-T06).

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C6 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 11 tests créés, Vitest ≥ 3 644 ; 4. diff gelé 0 ; 5. NF525 ajout seul, `idempotency.enabled` jamais exposé (test) ;
6. gates tranchés ou différés ; 7. `v1-hidden-modules.js` documenté, `SYSTEM_MAP.md` à jour, BRAIN vrai ; 8. deux cycles identiques ; 9. toutes les fiches de renvoi reçues traitées ou refusées par écrit.
**Interdit** : exposer un réglage fiscal ou d'idempotence · modifier la logique d'un consommateur d'une autre voie · éditer les 3 fichiers de menu depuis une autre session · supprimer un composant (archiver) · approuver un gate.
> Le sens : Nadia règle sa tolérance de caisse à 21 h 31, voit la phrase qui lui dit ce que ça change, et le tiroir se ferme.

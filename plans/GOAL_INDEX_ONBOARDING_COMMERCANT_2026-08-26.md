# GOAL INDEX — ONBOARDING COMMERÇANT : « de zéro au contrôle total, sans développeur »
## FoodKing — programme de 14 GOAL lançables en sessions séparées (2026-08-26)

- **Auteur** : Claude Code, chef de projet (session de cadrage du 2026-08-26) · **HEAD** : `43b120c7d` ·
  **Branche de base** : `pos/category-first-caisse-2026-06-23` · **Arbre principal servi** : `http://127.0.0.1:8766`
- **Dossier de mission** : `reports/audit/onboarding-commercant-2026-08-26/` (brief, gabarits, reconnaissance `recon/Z1..Z8`,
  rapports de mission `MISSION_ONB*.md`)
- **Mode d'emploi** : ce document est lu EN PREMIER par toute session du programme. Puis le rapport de mission du GOAL,
  puis le GOAL. Une session = UN GOAL. Plusieurs sessions en parallèle = respecter §2 (voies, ports, registres).

---

# §0 — MANDAT DU PROPRIÉTAIRE (2026-08-26) ET CE QU'IL CHANGE

## §0.1 La demande, reformulée sans la trahir
« Agir comme un développeur senior qui gère une entreprise à un million : chaque action apprise avec la bonne option,
jalonnement, vérification par des experts, disputes, bons choix. Regarder tout ce qui a été fait. Objectif principal :
**optimiser le système pour l'expérience utilisateur afin d'acquérir de NOUVELLES ENTREPRISES** qui l'utiliseront,
**personnalisé à elles** : elles partent de zéro jusqu'au **contrôle total de chaque détail**, puis on peut faire une
**publication vierge** et tester. Tester réellement sur le web, analyser, auditer, vérifier, disputer avec des agents
parallèles (raisonnement, interface, technique, **logique**). Cibler chaque fonctionnalité du Dashboard, toutes les
problématiques directes ou indirectes. Un GOAL par problème, avec documentation, disciplines, règles, boucle jusqu'à
validation complète, scénarios au-delà du premier degré (annulation, effets indirects), **à la place du commerçant**.
Au minimum 10 GOAL, ultra-riches, chacun lancé dans sa propre session avec deux fichiers : le GOAL et un rapport
complet de mission. Jusqu'à : profil d'entreprise vierge, **extraction du menu par IA** (chat qui liste, extrait, crée
les wizards), ajout manuel d'options et de pages de wizard, **personnalisation par catégories** (sauce, viande, pain,
boissons, formule…) avec règles **choix unique / choix gratuit / supplément payant**, gestion de tout, **chatbot de
missions locales** sur le profil. Pas besoin de créer l'accès d'un nouveau commerçant : vérifier et garantir que
**toute la structure fonctionne pour gérer un nouvel établissement**. »

## §0.2 Ce que ça change — contradiction constitutionnelle tranchée par un gate, pas par un agent
`CONSTITUTION.md §1` : « V1 = LOGICIEL PERSONNEL du restaurant Le Cayenne. PAS un SaaS. » La demande du 2026-08-26
vise un logiciel **livrable à un nouvel établissement**. Ce n'est pas une dérive d'agent, c'est un amendement du
propriétaire. Résolution appliquée à tout le programme (reprise du GOAL du 2026-08-12, §0.2 C2) :

| Distinction | Dans le programme | Hors programme |
|---|---|---|
| **Paramétrer** : sortir la marque, le menu, les rôles, les réglages du CODE vers la DONNÉE, éditables depuis le Dashboard | ✅ OUI — c'est tout l'objet des 14 GOAL | |
| **Multi-tenant** : plusieurs établissements vivants dans une même base | | ⛔ NON — reste V2/cloud (`BranchScope`, `User` non scopé) |
| **Cloud / SaaS / scale** | | ⛔ NON — `CONSTITUTION §3.3` inchangé |

**Gate G0 (propriétaire)** : réécrire la phrase de `CONSTITUTION.md §1` en « V1 = logiciel d'un établissement,
installé chez lui, **entièrement paramétrable depuis son Dashboard** ; Le Cayenne en est la première installation ».
Tant que G0 n'est pas contresigné, aucun GOAL ne remonte « multi-marque » comme P0/P1 bloquant, et aucun ne touche
`CONSTITUTION.md`. Tous continuent leur travail de paramétrage (qui est utile à Le Cayenne aussi).

## §0.3 Ce que ce programme hérite (ne pas refaire — preuves dans les rapports de mission)
- 2026-06-03 : colonne vertébrale admin convergée (25/25 boutons, KPI, historique, caisse).
- 2026-08-13 : **70/71 pages admin prouvées ouvrables** ; deux P0 corrigés ; la LARGEUR (26 sous-pages Réglages dont
  12 sans test, Utilisateurs/RBAC, Notifications, Rapports) **différée et jamais exécutée**.
- 2026-08-12 : chantier « swap multi-marque piloté par IA » **planifié, jamais exécuté** (129 fichiers « cayenne » alors,
  **147 + 11 aujourd'hui**).
- 2026-08-15 : 45 réglages métier exigent un développeur (`InterrupteurService` : 2 → 6 booléens).
- 2026-08-25 : sondes de santé honnêtes, file `notifications` orpheline (1 490 jobs), garde d'identité Playwright
  (`:8000` ≠ `:8766`), instrument-avant-produit (CLAUDE.md §3ter).

---

# §1 — LES 14 GOAL (un problème réel chacun, voies disjointes)

| # | GOAL (fichier `plans/GOAL_ONB<nn>_…_2026-08-26.md`) | Le problème réel (mesuré) | Voie / fichiers possédés | Port | Dépend de |
|---|---|---|---|---|---|
| **01** | `IDENTITE_ETABLISSEMENT` — nom, logo, SIRET/TVA, adresse, horaires, mentions légales, devise, langue, thème | Deux sources (Entreprise vs Filiale), SIRET/TVA sur la filiale seulement, **aucun éditeur d'horaires**, thème = 3 logos sans couleur, pages Thème/Langues/Pages cachées | CENTRAL · `settings/{Company,Site,Branch,Theme,Currency,Language,Page,TimeSlot,Slider}/**` + contrôleurs/requêtes homonymes | 8801 | — |
| **02** | `CATALOGUE_DE_ZERO` — catégories, articles, images, taxes, attributs, import/export, canaux, station KDS, allergènes | Concepts éclatés (Studio / Articles / Réglages-Catégories / Attributs / Ingrédients), Catégories-Taxes-Attributs cachés, wizard guidé de création = squelette, import Excel non prouvé aux bords | CENTRAL · `admin/items/**` (sauf `composer/**`), `settings/{ItemCategory,ItemAttribute,Tax}/**`, `Item*Controller`, `ItemCategoryController`, `TaxController`, `Item*Request`, `app/Imports`, `app/Exports` | 8802 | — |
| **03** | `WIZARD_REGLES_DE_PRIX` — personnalisation par catégories (sauce, viande, pain, boisson, formule…) avec **choix unique / inclus N gratuit / supplément payant**, par article ET par catégorie | Les étapes du wizard n'ont **aucune sémantique de prix** (`price` interdit) ; inclusions en dur dans `config/menu.php`/`config/kiosk.php` ; édition par article derrière `FEATURE_WIZARD_PER_ITEM_DEMO=false` | CENTRAL · `admin/items/composer/**`, `Composer*Controller`, `Composer*Request`, `app/Services/Composer/**`, migrations `item_wizard_*` ; **PricingService = zone gelée → LOCK + gate** | 8803 | 02 (catalogue stable) |
| **04** | `EXTRACTION_MENU_IA_ET_ASSISTANT` — photo/PDF/texte du menu → proposition structurée → validation humaine → création via les API existantes ; assistant de missions locales (« ajoute une sauce à tous les tacos ») | **Aucune extraction de menu ni chatbot** ; seule l'IA vision OpenAI existe (factures, tickets Uber), désactivée par défaut | CENTRAL · nouveaux `app/Services/MenuExtraction/**`, `app/Http/Controllers/Admin/Assistant/**`, `admin/assistant/**` ; consomme les API de 02/03 sans les modifier | 8804 | 02, 03 (API) |
| **05** | `REGLAGES_SANS_DEVELOPPEUR` — interrupteurs typés (nombre/texte/horaire), les 22 pages cachées (garder / cacher / retirer), tolérance caisse, barème livraison, seuils, mention ticket, heures de service ; **propriétaire des fichiers de visibilité du menu** | 6 booléens pilotables seulement ; 22/31 sous-pages Réglages cachées ; « 45 réglages exigent un développeur » | CENTRAL · `app/Services/Pilotage/**`, `Pilotage/InterrupteurController`, `settings/{OrderSetup,KioskSetup,Mail,Otp,Notification,NotificationAlert,SocialMedia,Cookies,analytics,SmsGateway,PaymentGateway,License}/**`, **`v1-hidden-modules.js`, `settings/MenuComponent.vue`, `BackendMenuComponent.vue` (visibilité)**, `config/{pos,kiosk,dashboard,features}.php` (clés de réglage) | 8805 | — |
| **06** | `EQUIPE_ROLES_ACCES` — créer son personnel et ses rôles métier, matrice lisible, enforcement API réel, repli permissif, mots de passe, appareils, PIN | Page Rôles cachée ; repli **permissif** (permission inconnue ⇒ affichée) ; libellés techniques (« Stuff », `pos-discount-over-10-requires-manager`) ; enforcement direct jamais prouvé pour tous les endpoints | CENTRAL · `admin/{administrators,employees,chefs,waiters,customers,deliveryBoys,profile}/**`, `settings/Role/**`, contrôleurs/requêtes homonymes, `RoleController`, `PermissionController`, `DeviceSessionController`, `permission-match.js`, seeders permissions/rôles, `docs/AUTHZ_MATRIX.md` | 8806 | — |
| **07** | `TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS` — widgets, accès rapide, ventes/articles/transactions, exports = écran, historique, rapport X, périodes/fuseau | Écran vs export jamais re-vérifié (REP-03/04 de juin), 2 widgets orphelins, rapport X sans page, chiffres « depuis toujours » corrigés en août mais non re-prouvés | CENTRAL · `admin/{dashboard,salesReport,itemsReport,transactions,orderHistory,creditBalanceReport}/**`, `DashboardController/Service`, `SalesReport/ItemsReport/Transaction/OrderHistory/CreditBalanceReport` controllers, `Fiscal/XReportController` (lecture), `config/dashboard.php`, `app/Exports/**` (rapports) | 8807 | — |
| **08** | `STOCK_INGREDIENTS_DISPONIBILITE` — 4 écrans pour un concept, ajustement sans validation, scan facture, 86/rupture, seuils, station `none` | `RawMaterialAdjustController` et `PurchasingScanController` **sans FormRequest** ; 11 articles vendables invisibles en cuisine (`kds_station=none`) ; widget stock-bas | CENTRAL · `admin/{stock,ingredients,purchasing}/**`, `items/AvailabilityToggleComponent.vue`, `ingredients/**`, contrôleurs Stock/Unified/RawMaterial/Ingredient/Purchasing/Availability, `app/Services/{Stock,Ingredients,Purchasing,RawMaterials}/**` | 8808 | — |
| **09** | `ANIMATION_COMMERCIALE` — promos, coupons, offres, fidélité, ticket promo, push/messages/abonnés, roue transférable | Coupons/Offres cachés, `kiosk.promo_enabled=false`, `pos.coupon_codes_enabled=false`, push → file orpheline, roue liée au site Le Cayenne | CENTRAL · `admin/{pushNotification,messages,subscribers,coupons,offers,promo}/**`, `settings/LoyaltySetup/**`, contrôleurs Push/Message/Subscriber/Coupon/Offer/OfferItem/PromoFlyer/LoyaltySetup/`Wheel/**`, `config/wheel.php` | 8809 | 05 (dé-cachage) |
| **10** | `EQUIPEMENT_ET_OPERATIONS` — bornes, imprimantes (allowlist LAN), TPE simulé expliqué, KDS/OSS réglables, état système / outbox / interrupteurs reliés au menu, file `notifications`, worker | Écrans orphelins (État du système, Outbox, Interrupteurs), garde `SafeRemoteHost` vs pont local, 1 490 jobs orphelins, postes de cuisine réglables article par article seulement | CENTRAL · `settings/{KioskMachine,Printers,PaymentTerminals}/**`, `admin/observability/**`, `observabilityRoutes.js`, contrôleurs KioskMachine/Printer/PaymentTerminal/Health*, `app/Rules/SafeRemoteHost.php`, `config/printing.php`, `config/queue.php` ; KDS/OSS **en lecture** (voie KDS : coordonner) | 8810 | — |
| **11** | `EXPERIENCE_COMMERCANT_TRANSVERSE` — UX, a11y, i18n, psychologie : motifs de formulaire, vocabulaire, erreurs FR, états vides, tablette, clavier, aide contextuelle, ordre du menu | Motifs hétérogènes (drawer / page / modale / Blade), anglais résiduel, « Filiales » pour un mono-restaurant, aucune aide contextuelle, aucune checklist | TRANSVERSE · **audit lecture seule en parallèle** ; corrections : `layouts/backend/**`, `admin/components/**` (partagé §6 → sérialisé), `resources/css/app.css`, `fr.json` ; toute correction dans une voie d'un autre GOAL lui est **renvoyée** | 8811 | audit : — ; corrections : après 01-10 |
| **12** | `PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE` — installeur, seeders génériques (vs Le Cayenne), checklist « Premier démarrage » dans le Dashboard, dé-cayennisation (147 + 11 fichiers → donnée), commandes `menu:*` archivées | Une installation neuve reçoit **le menu et les rôles de Le Cayenne** ; aucun parcours guidé ; marque dans le code | TRANSVERSE · `database/seeders/**` (sauf permissions/rôles → 06), `app/Console/Commands/{Menu*,*Cayenne*}`, `Installer/**`, nouveaux `admin/onboarding/**`, `config/menu.php`, `config/menu_images.php` ; renvoie chaque libellé « cayenne » au GOAL propriétaire du fichier | 8812 | 01, 02, 05 (modèle de données des réglages) ; G0 |
| **13** | `SECURITE_INTEGRITE_BACKOFFICE` — 8 contrôleurs sans FormRequest, uploads, injection `.env`, secrets dans les index de réglages, IDOR, idempotence des mutations admin, journal des changements de réglages | Validation inline ou absente (ajustement stock, scan facture, Uber photo, ticket promo, roue, alertes), secrets potentiellement exposés, aucun journal « qui a changé quel réglage » | TRANSVERSE · **audit lecture seule en parallèle** ; corrections : `app/Http/Requests/**` (nouvelles requêtes), `app/Http/Middleware/**` hors gelés, `config/idempotency.php` (ajout seul), `app/Services/Audit/**` ; jamais les fichiers gelés | 8813 | audit : — ; corrections : sérialisées par voie |
| **14** | `CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT` — E2E de bout en bout : install vierge → identité → catalogue → wizard → équipe → équipement → commande borne → KDS → encaissement → Z → rapports ; deux cycles identiques | Rien ne prouve aujourd'hui le parcours complet d'un établissement **autre que Le Cayenne** | TRANSVERSE · `tests/e2e/onboarding-*.spec.js`, `tests/Feature/Onboarding/**`, rapports ; **aucun code produit** (renvoi aux GOAL) | 8814 | 01-13 |

Durées indicatives par session : 01/06/07/08/09/10 = 3-6 h · 02/05 = 6-10 h · 03/04/12 = 10-20 h (avec gates) · 11/13 = 4-8 h · 14 = 6-12 h.

---

# §2 — ORDRE, PARALLÉLISME, COLLISIONS

## §2.1 Vagues de lancement (sessions séparées)
| Vague | GOAL lançables EN MÊME TEMPS | Pourquoi c'est sûr |
|---|---|---|
| **A** | 01 · 02 · 05 · 06 · 07 · 08 · 09 · 10 (+ 11 et 13 en **audit lecture seule**) | sous-voies CENTRAL à répertoires disjoints (tableau §1), ports distincts, préfixes DB distincts |
| **B** | 03 · 04 (après 02 fermé ou stabilisé) · 11 et 13 en **corrections** (sérialisées par voie) | 03 touche `PricingService` (gelé) sous LOCK : **seul** sur la zone partagée pricing ; 04 consomme les API de 02/03 |
| **C** | 12 (après 01, 02, 05 et G0) | réécrit les seeders et la marque : doit connaître le modèle de réglages final |
| **D** | 14 (dernier) | boucle de convergence sur l'ensemble |

⚠️ **CENTRAL est une seule voie dans `PARALLEL_PROTOCOL.md`.** Ce programme la découpe en **sous-voies déclarées** (colonne
« fichiers possédés » §1). Deux sessions ne modifient JAMAIS le même fichier ; si un GOAL a besoin d'un fichier possédé
par un autre, il écrit la demande dans son rapport de mission §8 et l'autre GOAL l'exécute (ou on sérialise).

## §2.2 Registres et fichiers partagés (append-coordination — déclarer chaque ligne ajoutée)
`routes/api.php` · `resources/js/router/index.js` · `resources/js/store/index.js` · `webpack.mix.js` ·
`database/seeders/DatabaseSeeder.php` · `resources/js/languages/fr.json` (ajouter ses clés **dans le bloc de sa zone**, jamais
en fin de fichier) · `BackendMenuComponent.vue` / `settings/MenuComponent.vue` / `v1-hidden-modules.js` (**possédés par 05** :
tout dé-cachage passe par 05) · `admin/components/**` et `DefaultComponent.vue` (§6 partagés : sérialiser).
Zones gelées (CLAUDE.md §7) : jamais sans `lock-plan` + contreseing ; le seul GOAL qui en approche est **03** (PricingService).

## §2.3 Base de données partagée et serveur
- Toutes les sessions partagent `foodking_e2e`. Préfixe obligatoire `GOAL-ONB<nn>` sur toute entité créée ; nettoyage en fin
  de vague avec preuve DB. ⛔ `migrate:fresh` interdit ; `php artisan test` nu interdit (garde
  `~/.claude/skills/brain/scripts/safe-test.sh`).
- Chaque session sert SON worktree sur SON port (§1). `:8766` reste l'arbre principal (lecture de référence) ; `:8000` est un
  autre worktree périmé — ne jamais l'auditer.
- Le serveur de dev PHP sert une requête à la fois : pas plus de 4 navigateurs Playwright simultanés par machine, timeouts 60 s.

---

# §3 — PROTOCOLE DE SESSION (identique pour les 14 GOAL)

1. **Lecture (10 min)** : `CONSTITUTION.md` → `PROJECT_BRAIN.md §2` → `SYSTEM_MAP.md` → `PARALLEL_PROTOCOL.md` → cet index →
   `MISSION_ONB<nn>_*.md` → `GOAL_ONB<nn>_*.md`. Puis le brief `recon/_BRIEF_COMMUN.md` et le rapport `recon/Z*.md` de la zone.
2. **Pré-vol (W0)** : GOAL §0.1 (worktree depuis HEAD, `.env` + port, `.env.testing`, `vendor`/`node_modules` en liens durs,
   serveur, `PLAYWRIGHT_BASE_URL`), filet (`backup/pre-onb<nn>-…` + dump), bases §0.6 re-mesurées, gates §G statués.
3. **Exécution** : vagues du GOAL §X, chaque tâche par `ultra-audit-profond`, spécialistes lecture seule en un message,
   implémenteur unique, contestation ROUGE avant tout « fini », visuel lu et analysé, point de contrôle 6 points par vague
   (Jalonneur), max 3 boucles de soin puis escalade.
4. **Compte rendu au propriétaire** (format fixé) : **FIXÉ** (une ligne par correctif) · **VÉRIFIÉ** (comptes de tests + comment
   c'est prouvé) · **BLOQUÉ** (ce qu'il faut du propriétaire, une phrase). Pas de journal brut, pas de diff fichier par fichier.
5. **Fin de session** : commits par fichiers nommés (jamais `git add .`), jamais de push, `PROJECT_BRAIN.md §2/§3` mis à jour,
   rapport de mission §8 rempli (journal, fichiers touchés, état final), Graphiti si décision durable.

---

# §4 — PROMPTS DE LANCEMENT (un par GOAL — à coller dans une nouvelle session ouverte sur l'arbre principal)

> Remplacer `<nn>` et `<SLUG>` par la ligne du tableau §1. Le prompt complet, spécifique à chaque GOAL, figure en §0 de son
> rapport de mission ; celui-ci est le squelette commun.

```
Tu es le chef de mission du GOAL ONB-<nn>. Lis dans l'ordre : CONSTITUTION.md, PROJECT_BRAIN.md §2, SYSTEM_MAP.md,
PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2 et §3 surtout),
reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB<nn>_<SLUG>.md, puis plans/GOAL_ONB<nn>_<SLUG>_2026-08-26.md.
Exécute le pré-vol §0.1 du GOAL (worktree depuis HEAD, port <port>, .env.testing, vendor en liens durs, serveur, filet).
Puis « lance le GOAL » : vagues §X dans l'ordre, pipeline ultra-audit-profond par tâche, armée d'agents §A (spécialistes
lecture seule en un message, un seul implémenteur, ROUGE avant tout « fini », Jalonneur à chaque point de contrôle),
scénarios adverses §S obligatoires, convergence = deux cycles consécutifs P0+P1=0 aux constats identiques.
Tu possèdes uniquement les fichiers listés au §0.2 du GOAL ; toute autre modification est renvoyée au GOAL propriétaire.
Base partagée : préfixe GOAL-ONB<nn>, jamais migrate:fresh, tests via safe-test.sh. Jamais de push. Décisions
propriétaire : §G, tu ne les prends pas à sa place. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ, rien d'autre.
```

---

# §5 — GATES TRANSVERSES DU PROGRAMME (QUI / QUOI / OÙ)

| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement `CONSTITUTION.md §1` : « logiciel d'un établissement, entièrement paramétrable depuis son Dashboard ; Le Cayenne = première installation » | Propriétaire | Une ligne de confirmation + commit de la phrase | `CONSTITUTION.md §1` + `PROJECT_BRAIN.md §6` | **EN ATTENTE** (bloque 12 ; ne bloque pas 01-11, 13) |
| **G-PRIX** | Autoriser un LOCK sur `PricingService.php` pour porter les règles gratuit/inclus/payant du wizard (03) | Propriétaire | `LOCK_ONB03_PRICING_*.md` contresigné | `docs/gates/` | **EN ATTENTE** (bloque la vague d'implémentation de 03) |
| **G-IA** | Fournisseur, clé et budget de l'IA d'extraction de menu et de l'assistant (04) ; clé OpenAI déjà partagée par le scan de factures | Propriétaire | Choix fournisseur + clé en `.env` + plafond de dépense | `.env` + `MISSION_ONB04` §6 | **EN ATTENTE** (bloque la vague « réel » de 04, pas la vague « mock ») |
| **G-CACHE** | Pour chacune des 22 pages Réglages cachées : garder visible / cacher / retirer (05) | Propriétaire | Tableau tranché ligne par ligne | `MISSION_ONB05` §6 | **EN ATTENTE** (05 propose, propriétaire tranche) |
| **G-DATA** | Toute migration de schéma (03, 05, 12) et tout seeder générique remplaçant des données Le Cayenne (12) | Propriétaire | Accord par migration/seeder | `docs/gates/GATE_LOG.md` | **EN ATTENTE** |
| **G-PUSH** | Poussée distante, étiquette, PR | Propriétaire | Accord explicite | commit + `PROJECT_BRAIN.md §2` | **EN ATTENTE** (bloque la fin de 14) |

⛔ Un gate ne peut être approuvé ni par un agent, ni par un test.

---

# §6 — CONSTATS CLÉS DE LA RECONNAISSANCE (2026-08-26, arbre principal :8766)

Reconnaissance web réelle exécutée sur **4 zones** (Z1 catalogue, Z2 profil/réglages, Z3 utilisateurs/RBAC, Z7 équipement — deux passages,
coupés par les limites de session, consolidés par le chef de projet depuis les résultats bruts : ~160 appels API, ~70 captures lues) ;
**4 zones non exécutées** (Z4 dashboard/rapports, Z5 stock, Z6 animation commerciale, Z8 UX transverse — briefs prêts dans `recon/_ZONES.md`,
exécutés en **W1** des GOAL 07, 08, 09, 11). Cartes de code vérifiées : `recon/Z0_carte_dashboard.md`, `recon/Z0_modele_catalogue_wizard_reglages.md`.
Toutes les entités de test des auditeurs ont été **supprimées définitivement** et les réglages restaurés (preuve DB dans chaque `Z*.md §7`).

| Sév. | Constat (prouvé, deux moyens) | Zone | GOAL |
|---|---|---|---|
| P1 | Champs SIRET / TVA intra / mentions légales / barème de livraison de la filiale **jamais enregistrés** (API 200 sans les clés, base NULL, formulaire sans champ) | Z2 | 01 |
| P1 | Page Site **inenregistrable** sans clé Google Maps ni copyright (obligatoires, vides) ; zone de livraison cassée (`DrawingManager` retiré) | Z2 | 01 |
| P1 | Page Licence affiche la **clé d'API** en clair | Z2 | 05 / 13 |
| P1 | Nouvel article créé à **TVA 0 %** (config dit 10 %) ; table `taxes` : 6 légitimes + 47 parasites | Z1 + SQL | 02 |
| P1 | `kds_station` inconnue → **erreur SQL brute** ; canal inconnu **accepté** | Z1 | 02 |
| P1 | Modifier un employé sans téléphone → **erreur SQL brute** ; 80 permissions en **anglais/jargon** | Z3 | 06 |
| P1 | Déconnecter / désactiver / supprimer une borne **ne révoque pas son jeton** ; la borne créée n'est pas celle qui se connecte (`kiosk-lecayenne`, `.env`) | Z7 | 10 |
| P1 | Imprimantes : **aucune adresse LAN acceptée** (message anglais SMTP) ; statut écran 1/0 vs moteur 5 (réelles « Archivé ») ; largeur 42 refusée en silence ; TPE simulé non éditable | Z7 | 10 |
| P1 | **Aucune sémantique de prix** dans le wizard (`price` interdit) ; inclusions Le Cayenne en dur dans `config/menu.php`/`config/kiosk.php` | Z0 + Z1 | 03 |
| P1 | **9 mutateurs admin sans FormRequest** ; lecture des réglages ouverte au caissier ; `/api/health` détaillé sans auth | code + Z2 + Z7 | 13 |
| P1 | Installation neuve = **Le Cayenne** (menu, bornes, textes, comptes) ; **147 + 11** fichiers citent la marque ; aucune checklist | code | 12 |
| P2 | 22/31 sous-pages Réglages cachées par une liste de circonstance ; 6 interrupteurs booléens seulement | Z2, Z0 | 05 |
| P2 | Coupons/Offres cachés **et** désactivés par 3 drapeaux ; file `notifications` orpheline (~1 500 en Redis) ; coupon devis ≠ commit (15/08) | code, Z7 | 09, 10 |
| P2 | 5 entrées de menu pour le stock, 5 pour le catalogue ; « Ingrédients » = façade virtuelle ; 11 articles vendables sans poste de cuisine | Z0, SQL | 08, 02, 10 |
| P2 | Toasts et erreurs anglais, « Filiales », « Stuff », « SLA » ; colonnes hors cadre à 1366 ; alertes santé permanentes non actionnables ; PIN borne « 1234 » affiché | Z1, Z2, Z3, Z7 | 11, 10 |
| Instrument | « Page Rôles introuvable » = **mauvaise URL** (`role`, singulier) — retiré ; « État du système orphelin » = faux (menu en base) — corrigé | Z3, Z0 | 06, 05 |

**Ce qui marche et ne doit pas casser** (prouvé) : propagation immédiate des réglages ; anti-injection `.env` ; validations FR des pages visibles ; mot de passe ≥ 12,
révocation par appareil, plafond 10, garde-fous d'auto-suppression ; RBAC `pos@` 403 sur toutes les écritures ; borne désactivée refusée à la connexion ; suppression de
catégorie non vide bloquée ; doublons refusés ; upload > 8 Mo et `.svg` refusés ; `/api/healthz` honnête.

# §6bis — FICHIERS LIVRÉS PAR LA SESSION DE CADRAGE (branche `goal/onboarding-commercant-2026-08-26`)

| GOAL | Plan (`plans/`) | Rapport de mission (`reports/audit/onboarding-commercant-2026-08-26/`) |
|---|---|---|
| 01 | `GOAL_ONB01_IDENTITE_ETABLISSEMENT_2026-08-26.md` | `MISSION_ONB01_IDENTITE_ETABLISSEMENT.md` |
| 02 | `GOAL_ONB02_CATALOGUE_DE_ZERO_2026-08-26.md` | `MISSION_ONB02_CATALOGUE_DE_ZERO.md` |
| 03 | `GOAL_ONB03_WIZARD_REGLES_DE_PRIX_2026-08-26.md` | `MISSION_ONB03_WIZARD_REGLES_DE_PRIX.md` |
| 04 | `GOAL_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT_2026-08-26.md` | `MISSION_ONB04_EXTRACTION_MENU_IA_ET_ASSISTANT.md` |
| 05 | `GOAL_ONB05_REGLAGES_SANS_DEVELOPPEUR_2026-08-26.md` | `MISSION_ONB05_REGLAGES_SANS_DEVELOPPEUR.md` |
| 06 | `GOAL_ONB06_EQUIPE_ROLES_ACCES_2026-08-26.md` | `MISSION_ONB06_EQUIPE_ROLES_ACCES.md` |
| 07 | `GOAL_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS_2026-08-26.md` | `MISSION_ONB07_TABLEAU_DE_BORD_ET_RAPPORTS_VRAIS.md` |
| 08 | `GOAL_ONB08_STOCK_INGREDIENTS_DISPONIBILITE_2026-08-26.md` | `MISSION_ONB08_STOCK_INGREDIENTS_DISPONIBILITE.md` |
| 09 | `GOAL_ONB09_ANIMATION_COMMERCIALE_2026-08-26.md` | `MISSION_ONB09_ANIMATION_COMMERCIALE.md` |
| 10 | `GOAL_ONB10_EQUIPEMENT_ET_OPERATIONS_2026-08-26.md` | `MISSION_ONB10_EQUIPEMENT_ET_OPERATIONS.md` |
| 11 | `GOAL_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE_2026-08-26.md` | `MISSION_ONB11_EXPERIENCE_COMMERCANT_TRANSVERSE.md` |
| 12 | `GOAL_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE_2026-08-26.md` | `MISSION_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE.md` |
| 13 | `GOAL_ONB13_SECURITE_INTEGRITE_BACKOFFICE_2026-08-26.md` | `MISSION_ONB13_SECURITE_INTEGRITE_BACKOFFICE.md` |
| 14 | `GOAL_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT_2026-08-26.md` | `MISSION_ONB14_CONVERGENCE_JOURNEE_NOUVEAU_COMMERCANT.md` |

Matériel commun : `_BRIEF_COMMUN.md`, `_ZONES.md` (briefs Z1..Z8), `Z0_*.md` (cartes), `Z1/Z2/Z3/Z7_*.md` (mesures), `screens/Z*/` (captures), `_GABARIT_GOAL.md`,
`_GABARIT_MISSION.md`, `_CONSIGNE_REDACTEUR.md`, `_FICHES_GOAL.md`, `BRAIN_ENTRY_2026-08-26.md` (bloc à coller dans `PROJECT_BRAIN.md §4`).
**Chaque GOAL a été passé au contrôle anti-fiction** (script d'existence des chemins cités sur l'arbre principal) ; les seuls chemins absents sont marqués « À CRÉER »
ou sont des livrables du programme lui-même.

---

# §7 — RÈGLE FINALE DU PROGRAMME

Le programme est **TERMINÉ** quand, et seulement quand :
1. Les 14 GOAL sont convergés (règle §F de chacun) ou explicitement différés par le propriétaire avec motif écrit.
2. ONB-14 a prouvé, **deux cycles consécutifs aux constats identiques**, la journée complète d'un établissement qui n'est
   PAS Le Cayenne : identité → catalogue → wizard à règles de prix → équipe → équipement → commande borne → cuisine →
   encaissement → Z → rapports, **sans qu'un développeur ait touché un fichier**.
3. Diff zone gelée = 0 ligne sur tout le programme hors LOCK contresignés ; chaîne NF525 en ajout seul ; PHPUnit et Vitest
   ≥ bases §0.6 de chaque GOAL, plus les tests créés.
4. `PROJECT_BRAIN.md §2/§3/§4/§6/§7`, `CONSTITUTION.md` (si G0), `SYSTEM_MAP.md` (sous-voies) reflètent la réalité.

> Le but n'est pas d'avoir « des pages qui s'ouvrent ». Le but : qu'un restaurateur qui n'a jamais entendu parler de
> Le Cayenne installe ce logiciel, le règle seul, vende avec, et fasse confiance à ses chiffres le soir.

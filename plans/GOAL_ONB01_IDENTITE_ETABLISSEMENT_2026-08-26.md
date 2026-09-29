# GOAL — ONB-01 IDENTITÉ DE L'ÉTABLISSEMENT
## FoodKing — Onboarding commerçant · nom, logo, SIRET/TVA, adresse, horaires, mentions légales, devise, langue, apparence : une seule source, réglable sans développeur

- **Slug** : `ONB01_IDENTITE_ETABLISSEMENT_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « identité & apparence » (`settings/{Company,Site,Branch,Theme,Currency,Language,Page,TimeSlot,Slider}/**`)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB01_IDENTITE_ETABLISSEMENT.md`
- **Port de session** : **8801** · **Persona** : Nadia, kebab-burger à Lyon, 1 caisse, 1 borne, 2 cuisiniers, aucun service informatique.

> **En cinq lignes.** Le problème : l'identité d'un établissement est éclatée entre quatre écrans et deux sources (Entreprise ↔ Filiale), et
> les seules colonnes prévues pour SIRET / TVA / mention légale / barème de livraison **ne sont jamais enregistrées** ; il n'existe **aucun
> éditeur d'horaires**, aucune couleur réglable, et la page Site **refuse de s'enregistrer** sans clé Google Maps. La preuve : mesures Z2 du
> 2026-08-26 (`recon/Z2_profil_reglages.md`, 57 appels API, 25 pages). FINI = Nadia règle nom, logo, SIRET, TVA, adresse, horaires, mention
> du ticket, devise et couleurs depuis UN écran, et le ticket + la borne le reflètent (C1..C6). Ce GOAL ne touche ni au catalogue, ni aux rôles,
> ni aux bornes/imprimantes, ni à la visibilité du menu (→ ONB-05). Premier geste : W0 pré-vol puis rejouer `tmp/recon/Z2/02-api.js` sur :8801.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- Décision : **worktree dédié** `.claude/worktrees/onb01-identite` sur branche `goal/onb01-identite-2026-08-26`, créé **depuis HEAD**
  (`git worktree add .claude/worktrees/onb01-identite -b goal/onb01-identite-2026-08-26 HEAD`), jamais depuis `origin/main` (2 485 commits de retard).
- Pré-vol : copier `.env` → `APP_URL=http://127.0.0.1:8801` ; copier `.env.testing` (ignoré par git, sinon ~336 rouges fantômes) ; `vendor/` et
  `node_modules/` par **liens durs** (`rsync -a --link-dest=<principal>/vendor/ <principal>/vendor/ vendor/`) — ⛔ jamais de symlink `vendor/`
  (`__DIR__` résoudrait vers l'autre arbre) ; vérifier `php artisan tinker --execute='echo (new ReflectionClass(App\Models\Branch::class))->getFileName();'`
  → chemin du worktree ; `php artisan serve --host=127.0.0.1 --port=8801` ; `PLAYWRIGHT_BASE_URL=http://127.0.0.1:8801`.
- Base **partagée** `foodking_e2e` : préfixe `GOAL-ONB01` sur toute entité créée (filiales de test, créneaux) ; ⛔ jamais `migrate:fresh`, jamais
  `php artisan test` nu → `bash ~/.claude/skills/brain/scripts/safe-test.sh --phpunit "Settings|Branch|Company|Site|Theme|Receipt"`.
- ⚠️ Les pages **Entreprise** et **Site écrivent le `.env`** (`SiteRequest.php:31` : « written verbatim into .env ») : dans le worktree elles écrivent
  le `.env` DU WORKTREE (copie) — jamais celui de l'arbre principal. Ne jamais enregistrer ces pages sur `:8766`.
- Filet : `git branch backup/pre-onb01-2026-08-26` + `mysqldump foodking_e2e settings branches > reports/audit/onboarding-commercant-2026-08-26/onb01-pre.sql`.
- Git : fichiers nommés un par un, jamais `git add .`/`-A`, jamais push/force/`--no-verify`, un commit par vague.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS (sous-systèmes) | Fichiers POSSÉDÉS |
|---|---|
| S1 Source unique d'identité (Entreprise ↔ Filiale ↔ ticket ↔ borne) | `resources/js/components/admin/settings/{Company,Branch}/**`, `app/Http/Controllers/Admin/{CompanyController,BranchController}.php`, `app/Http/Requests/{CompanyRequest,BranchRequest}.php`, `app/Services/BranchService.php`, `app/Models/Branch.php` (champs identité), `app/Services/Receipt/ReceiptDataService.php` (lecture des champs identité), `app/Services/Hardware/OrderReceiptEscPosRenderer.php` (lignes identité seulement) |
| S2 Horaires & calendrier | nouveaux : migration `branch_opening_hours` (+ `branch_closures`), `app/Models/BranchOpeningHour.php`, `app/Services/OpeningHoursService.php`, `app/Http/Controllers/Admin/OpeningHoursController.php`, `app/Http/Requests/OpeningHoursRequest.php`, `resources/js/components/admin/settings/OpeningHours/**` ; existant : `settings/TimeSlot/**`, `TimeSlotController`, `TimeSlotRequest` |
| S3 Marque & apparence | `settings/Theme/**`, `ThemeController.php`, `ThemeRequest.php`, modèle `ThemeSetting`, `settings/Slider/**`, `settings/Page/**`, nouveau `resources/css/brand-tokens.css` (variables), `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (**non gelé** — textes en dur, coordination voie BORNE) |
| S4 Localisation & écritures `.env` | `settings/{Site,Currency,Language}/**`, `SiteController.php`, `SiteRequest.php`, `app/Services/SiteService.php`, `CurrencyController`, `LanguageController` |

| HORS (déclaré) | Porté par |
|---|---|
| Visibilité des pages Thème / Langues / Pages / Bannières / Créneaux dans le menu (`v1-hidden-modules.js`, `MenuComponent.vue`) | **ONB-05** (demande de dé-cachage à écrire dans MISSION §8) |
| Taxes, catalogue, images produit | ONB-02 |
| Rôles, comptes | ONB-06 |
| Bornes, imprimantes, TPE, PIN borne | ONB-10 |
| Dé-cayennisation globale des seeders / commandes | ONB-12 (ce GOAL lui livre le **modèle de données** d'identité) |
| Journal « qui a changé quoi » | ONB-13 (ce GOAL émet `SettingsUpdated`, ne journalise pas) |
| Composants kiosk gelés (`KioskWizardComponent`, `KioskAppComponent`, `KioskUpsellComponent`), `BranchScope.php`, fiscal | jamais |

Zones à coordonner (append-coordination, déclarer chaque ligne) : `routes/api.php` (routes horaires), `resources/js/router/modules/settingRoutes.js`
(route horaires), `resources/js/languages/fr.json` (bloc `label.*` de la zone, jamais en fin de fichier), `database/seeders/DatabaseSeeder.php`.

## §0.3 — Drapeaux d'expansion de périmètre
| Drapeau | Seuil | Action |
|---|---|---|
| SCOPE-1 | correctif touchant un fichier gelé (trio kiosk, fiscal, pricing, BranchScope) | STOP → `lock-plan` + contreseing |
| SCOPE-2 | 3 boucles de soin sur le même amas | STOP → §X.9 |
| SCOPE-3 | migration non prévue au §G (autre que horaires/fermetures) | STOP → G-DATA |
| SCOPE-4 | chaîne NF525 modifiée hors ajout (aucune raison ici) | STOP IMMÉDIAT |
| SCOPE-5 | besoin d'éditer un fichier d'un autre GOAL (`v1-hidden-modules.js`, `ItemRequest`, `PrinterRequest`…) | STOP → fiche de renvoi dans MISSION §8 |

## §0.4 — Pipeline par tâche (référence unique)
Chaque `T-x.y.z` via **`ultra-audit-profond`** ; zone gelée → `lock-plan` ; page → `test-e2e` ; constats → `verify-before-report` ;
`superpowers:test-driven-development` (rouge d'abord) ; `superpowers:systematic-debugging` avant tout correctif. Non redécrit.

## §0.5 — Critères de convergence et règles de rejet
Rejet si : étiquette brute à l'écran · casse de mise en page (1366×768, 1024×768, 768×1024) · erreur console · diff gelé ≠ 0 · P0 non traité ·
test rouge non documenté · acceptation sans chemin de test · « ça marche presque » · NF525 hors ajout · deux cycles aux constats différents.
**Convergence = deux cycles consécutifs avec P0+P1 = 0 ET ensembles de constats identiques.** Critères chiffrés propres :

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Les champs fiscaux de la filiale se sauvegardent | `PUT branch` avec `siret, vat_intra, legal_footer, delivery_fee_*` → relus par `GET` ET présents en base | **100 %** des 8 champs |
| C2 | Le ticket porte l'identité saisie | rendu ESC/POS d'une commande de test contient `siret`, `vat_intra`, `legal_footer` saisis | **3/3** |
| C3 | La page Site s'enregistre sans clé Google Maps ni copyright | PUT avec ces deux champs vides | **200** |
| C4 | Horaires : la borne affiche « fermé » hors horaires | projection `GET /api/frontend/setting` (ou route retenue) + écran borne | **VRAI** aux 3 cas (ouvert / fermé / fermeture exceptionnelle) |
| C5 | Couleurs de marque appliquées sans recompilation | changer `#F4501E` → `#0055FF` en réglage → `getComputedStyle` sur admin, borne, OSS | **3/3** surfaces |
| C6 | Zéro « Cayenne » en dur sur l'écran d'accueil borne | `grep -c "Cayenne\|tacos" KioskIdleScreenComponent.vue` | **0** (hors clé de traduction) |

## §0.6 — Base de référence héritée (à ne pas dégrader)
PHPUnit **5 194 passés** (2026-08-25 ; 4 862 la veille — écart non expliqué, ne pas l'inventer) · Vitest **445 fichiers / 3 644 passés / 0 échec** ·
zone gelée **0 ligne** · NF525 `audit_logs` 8 119 en ajout seul, `z_reports` 33 · `tests/Feature/Settings/` = **7 fichiers** · `tests/Feature/Receipt/` +
`tests/Feature/Hardware/` (8+ fichiers dont `KioskKitchenTicketTest.php`) · `branches` = **6 lignes** (1 réelle « Le Cayenne (principal) » + 5 de démonstration
« Collier and Sons Branch »…) · mesures Z2 : 25/25 pages en 200, 0 libellé brut, 0 réponse ≥ 400, **1 erreur JS** (zone de livraison).

## §0.7 — Contradictions détectées et tranchées (CLAUDE.md §12)
- **C-CONST** — `CONSTITUTION.md §1` « logiciel PERSONNEL de Le Cayenne, PAS un SaaS » vs mandat 2026-08-26 « livrable à un nouvel établissement ».
  Résolution de l'index : **paramétrer ≠ multi-tenant** ; ce GOAL sort l'identité du code vers la donnée, pour UN établissement. Gate **G0**.
- **C-DOC-1** — `Z0_modele_*.md §E` dit « SIRET/TVA sur la filiale » ; mesuré Z2 : les colonnes existent (`2026_04_20_210000_add_fiscal_identity_to_branches.php`)
  mais **aucune requête ni service ne les écrit** (`grep siret BranchRequest.php BranchService.php` = vide). Tranché : c'est un défaut, pas une décision.
- **C-DOC-2** — `OrderSetupRequest.php:32-45` garde 3 champs de frais de livraison « pour compatibilité » et dit que le vrai calcul lit `branches` :
  deux écrans pour une donnée. Tranché : la filiale est la source, Configuration des commandes n'affiche plus ces trois champs (coordination ONB-05).
- **C-SAAS** — la page Site exige « passerelle de paiement en ligne », « connexion invité », liens Android/iOS, clé Google Maps : vocabulaire SaaS
  d'origine. Tranché : rendus facultatifs ici ; leur retrait du menu relève de ONB-05 (G-CACHE).

## §0.8 — Le commerçant-type et ses questions
Nadia, 41 ans, kebab-burger, ouvre à 11 h, ferme le lundi, TVA 10 %, SIRET sur le ticket obligatoire, veut son logo et « son » orange.
1. « Où je mets mon nom, mon SIRET et ma TVA, et est-ce que ça s'imprime ? » 2. « Comment je dis que je suis fermée le lundi et le 15 août ? »
3. « Je mets mon logo et mes couleurs où, et ça change la borne ? » 4. « Pourquoi on me demande une clé Google pour changer mon fuseau horaire ? »
5. « Filiale, c'est quoi ? J'ai un seul restaurant. » — Chaque question doit recevoir un OUI prouvé (test nommé + capture lue).

# §1 — CARTE DU SYSTÈME (ancrages vérifiés — sortie brute)

| Sous-système | Maturité mesurée | Ancrage réel | Tests existants |
|---|---|---|---|
| S1 Identité | **CASSÉE en silence** (champs fiscaux jamais écrits) | `app/Models/Branch.php:14-52` · `database/migrations/2026_04_20_210000_add_fiscal_identity_to_branches.php` · `app/Http/Requests/BranchRequest.php` (sans champs fiscaux) · `app/Services/BranchService.php` (`:89-92` filiale par défaut non supprimable) · `app/Http/Controllers/Admin/CompanyController.php:19,36` · `app/Http/Requests/CompanyRequest.php:29-42` · `app/Services/Receipt/ReceiptDataService.php` · `app/Services/Hardware/OrderReceiptEscPosRenderer.php` | `tests/Feature/Settings/` (7) · `tests/Feature/Receipt/` · `tests/Feature/Hardware/` (8+) |
| S2 Horaires | **INEXISTANTE** (créneaux cachés pensés pour la commande en ligne) | `app/Http/Controllers/Admin/TimeSlotController.php` (`routes/api.php:643-647`) · `app/Http/Requests/TimeSlotRequest.php` · `settings/TimeSlot/TimeSlotListComponent.vue` · date métier = cron Z `app/Console/Kernel.php:495-549` | aucun dédié → À CRÉER |
| S3 Marque | **PARTIELLE** (3 logos, cachée, aucune couleur) | `app/Http/Requests/ThemeRequest.php:33-37` · `app/Http/Controllers/Admin/ThemeController.php` · `settings/Theme/ThemeComponent.vue` · logo sidebar `resources/js/components/layouts/backend/BackendMenuComponent.vue:6,9` · textes en dur `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (grep « Composez votre tacos », « 100% HALAL » = 1 fichier) | aucun dédié → À CRÉER |
| S4 Localisation / `.env` | **FRAGILE** (écritures `.env`, champs SaaS obligatoires) | `app/Http/Requests/SiteRequest.php:31,36-41,52,55` · `app/Services/SiteService.php` · `app/Http/Controllers/Admin/SiteController.php` · `CurrencyController` (`routes/api.php:497-503`) · `LanguageController` (`:649-659`) | `tests/Feature/Settings/` (partiel) |

**Sortie d'ancrage brute (2026-08-26)** : `grep -rl siret database/migrations` → `2026_04_20_210000_add_fiscal_identity_to_branches.php` ·
`grep -n "siret\|vat_intra\|legal_footer\|delivery_fee" app/Http/Requests/BranchRequest.php app/Services/BranchService.php` → **vide** ·
`grep -rln "legal_footer\|siret" app/Services resources/views` → `Hardware/OrderReceiptEscPosRenderer.php`, `Receipt/ReceiptDataService.php` ·
`grep -n "site_google_map_key\|site_copyright" SiteRequest.php` → `:52 required`, `:55 required` · `ls tests/Feature/Settings | wc -l` → 7 ·
`ls app/Services | grep -i site` → `SiteService.php` · `grep -rln "Composez votre tacos\|100% HALAL" resources/js/components/frontend/kiosk` → `KioskIdleScreenComponent.vue` ·
`SELECT COUNT(*) FROM branches` → 6 · `settings` : `company_name = "Le Cayenne"`, `kiosk_welcome_title = "Bienvenue !"`.

# §2 — ÉTAT MESURÉ LE 2026-08-26 (extrait de `recon/Z2_profil_reglages.md`)

**Ce qui marche** : 25/25 pages 200, 0 libellé brut ; validations FR (nom dupliqué, ville vide, PIN, longueurs, créneaux chevauchants, taux négatif) ;
anti-injection `.env` (`\n`, `"` → 422) ; propagation immédiate (`GET /api/frontend/setting` sans cache) ; RBAC `pos@` 403 sur toutes les écritures.
**Constats** : [P1] champs fiscaux/livraison de la filiale jamais enregistrés (API 200 sans les clés, base NULL, formulaire à 11 champs sans SIRET) ·
[P1] page Site inenregistrable sans clé Google Maps + copyright (`SiteRequest.php:52,55`) · [P1] zone de livraison : `DrawingManager` retiré de l'API
Google Maps v3.65 (seule erreur JS des 25 pages) · [P1] page Licence = clé d'API en clair (→ ONB-05/13) · [P2] lecture de `company/site/order-setup/branch`
ouverte au POS Operator (→ ONB-13) · [P2] borne : « Composez votre tacos comme vous l'aimez », « Le Cayenne », « 100% HALAL » en dur ·
[P2] toasts anglais (« Filiales Created Successfully. »). **Angles morts** : horaires, SIRET/TVA/mention, barème, couleurs, langue — aucun écran.
**Cayenne en dur** : `company_name` (donnée), textes borne, 5 filiales de démonstration (seeder), comptes `@lecayenne.fr`.

# §3 — SOUS-SYSTÈME 1 : SOURCE UNIQUE D'IDENTITÉ (Entreprise ↔ Filiale ↔ ticket ↔ borne)

### Contrat
Une donnée d'identité se saisit à UN endroit, se relit partout (ticket, borne, rapports, e-mails) et se prouve par le contenu imprimé, jamais par un 200.
**Modèle retenu** (décision chef de projet, à confirmer G-ID) : *Entreprise* = nom commercial, logo, contact ; *Filiale* = point de vente : adresse,
SIRET, TVA intra, `register_id`, `legal_footer`, barème livraison. En mono-établissement, un écran **« Mon établissement »** lit et écrit les deux.

## Sub 1.1 — Ce qui s'imprime et s'affiche vraiment
**Ancrages** : `app/Services/Receipt/ReceiptDataService.php`, `app/Services/Hardware/OrderReceiptEscPosRenderer.php`, `config/printing.php:83,109,185`
(`RECEIPT_WEBSITE`, `RECEIPT_PHONE`, afficheur « LE CAYENNE » — mesuré Z7), `KioskIdleScreenComponent.vue`.
**Hérité** : tests `tests/Feature/Hardware/{KioskKitchenTicketTest,ClientTicketMenuRoleLabelTest}.php` prouvent le rendu du ticket (pas l'identité).
**Tâches**
- **T-1.1.1** — Cartographier chaque champ d'identité → surface(s) où il apparaît (ticket client, ticket cuisine, afficheur, borne, e-mail, PDF EOD, rapports) —
  table écrite dans MISSION §8, chaque ligne prouvée par un `grep` + un rendu.
  • ancrage : `ReceiptDataService.php`, `OrderReceiptEscPosRenderer.php`, `config/printing.php` · test : (À CRÉER à `tests/Feature/Receipt/ReceiptIdentityFieldsCharacterizationTest.php`)
  • au-delà du premier degré : champ vide → ligne omise (pas de « null ») ; champ de 200 caractères → coupure propre à 32/48 colonnes.
- **T-1.1.2** — Faire porter le ticket par les valeurs de la filiale (SIRET, TVA, mention légale) et non par `config/printing.php`/`.env`.
  • ancrage : `ReceiptDataService.php` (source des champs) · test : (À CRÉER à `tests/Feature/Receipt/ReceiptUsesBranchIdentityTest.php`) · visuel : rendu ESC/POS décodé
  • au-delà : changement de SIRET → prochaine commande imprime le nouveau, la commande précédente garde l'ancien (snapshot ? à trancher, NF525 : le ticket est ré-imprimable à l'identique).
- **T-1.1.3** — Afficheur client et borne : remplacer « LE CAYENNE » / textes en dur par `company_name` + réglages (coordination voie BORNE pour `KioskIdleScreenComponent.vue`, non gelé).
  • ancrage : `config/printing.php:185`, `KioskIdleScreenComponent.vue` · test : (À CRÉER à `tests/js/kioskIdleScreenBrandFromSettings.spec.js`) · visuel : `http://127.0.0.1:8801/kiosk/idle`
**Acceptation** : table de cartographie écrite · 2 tests VERTS · C2 = 3/3 · C6 = 0 · capture borne lue.

## Sub 1.2 — Champs fiscaux et de livraison de la filiale (le P1)
**Ancrages** : `app/Http/Requests/BranchRequest.php`, `app/Services/BranchService.php`, `app/Models/Branch.php:14-52`, `settings/Branch/{BranchCreateComponent,BranchShowComponent}.vue`,
migration `2026_04_20_210000_add_fiscal_identity_to_branches.php`.
**Tâches**
- **T-1.2.1** — Test de caractérisation ROUGE d'abord : `PUT branch` avec les 8 champs → aujourd'hui absents de la réponse et NULL en base.
  • test : (À CRÉER à `tests/Feature/Settings/BranchFiscalFieldsRoundTripTest.php`)
- **T-1.2.2** — `BranchRequest` : `siret` (14 chiffres, Luhn), `vat_intra` (`FR` + 11 caractères), `register_id`, `legal_footer` (max 500), `delivery_fee_base/_per_km/_minimum/_free_km ≥ 0`,
  `delivery_minimum_order ≥ 0` ; `BranchService::store/update` persistent ; ressource renvoie les champs.
  • ancrage : `BranchRequest.php`, `BranchService.php` · test : le même, VERT · au-delà : SIRET invalide → 422 FR ; `pos@` → 403 ; deux onglets → dernier écrit gagne, prouvé.
- **T-1.2.3** — Formulaire filiale : section « Identité légale » + « Livraison » (masquée si la livraison est désactivée dans Configuration des commandes) ; onglet Informations affiche SIRET/TVA.
  • ancrage : `BranchCreateComponent.vue`, `BranchShowComponent.vue` · test : (À CRÉER à `tests/js/branchFormFiscalFields.spec.js`) · visuel : `/admin/settings/branches/show/1`
- **T-1.2.4** — Retirer les 3 champs de frais de `OrderSetup` de l'écran (garder la compatibilité API, `OrderSetupRequest.php:32-45`) — **demande à ONB-05** (fichier hors voie) consignée MISSION §8.
**Acceptation** : C1 = 100 % · 2 tests VERTS · captures 1366/1024 lues · zéro toast anglais sur ces écrans.

## Sub 1.3 — Écran unique « Mon établissement »
**Ancrages** : `settings/Company/CompanyComponent.vue`, `CompanyController.php`, `settings/Branch/**`, `settingRoutes.js:69-78,91-125`.
**Tâches**
- **T-1.3.1** — Décision de forme (G-ID) : un écran composite (Entreprise + Filiale 1) ou lien croisé explicite. Recommandation : **composite** en mono-établissement,
  liste des filiales conservée pour le futur, libellé « Filiales » → « Points de vente » (renommage = ONB-11, vocabulaire).
- **T-1.3.2** — Implémenter l'écran retenu ; enregistrement en deux appels (company, branch) avec **transaction d'affichage** : si le second échoue, message clair, rien de perdu.
  • test : (À CRÉER à `tests/js/establishmentProfileComposite.spec.js`) + `tests/Feature/Settings/CompanySettingsRoundTripTest.php` (À CRÉER — hérité SET-T01 du 13/08, jamais écrit)
  • au-delà : annulation à mi-chemin → aucune écriture ; rechargement pendant l'enregistrement → état cohérent ; double clic → un seul PUT.
- **T-1.3.3** — Cohérence des 5 filiales de démonstration (`Collier and Sons Branch`…) : proposer leur archivage (seeder socle = 1 filiale) — **renvoi ONB-12**, aucune suppression ici.
**Acceptation** : question 1 et 5 de Nadia = OUI prouvé · 2 tests VERTS · captures lues.

# §4 — SOUS-SYSTÈME 2 : HORAIRES & CALENDRIER

### Contrat
Le commerçant déclare ses horaires hebdomadaires et ses fermetures exceptionnelles ; la borne (et le site, via projection) disent « fermé » hors horaires ;
la caisse n'est **jamais** bloquée ; la date métier NF525 (cron Z 23:59/00:01) n'est **pas** touchée.

## Sub 2.1 — Modèle et API
**Ancrages** : `TimeSlotController.php`, `TimeSlotRequest.php` (validations mesurées : fin < début, chevauchement → 422), `app/Console/Kernel.php:495-549`.
**Tâches**
- **T-2.1.1** — Trancher : réutiliser `time_slots` (jour + plage, pensé « commande en ligne ») ou créer `branch_opening_hours` (jour, plages multiples, fermé) + `branch_closures` (date, motif).
  Recommandation : **nouvelles tables** (les créneaux restent un outil de livraison) — **gate G-DATA**.
- **T-2.1.2** — Migration + modèle + `OpeningHoursService::isOpenAt(Carbon $at, Branch $b)` + `OpeningHoursRequest` (plages non chevauchantes, 00:00-23:59, coupure de nuit 18:00-02:00 autorisée).
  • test : (À CRÉER à `tests/Feature/Settings/OpeningHoursServiceTest.php`) · au-delà : fuseau Europe/Paris, changement d'heure, fermeture exceptionnelle prioritaire sur l'hebdomadaire.
- **T-2.1.3** — Routes `GET/PUT /api/admin/setting/opening-hours` (`permission:settings`) + projection publique dans `GET /api/frontend/setting` (`is_open_now`, `next_opening`).
  • ancrage : `routes/api.php` (append-coordination) · test : (À CRÉER à `tests/Feature/Settings/OpeningHoursApiTest.php`)
**Acceptation** : 2 tests VERTS · G-DATA tranché · `pos@` → 403 en écriture, 200 en lecture publique.

## Sub 2.2 — Écran et effets
**Tâches**
- **T-2.2.1** — Écran « Horaires » (7 jours × plages, bouton « fermé », liste des fermetures) sous Réglages ; dé-cachage/entrée de menu **via ONB-05**.
  • test : (À CRÉER à `tests/js/openingHoursEditor.spec.js`) · visuel : `/admin/settings/opening-hours` à 1366/1024/768.
- **T-2.2.2** — Effet borne : écran « fermé » avec prochaine ouverture (composant kiosk **non gelé** : `KioskIdleScreenComponent.vue` — coordination BORNE) ; la caisse ignore les horaires.
  • test : (À CRÉER à `tests/js/kioskIdleClosedState.spec.js`) · visuel : `/kiosk/idle` hors horaires (horloge simulée).
- **T-2.2.3** — Preuve d'indépendance NF525 : ouvrir/fermer n'appelle rien dans `app/Services/Fiscal/*` ; le Z reste piloté par le cron.
  • test : (À CRÉER à `tests/Feature/Settings/OpeningHoursDoNotTouchFiscalTest.php`)
**Acceptation** : C4 VRAI · 3 tests VERTS · zéro diff dans `app/Services/Fiscal/**`.

# §5 — SOUS-SYSTÈME 3 : MARQUE & APPARENCE

### Contrat
Logo, favicon, couleurs, textes d'accueil sont des **données** ; la palette Cayenne (`#F4501E` / `#FFB800` / `#1A1A1A`) devient la valeur par défaut, pas une constante.

## Sub 3.1 — Logos et thème
**Ancrages** : `ThemeRequest.php:33-37` (3 fichiers jpg/png ≤ 2 Mo), `ThemeController.php`, `settings/Theme/ThemeComponent.vue`, `BackendMenuComponent.vue:6,9`.
**Tâches**
- **T-3.1.1** — Caractériser où chaque logo est consommé (sidebar, borne, ticket, PDF, e-mail) et ce qui se passe sans logo (repli) — table MISSION §8.
  • test : (À CRÉER à `tests/Feature/Settings/ThemeLogoConsumersTest.php`)
- **T-3.1.2** — Validation d'image robuste (type réel par contenu, pas extension ; dimensions min ; SVG refusé avec message FR) — motif partagé avec ONB-02 (renvoi si règle commune).
- **T-3.1.3** — Dé-cacher Thème — **demande ONB-05** ; d'ici là, atteignable par URL.
**Acceptation** : test VERT · captures logo remplacé lues sur admin + borne.

## Sub 3.2 — Couleurs de marque à l'exécution
**Tâches**
- **T-3.2.1** — Réglages `brand_primary`, `brand_accent`, `brand_dark` (hex validé) exposés à `window.foodkingConfig` et `GET /api/frontend/setting` ; feuille `resources/css/brand-tokens.css`
  déclarant `--brand-primary` etc. ; les composants admin/borne/OSS consomment les variables (inventaire des constantes `#F4501E` par grep, remplacement par variable, **sans** toucher au trio kiosk gelé).
  • test : (À CRÉER à `tests/js/brandTokensApplied.spec.js`) · visuel : les 3 surfaces avec `#0055FF`
  • au-delà : contraste minimal WCAG (refus d'une couleur illisible sur blanc), retour au défaut en un clic.
- **T-3.2.2** — Textes d'accueil borne (`kiosk_welcome_*` existants) + nouveaux `brand_tagline`, `brand_claims` (ex. « 100 % halal ») remplaçant les chaînes en dur de `KioskIdleScreenComponent.vue`.
  • test : `tests/js/kioskIdleScreenBrandFromSettings.spec.js` (T-1.1.3) · C6 = 0.
**Acceptation** : C5 = 3/3 · C6 = 0 · trio kiosk gelé : 0 ligne de diff.

# §6 — SOUS-SYSTÈME 4 : LOCALISATION & ÉCRITURES `.env`

## Sub 4.1 — Site : rendre enregistrable et sûr
**Ancrages** : `SiteRequest.php:31,36-41,52,55`, `app/Services/SiteService.php`, `SiteController.php`, `settings/Site/SiteComponent.vue`.
**Tâches**
- **T-4.1.1** — Test ROUGE : PUT sans clé Google ni copyright → 422 aujourd'hui ; puis `nullable` sur `site_google_map_key`, `site_copyright` (valeur par défaut = `company_name`).
  • test : (À CRÉER à `tests/Feature/Settings/SiteSavesWithoutGoogleKeyTest.php`)
- **T-4.1.2** — Inventaire de ce que `SiteService` écrit dans `.env` (clé par clé) et des effets (cache config, redémarrage, permissions fichier, worktree) ; proposer la migration
  vers la table `settings` pour tout ce qui n'est pas réellement une variable d'environnement — **gate G-ENV** (décision propriétaire, changement de mécanisme).
  • test : (À CRÉER à `tests/Feature/Settings/SiteEnvWritesInventoryTest.php`) — sentinelle : toute nouvelle clé écrite dans `.env` doit être listée.
- **T-4.1.3** — Devise / formats / fuseau : prouver l'effet sur ticket, rapports, borne (`site_currency_position`, `site_digit_after_decimal_point`) — pas seulement l'enregistrement.
  • test : (À CRÉER à `tests/Feature/Settings/SiteFormatsPropagationTest.php`)
**Acceptation** : C3 = 200 · 3 tests VERTS · G-ENV tranché.

## Sub 4.2 — Langue et zone de livraison
**Tâches**
- **T-4.2.1** — Langues : FR verrouillé (ADR-007) ; la page cachée Langues sert d'éditeur de fichiers (`LanguageController` file-text) — trancher : outil d'intégrateur (garder caché) ou retirer.
  Mesuré Z2 : éditeur chargé (17 fichiers, 5 champs). Renvoi ONB-05/11.
- **T-4.2.2** — Zone de livraison (`BranchShowComponent.vue` onglet Zone) : `DrawingManager` retiré (v3.65). Options : A) polygone par saisie/import GeoJSON, B) rayon en km autour du point,
  C) retirer l'onglet en V1. Recommandation **B** (simple, sans clé Google). **Gate G-ZONE**.
  • test : (À CRÉER à `tests/js/branchDeliveryRadius.spec.js`) · visuel : `/admin/settings/branches/show/1` onglet Zone, **0 erreur console**.
**Acceptation** : 0 erreur console sur les 25 pages de la zone · G-ZONE tranché et implémenté.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES

| Fonction \ scénario | annulation à mi-chemin | rechargement pendant l'enregistrement | double soumission | deux onglets | rôle inférieur (API) | données vides | volume | réseau/worker coupé | effet ticket / borne / rapports | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Filiale (champs fiscaux) | `branchFormFiscalFields.spec.js` | `BranchFiscalFieldsRoundTripTest` | idem (idempotence PUT) | idem (dernier gagne, prouvé) | `pos@` 403 (`tests/Feature/Security/` — étendre) | SIRET vide accepté, ligne omise du ticket | N/A motivé (6 filiales) | N/A (écriture synchrone) | `ReceiptUsesBranchIdentityTest` | restaurer l'ancien SIRET → ticket suivant | 13/15 chiffres, lettres, `FRXX` |
| Entreprise / Site | `establishmentProfileComposite.spec.js` | `CompanySettingsRoundTripTest` | idem | idem | `pos@` 403 (mesuré) | copyright vide → défaut | N/A | `.env` non inscriptible → message clair (`SiteEnvWritesInventoryTest`) | formats → `SiteFormatsPropagationTest` | valeur précédente restaurée | `\n`, `"`, `=`, 190+ car. (mesuré 422) |
| Horaires | `openingHoursEditor.spec.js` | `OpeningHoursApiTest` | idem | idem | 403 écriture / 200 lecture | aucune plage = fermé | 7 j × 3 plages | horloge figée → dernière valeur | `kioskIdleClosedState.spec.js` | supprimer une fermeture → rouvert | 00:00-23:59, plage de nuit, 29 février |
| Marque | `brandTokensApplied.spec.js` | idem | N/A | idem | 403 | couleur vide → défaut Cayenne | N/A | N/A | 3 surfaces | « réinitialiser » | `#GGG`, contraste insuffisant, SVG, 27 Mo (mesuré 413 brut) |
| Zone de livraison | `branchDeliveryRadius.spec.js` | idem | N/A | idem | 403 | rayon vide = pas de livraison | N/A | N/A | frais de livraison (devis) | rayon précédent | 0 km, 500 km, négatif |

# §A — ARMÉE D'AGENTS : spécialistes, jalonnement, disputes

| Rôle | Outils | Consigne / angle |
|---|---|---|
| Architecte | lecture seule | frontière Entreprise/Filiale, écritures `.env` vs `settings`, dépendances ticket |
| Sécurité | lecture seule | injection `.env`, lecture des réglages par `pos@`, upload logo, exposition clé d'API |
| UX / A11y | lecture + axe-core | écran composite, 3 gabarits, clavier, contraste des couleurs choisies |
| **Psychologie commerçant** | lecture | vocabulaire (« Filiale », « Site », « Thème »), peur de casser le ticket, confiance (aperçu du ticket avant d'enregistrer) |
| DBA | lecture | migrations horaires, index, `BranchScope` non touché, `settings` group |
| SRE / Synchro | lecture | propagation `SettingsUpdated`, cache, effet borne en < 5 s |
| Implémenteur | Edit/Write/Bash | TDD rouge d'abord ; **jamais deux en parallèle** |
| ROUGE | lecture seule | réfute chaque « fini » : rejoue Z2 (`tmp/recon/Z2/02-api.js`) après correctif |
| QA visuel · ROUGE visuel | Playwright · lecture | captures lues et **contestées** indépendamment |
| **Jalonneur** | lecture | à chaque point de contrôle §X.8 : relit les 6 points, refuse la vague au premier « non » |

Matrice : frontend visuel → Arch, Séc, UX, Psy, Impl, ROUGE, QA vis, ROUGE vis · logique backend → Arch, Séc, DBA, Impl, ROUGE · migration → + DBA obligatoire ·
E2E ticket/borne → tous. Discipline : 5 spécialistes lecture seule en UN message ; ROUGE après implémentation, avant tout « fini » ; chaque agent écrit sur disque
`reports/test-e2e/ONB01_IDENTITE_ETABLISSEMENT/<round>/wave-<W>-<rôle>.json` (contrat `[P0..P3] file:line — titre / reproduction / preuve / recommandation` ;
P0/P1 sans file:line + reproduction = rejeté). Plafond 1 200-1 500 mots par agent.

# §X — VAGUES DE CONVERGENCE

| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol §0.1, bases §0.6, gates §G statués | séquentiel | — |
| **W1** | Reconnaissance ciblée : rejouer `02-api.js` + `04-pass2.js` sur :8801 ; cartographie ticket (T-1.1.1) ; inventaire `.env` (T-4.1.2) | fan-out lecture seule | — |
| **W2** | S1 — champs fiscaux, ticket, écran composite (T-1.1.2, T-1.2.*, T-1.3.*) | séquentiel | G-ID (forme de l'écran) |
| **W3** | S4 — Site enregistrable, formats, zone (T-4.1.1, T-4.1.3, T-4.2.2) | séquentiel | G-ZONE ; G-ENV pour la migration `.env` → settings |
| **W4** | S2 — horaires (T-2.*) | séquentiel | **G-DATA** |
| **W5** | S3 — marque (T-3.*) | séquentiel ; coordination BORNE pour `KioskIdleScreenComponent.vue` | — |
| **W6** | Convergence : deux cycles identiques, suite complète via le garde, diff gelé 0, BRAIN | séquentiel | — |

**W0** — filet, bases, `SELECT * FROM branches WHERE id=1` figé dans MISSION §8. **W1** — aucune écriture produit ; sortie = table de cartographie + inventaire.
**W2..W5** — chaque tâche : rouge → vert → ROUGE → visuel → point de contrôle. **W6** — `safe-test.sh --phpunit "Settings|Branch|Receipt|Hardware"`, Vitest complet,
Playwright `tests/e2e/onb01-*.spec.js` (À CRÉER) sur :8801, `git diff --stat -- <13 gelés>` = 0, `php artisan fiscal:verify-chain --all` CHAIN OK.

## §X.8 — Point de contrôle de vague (6 points, Jalonneur)
- [ ] toutes les tâches PASSENT ou échouent avec motif écrit · [ ] diff gelé = 0 · [ ] NF525 inchangée (ce GOAL ne touche pas au fiscal) · [ ] captures lues et analysées ·
- [ ] contestation ROUGE faite, P0/P1 nouveaux soignés ou différés avec motif · [ ] `PROJECT_BRAIN.md §2/§3` + MISSION §8 à jour, commits nommés. Un « non » ⇒ vague non close.

## §X.9 — Échec de convergence (3e boucle, même amas)
STOP · analyse de cause · `reports/test-e2e/ONB01_IDENTITE_ETABLISSEMENT/STUCK_<vague>_<horodatage>.md` · 4 options (accepter documenté / pivot / différer / gate humain) · attendre.

## §X.10 — Interruption
`wip(<vague>): partiel jusqu'à T-x.y.z` (fichiers nommés) · `INTERRUPT_<vague>_<horodatage>.md` (dernier commit vert, tâche, suivante, rapports sur disque) · BRAIN §2 · reprise : manifeste → `git status` → fumée.

# §G — GATES PROPRIÉTAIRE (QUI / QUOI / OÙ)

| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement `CONSTITUTION.md §1` (porté par l'index) | Propriétaire | ligne de confirmation | `CONSTITUTION.md` + `PROJECT_BRAIN.md §6` | EN ATTENTE — ne bloque pas ce GOAL |
| **G-ID** | Forme de l'écran « Mon établissement » : A) composite Entreprise + Filiale 1 (recommandé) · B) deux écrans liés | Propriétaire | choix A/B | MISSION §6 + commit | EN ATTENTE — bloque T-1.3.2 |
| **G-DATA** | Migration `branch_opening_hours` + `branch_closures` (nouvelles tables) | Propriétaire | accord écrit | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque W4 |
| **G-ENV** | Migrer les réglages Site/Entreprise du `.env` vers `settings` (changement de mécanisme) ; d'ici là `nullable` seulement | Propriétaire | accord + fenêtre | MISSION §6 | EN ATTENTE — bloque T-4.1.2 (mise en œuvre), pas l'inventaire |
| **G-ZONE** | Zone de livraison : A) polygone GeoJSON · B) rayon km (recommandé) · C) retirer en V1 | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-4.2.2 |
| **G-LOGO-BORNE** | Éditer `KioskIdleScreenComponent.vue` (voie BORNE, non gelé) depuis ce GOAL : accord de coordination | Propriétaire | accord | MISSION §8 | EN ATTENTE — bloque T-1.1.3, T-2.2.2, T-3.2.2 |

Protocole d'attente : W0-W3 exécutables immédiatement (T-4.1.2 en inventaire seul) ; W4 attend G-DATA ; W5 attend G-LOGO-BORNE. ⛔ Aucun gate approuvé par un agent.

# §R — RÉFÉRENCES
Compétences : `ultra-audit-profond` · `test-e2e` · `verify-before-report` · `lock-plan` · `superpowers:test-driven-development` · `superpowers:dispatching-parallel-agents`.
Mémoire : `CONSTITUTION.md` · `SYSTEM_MAP.md §5-6` · `PARALLEL_PROTOCOL.md` · `CLAUDE.md §3bis (palette), §7, §9` · `CLAUDE.md §7` (liste canonique des zones gelées).
Programme : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `reports/audit/onboarding-commercant-2026-08-26/{_FICHES_GOAL.md (ONB-01), recon/Z2_profil_reglages.md, recon/Z7_equipement_ops.md (§5 Cayenne en dur), recon/Z0_modele_catalogue_wizard_reglages.md §E}` ·
`tmp/recon/Z2/{02-api.js,04-pass2.js}` (scripts rejouables).
Antérieur : `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (SET-T01, T05, T06, N05-N07, N11-N12 jamais exécutés) · `plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md` (B5).

# §F — RÈGLE FINALE
Ce GOAL est **TERMINÉ** quand, et seulement quand :
1. Les 6 vagues sont closes selon §X.8 ; 2. C1..C6 sont VRAIS, chiffres écrits dans MISSION §8 ; 3. PHPUnit ≥ 5 194 + les tests créés (≥ 14 nommés ci-dessus), Vitest ≥ 3 644 ;
4. diff zone gelée = 0 ligne (trio kiosk, fiscal, pricing, BranchScope) ; 5. chaîne NF525 en ajout seul ; 6. les 6 gates tranchés ou explicitement différés ;
7. `PROJECT_BRAIN.md §2/§3/§4` et MISSION §8 disent la réalité ; 8. deux cycles de convergence consécutifs aux constats identiques ; 9. les demandes vers ONB-05 / ONB-11 / ONB-12 sont écrites (MISSION §8), pas exécutées ici.
**Interdit** : enregistrer Entreprise/Site sur `:8766` · toucher `KioskWizard/KioskApp/KioskUpsell` · supprimer une filiale existante · déclarer vert sans capture lue · approuver un gate à la place du propriétaire.
> Le sens : Nadia lit son SIRET sur son ticket, sa fermeture du lundi sur sa borne, et son orange sur son écran — sans avoir appelé personne.

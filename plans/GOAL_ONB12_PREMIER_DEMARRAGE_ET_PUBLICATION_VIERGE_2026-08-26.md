# GOAL — ONB-12 PREMIER DÉMARRAGE & PUBLICATION VIERGE
## FoodKing — Onboarding commerçant · une installation neuve reçoit un socle générique (pas le menu de Le Cayenne), une checklist « Premier démarrage » dans le Dashboard, et la marque sort du code pour devenir une donnée

- **Slug** : `ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **Voie SYSTEM_MAP** : **TRANSVERSE** — `database/seeders/**` (sauf permissions/rôles → 06), `app/Console/Commands/{Menu*,*Cayenne*,FreshOrderSeed}.php`, `Installer/**`, nouveaux `admin/onboarding/**`, `config/menu.php`, `config/menu_images.php` ; renvoie chaque libellé « cayenne » au GOAL propriétaire du fichier
- **HEAD** : `43b120c7d` · **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE.md`
- **Port de session** : **8812** · **Vague C** : après ONB-01, 02, 05 et **G0** · **Persona** : l'intégrateur qui installe le logiciel chez Nadia un dimanche, puis Nadia qui l'ouvre le lundi.

> **En cinq lignes.** Le problème, mesuré le 26/08 : `DatabaseSeeder` (134 lignes) installe **le menu, les rôles-landing, les bornes et les textes de Le Cayenne** (`MenuSeeder` 845 lignes,
> `LeCayenneRoleLandingUrlSeeder`, `KioskMachineTableSeeder`, `CompanyTableSeeder`, 5 filiales de démonstration « Collier and Sons »…) ; **147 fichiers** de `app/ config/ resources/js/
> database/ routes/` + **11** vues/langues citent « cayenne » ; 12 commandes artisan `Menu*`/`*Cayenne*` (dont `MenuResetLeCayenneCommand` 1 250 lignes) ; l'installeur Blade
> `/install` (153 lignes) est hors Dashboard ; aucune checklist de démarrage ; `GrillHouseMenuSeeder` = tentative antérieure de seconde marque, **bloquée** (« DEPRECATED — DO NOT USE »).
> FINI = `migrate --seed` sur une base vide donne un établissement générique et propre ; checklist « Premier démarrage » (7 étapes) ; sentinelle « zéro marque dans le code » ; les commandes
> Cayenne archivées (C1..C6). Rien de Le Cayenne n'est supprimé : tout devient **un jeu de données parmi d'autres**. Premier geste : W0 puis créer la base dédiée `foodking_onb12` (G-DATA).

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb12-vierge`, branche `goal/onb12-vierge-2026-08-26`, depuis **HEAD** (après 01/02/05 : le modèle de réglages doit être stable).
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8812` **et `DB_DATABASE=foodking_onb12`** (base **dédiée**, créée vide — **exception** à la base partagée, gate G-DATA : c'est le seul moyen de prouver une installation vierge) ; `.env.testing` ; liens durs ; serveur 8812 ; `PLAYWRIGHT_BASE_URL`.
- ⛔ **Jamais** `migrate:fresh`/`db:seed` sur `foodking_e2e` ou toute base existante ; les commandes de reset de menu (`menu:reset-le-cayenne`, `MenuTruncateTableSeeder`, `EXECUTE_MENU_FIX.sh`) ne s'exécutent **que** sur `foodking_onb12`, jamais ailleurs, jamais en prod.
- `safe-test.sh --phpunit "Seeder|Installer|Onboarding|Menu|Sentinels"` (sqlite `:memory:` pour la suite ; la base dédiée sert aux preuves d'installation).
- Filet : `git branch backup/pre-onb12-2026-08-26` ; inventaire `git grep -il cayenne` figé en W0 (147 + 11).

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Installation vierge reproductible | `database/seeders/DatabaseSeeder.php`, nouveaux `database/seeders/Socle/**` (permissions/rôles restent ONB-06 : ce GOAL les **appelle**), `database/seeders/LeCayenne/**` (déplacement des seeders de marque), `app/Console/Commands/FoodkingInstallerEtablissementCommand.php` (À CRÉER), `app/Http/Controllers/Installer/InstallerController.php` (153 l.), vues `resources/views/installer/**`, `docs/INSTALLATION_ETABLISSEMENT.md` (À CRÉER) |
| S2 Checklist « Premier démarrage » | (À CRÉER) `resources/js/components/admin/onboarding/{OnboardingChecklistComponent,OnboardingStepCard}.vue`, `app/Http/Controllers/Admin/OnboardingController.php`, `app/Services/Onboarding/OnboardingProgressService.php`, migration `onboarding_progress` (ou `settings`), `onboardingRoutes.js`, spécification `docs/UX_PREMIERE_HEURE.md` (ONB-11) |
| S3 Dé-cayennisation | `config/menu.php`, `config/menu_images.php`, `app/Console/Commands/{MenuCommand,MenuHeal*,MenuResetLeCayenneCommand,ApplyLeCayenneV2Command,EnsureCayenneMixteCommand,EnsureKidsMenuStepsCommand,AssignMenuVatCommand,FreshOrderSeed}.php` (archivage sous `Commands/LeCayenne/`), `tests/Feature/Sentinels/NoBrandInCodeSentinelTest.php` (À CRÉER), inventaire `reports/audit/onboarding-commercant-2026-08-26/INVENTAIRE_CAYENNE.md` (À CRÉER) |
| S4 Preuve | `tests/Feature/Onboarding/**` (À CRÉER), `tests/e2e/onb12-installation-vierge.spec.js` (À CRÉER) |

| HORS | Porté par |
|---|---|
| Libellés « Cayenne » dans les composants/config d'autres voies (borne `KioskIdleScreenComponent.vue`, `config/kiosk.php` identité, `config/printing.php`, `config/app.php:123,129` comptes) | **fiche de renvoi** au GOAL/voie propriétaire (01, 10, BORNE, CAISSE) — ce GOAL inventorie, sentinelle, ne modifie pas hors sa voie |
| Permissions / rôles socle | ONB-06 |
| Réglages typés, valeurs par défaut du socle (mécanisme) | ONB-05 |
| Identité (modèle de données) | ONB-01 |
| Catalogue, taxes FR par défaut | ONB-02 (`TaxTableSeeder`) |
| `CONSTITUTION.md` | **G0** — le propriétaire seul |

Zones à coordonner : `DatabaseSeeder.php` (registre), `routes/api.php`, `router/index.js`, `fr.json`, `config/app.php` (providers).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé · SCOPE-2 3 boucles · SCOPE-3 migration prévue (`onboarding_progress`) : G-DATA ; base dédiée : G-DATA · SCOPE-4 NF525 : une installation vierge **initialise** la chaîne fiscale (premier `audit_logs`) — vérifier `FiscalInstallImmutabilityTriggersCommand` sans le modifier · SCOPE-5 : toute suppression de donnée Le Cayenne = STOP (rien n'est supprimé, tout est déplacé).

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques**.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Installation vierge générique | base vide → `migrate` → `db:seed` (socle) → 1 admin, 1 filiale « Mon établissement », rôles socle, taxes FR, réglages par défaut, **0 article, 0 borne, 0 texte Cayenne** | **VRAI** |
| C2 | Le Cayenne reste installable | `db:seed --class=LeCayenne\\LeCayenneSeeder` sur le socle → menu identique à aujourd'hui (59 articles, profils publiés, prix identiques — ligne de base ONB-03) | **identique** |
| C3 | Zéro marque dans le code | `grep -rli cayenne app config resources/js routes` hors `Commands/LeCayenne/`, `seeders/LeCayenne/`, tests, docs → 0 ; sentinelle | **0** |
| C4 | Checklist | 7 étapes, état persisté par établissement, complétion calculée (pas déclarée), dismiss/reprise, liens vers les écrans réels | **7/7** |
| C5 | Installeur | `/install` (Blade) ou commande : un intégrateur installe en < 30 min avec le guide, sans éditer un fichier PHP | **VRAI** |
| C6 | Preuve de bout en bout | sur `foodking_onb12` : identité → catalogue (3 articles) → équipe → borne → commande borne → KDS → encaissement → Z → rapport = journée ONB-14 en réduit | **VRAI** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · `database/seeders/` = **94** fichiers ; `DatabaseSeeder.php` = 134 lignes (appels `:36-108`) ; `MenuSeeder.php` 845 ; `MenuResetLeCayenneCommand.php` 1 250 ; `InstallerController.php` 153 (`routes/web.php:22-33`) ; commandes menu = 12 ; « cayenne » = **147** fichiers (`app/ config/ resources/js/ database/ routes/`) + **11** (`resources/views`, `lang`, `resources/js/languages`) ;
`GrillHouseMenuSeeder.php:7-12` « DEPRECATED — DO NOT USE — BLOCKED » ; tests `tests/Feature/Security/InstallerAlreadyInstalledGuardTest.php`, `tests/Feature/Menu/MenuResetDriftGuardTest.php` (garde de dérive, sortie 2).

## §0.7 — Contradictions tranchées
- **C-CONST** — c'est **le** GOAL qui matérialise l'amendement : sans **G0**, il ne démarre pas (W0 vérifie la ligne dans `CONSTITUTION.md §1`).
- **C-GRILLHOUSE** — une seconde marque a déjà été tentée (`GrillHouseMenuSeeder`, bloquée). Tranché : lire pourquoi (docblock) avant de concevoir le socle ; ne pas répéter l'erreur (seeder de marque **remplaçant** au lieu de **socle + jeu de données**).
- **C-RESET** — `menu:reset-le-cayenne` est protégé par une garde de dérive (GOAL CAISSE PARFAITE S3, `MenuResetDriftGuardTest`) : c'est un outil de **Le Cayenne**, pas un outil d'installation. Tranché : archivé sous `Commands/LeCayenne/`, jamais appelé par l'installation.
- **C-SEED-PROD** — `DatabaseSeeder` mêle socle (permissions, devise, langue) et données (menu, bornes, textes). Tranché : deux arbres de seeders, un ordre documenté, `db:seed` = socle seul.
- **C-INSTALL** — installeur Blade `/install` (héritage SaaS : licence, site, base) vs commande artisan. Tranché : **les deux** (Blade pour l'intégrateur sans terminal, commande pour l'automatisation), même service.

## §0.8 — Le commerçant-type et ses questions
L'intégrateur : 1. « J'installe, et j'obtiens un restaurant vide ou celui de Le Cayenne ? » 2. « Je mets le nom du restaurant où, à l'installation ? » Nadia, lundi : 3. « Par où je commence ? » 4. « Pourquoi ma borne dit "tacos" ? » 5. « Si je clique "terminer l'étape", ça vérifie vraiment ? »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Installation | **INSTALLE LE CAYENNE** | `database/seeders/DatabaseSeeder.php:36-108` (`MenuTableSeeder`, `MenuTemplateTableSeeder`, `MenuSectionTableSeeder`, `PermissionTableSeeder`, `RoleTableSeeder`, `CompanyTableSeeder`, `LanguageTableSeeder`, `SiteTableSeeder`, `PaymentGatewayTableSeederVersionOne`, `SmsGatewayTableSeederVersionOne`, `CurrencyTableSeeder`, `BranchTableSeeder`, `UserTableSeeder`, `RolePermissionTableSeeder`, `ComposerPermissionsMinimalSeeder`, `IngredientPermissionSeeder`, `AvailabilityTogglePermissionSeeder`, `AdminWebGuardPermissionsSyncSeeder`, **`LeCayenneRoleLandingUrlSeeder`**, `MailTableSeeder`, `OrderSetupTableSeeder`, `OtpTableSeeder`, `NotificationTableSeeder`, `NotificationAlertTableSeeder`, `CookiesTableSeeder`, `ThemeTableSeeder`, `LicenseTableSeeder`, **`KioskMachineTableSeeder`**, `SocialMediaTableSeeder`, `AnalyticTableSeeder`, `TaxTableSeeder`, `PageTableSeeder`, `SliderTableSeeder`, **`MenuSeeder`** `:100`, `TimeSlotTableSeeder`, `PaymentGatewayDataTableSeeder`) · `InstallerController.php` (`routes/web.php:22-33`) · `app/Console/Commands/FiscalInstallImmutabilityTriggersCommand.php` | `InstallerAlreadyInstalledGuardTest` |
| S2 Checklist | **INEXISTANTE** | `DashboardComponent.vue` (accès rapide), spécification ONB-11 | (À CRÉER) |
| S3 Marque dans le code | **147 + 11 fichiers** | `config/menu.php` (restaurant `:24-30`, catégories `:47-65`, items `:162+`), `config/menu_images.php`, `config/kiosk.php:266-283` (BORNE), `config/printing.php:83,109,185` (CAISSE), `config/app.php:123,129` (comptes), `KioskIdleScreenComponent.vue` (BORNE), 12 commandes `Menu*`/`*Cayenne*`, seeders `AlignFritesWizardProfilesSeeder`, `CompleteFrenchMenuSeeder`, `ComposerSeeder`, `ItemCategoryWizardSeeder`, `LeCayenneAllergenSeeder`, `MenuEnfantChickenBurger20260707Seeder`, `OwnerMenuUpdate20260623Seeder`, `RestoreLeCayenne{DessertsAndDrinks,ItemImages}Seeder`, `WizardCayenneAndBolsCorrectionsSeeder`, `GrillHouseMenu{,Images}Seeder` (bloqués) | `MenuResetDriftGuardTest` |
| S4 Preuve | **AUCUNE INSTALLATION VIERGE JAMAIS FAITE** | `docs/DEPLOYMENT_GUIDE_V1.md`, `docs/GO_LIVE_RUNBOOK_LECAYENNE.md`, `docs/KIOSK_DEPLOYMENT.md` | (À CRÉER) |

**Sortie d'ancrage brute** : `grep -rli cayenne app config resources/js database routes | wc -l` → 147 · `… resources/views lang resources/js/languages` → 11 · `ls database/seeders | wc -l` → 94 · `ls app/Console/Commands | grep -i "menu\|cayenne\|seed"` → 12 · `wc -l DatabaseSeeder.php MenuSeeder.php MenuResetLeCayenneCommand.php InstallerController.php` → 134 / 845 / 1 250 / 153 · `head GrillHouseMenuSeeder.php` → « DEPRECATED - DO NOT USE - BLOCKED ».

# §2 — ÉTAT CONNU LE 2026-08-26
Une installation neuve = Le Cayenne (menu 59 articles, bornes `KM-*`, rôle-landing, textes borne, comptes `@lecayenne.fr`, 5 filiales de démonstration « Collier and Sons Branch »…, taxes polluées — ONB-02). Aucune checklist. Marque dans 158 fichiers. `GrillHouse` bloqué. Installeur Blade SaaS (licence, site, base). Mesures utiles : Z1 (aperçu avec filiales de démo), Z2 (borne « Composez votre tacos… »), Z7 (`kiosk-lecayenne`, afficheur « LE CAYENNE », ponts), Z3 (comptes).

# §3 — SOUS-SYSTÈME 1 : INSTALLATION VIERGE REPRODUCTIBLE

## Sub 1.1 — Socle vs jeu de données
**Ancrages** : `DatabaseSeeder.php:36-108`, seeders listés §1, `TaxTableSeeder` (ONB-02), `RolePermissionTableSeeder` (ONB-06).
**Tâches**
- **T-1.1.1** — Classer les 94 seeders : **socle** (permissions, rôles, devise EUR, langue FR, réglages par défaut, 1 filiale « Mon établissement », 1 admin à mot de passe imposé au premier login, taxes FR) / **données Le Cayenne** (menu, wizards, images, bornes, textes, rôle-landing, allergènes) / **démo/obsolète** (5 filiales « Collier… », `GrillHouse*`, `MenuTruncate*`) — table INVENTAIRE.
  • test : (À CRÉER à `tests/Feature/Onboarding/SeedersClassifiedSentinelTest.php` — tout seeder non classé = rouge)
- **T-1.1.2** — Deux arbres : `seeders/Socle/SocleSeeder.php` (ordre documenté, idempotent) et `seeders/LeCayenne/LeCayenneSeeder.php` (tout ce qui est marque) ; `DatabaseSeeder` = socle seul ; `db:seed --class=LeCayenne\\LeCayenneSeeder` reproduit l'état actuel (**C2** : ligne de base des 59 devis de ONB-03).
  • test : (À CRÉER à `tests/Feature/Onboarding/SocleSeedIsGenericTest.php`) + (À CRÉER à `tests/Feature/Onboarding/LeCayenneSeedReproducesMenuTest.php`) · C1, C2
  • au-delà : `db:seed` deux fois → idempotent ; socle sur une base déjà installée → refus (garde) ; `KioskMachineTableSeeder` hors socle.
- **T-1.1.3** — Commande `foodking:installer --etablissement="Chez Nadia" --admin=nadia@… ` (crée admin, filiale, réglages d'identité via ONB-01) + installeur Blade `/install` aligné (étapes : prérequis → base → établissement → admin → fin), `InstallerAlreadyInstalledGuardTest` conservé ; chaîne fiscale initialisée (`FiscalInstallImmutabilityTriggersCommand` appelé, non modifié).
  • test : (À CRÉER à `tests/Feature/Onboarding/InstallerCommandTest.php`) · C5
**Acceptation** : C1, C2, C5 · 4 tests VERTS · `docs/INSTALLATION_ETABLISSEMENT.md` écrit.

# §4 — SOUS-SYSTÈME 2 : CHECKLIST « PREMIER DÉMARRAGE »

**Ancrages** : spécification `docs/UX_PREMIERE_HEURE.md` (ONB-11), écrans cibles (01 identité, 02 catalogue, 03 règles, 06 équipe, 10 équipement, 14 commande test), `DashboardComponent.vue`.
**Tâches**
- **T-2.1.1** — Modèle : 7 étapes (Identité · Carte · Personnalisation · Équipe · Équipement · Commande test · Publication) ; complétion **calculée** (ex. Identité = SIRET + adresse + logo présents ; Carte ≥ 1 catégorie et 3 articles avec taxe ; Équipe ≥ 1 employé ; Équipement ≥ 1 borne installée ou 1 imprimante active ; Commande test = 1 commande de test marquée) ; persistance par établissement (`settings` ou table `onboarding_progress` — G-DATA) ; dismiss.
  • test : (À CRÉER à `tests/Feature/Onboarding/OnboardingProgressComputedTest.php`) · C4
- **T-2.1.2** — Composant Dashboard : carte en haut tant que < 7/7, étapes cliquables vers l'écran réel, état « fait / à faire / optionnel », reprise après rechargement, dismiss « ne plus afficher » (réversible dans Réglages).
  • test : (À CRÉER à `tests/js/onboardingChecklist.spec.js`) · visuel : `http://127.0.0.1:8812/admin/dashboard` à 3 gabarits
  • au-delà : étape « faite » puis donnée supprimée → redevient « à faire » ; deux onglets ; rôle caissier → carte invisible.
- **T-2.1.3** — « Commande test » : mode test qui **n'entre pas** dans la chaîne fiscale ? — **impossible sans gate** (NF525 : toute commande est fiscale) ; tranché : l'étape se valide par une **vraie** commande de 0,01 € annulée proprement, ou par le parcours ONB-14 ; G-TEST-ORDER.
**Acceptation** : C4 · 2 tests VERTS · questions 3, 5 = OUI.

# §5 — SOUS-SYSTÈME 3 : DÉ-CAYENNISATION

**Tâches**
- **T-3.1.1** — `INVENTAIRE_CAYENNE.md` : les 158 fichiers classés — **marque-dans-code** (à migrer vers réglage/donnée : identité, textes borne, afficheur, ticket, comptes, ponts), **donnée** (seeders, config menu → jeu de données), **test/doc** (acceptable), **archive** — avec GOAL/voie propriétaire et fiche de renvoi.
  • test : (À CRÉER à `tests/Feature/Sentinels/NoBrandInCodeSentinelTest.php` — cliquet : nombre de fichiers hors zones archivées ne remonte jamais) · C3 progressif
- **T-3.1.2** — Sa propre voie : `config/menu.php` (restaurant, catégories, items, sauces, viandes… → **jeu de données Le Cayenne**, plus lu par le code générique — coordination ONB-03 pour les inclusions), `config/menu_images.php` (repli neutre, ONB-02), commandes `Menu*`/`*Cayenne*` déplacées sous `Commands/LeCayenne/` (namespace, tests conservés, **jamais supprimées**), `FreshOrderSeed`, `MenuTruncateTableSeeder` (dangereux : garde « base dédiée uniquement »).
  • test : `tests/Feature/Menu/MenuResetDriftGuardTest.php` (existant, doit rester vert après déplacement) + (À CRÉER à `tests/Feature/Onboarding/LeCayenneCommandsArchivedTest.php`)
- **T-3.1.3** — Fiches de renvoi pour les fichiers d'autres voies (BORNE : `KioskIdleScreenComponent.vue`, `config/kiosk.php:266-283` ; CAISSE : `config/printing.php:83,109,185`, ponts ; ONB-01 : identité ; ONB-06/12 : comptes par défaut `config/app.php:123,129` ; ONB-10 : `kiosk-lecayenne`) — chacune avec le réglage cible.
**Acceptation** : inventaire commité · sentinelle VERTE au cliquet initial · commandes archivées · G-ARCHIVE tranché.

# §6 — SOUS-SYSTÈME 4 : PREUVE SUR BASE VIERGE

**Tâches**
- **T-4.1.1** — Sur `foodking_onb12` : installer (T-1.1.3) → checklist → identité « Chez Nadia » → 3 articles (dont 1 avec règles ONB-03) → 1 employé → 1 borne (lien d'installation ONB-10) → commande borne → KDS → encaissement → Z → rapport : Playwright + jumeau PHP réduit ; **zéro « Cayenne » à l'écran** (grep du DOM sur 12 pages).
  • test : (À CRÉER à `tests/e2e/onb12-installation-vierge.spec.js`) + (À CRÉER à `tests/Feature/Onboarding/InstallationViergeJourneeTest.php`) · C6
- **T-4.1.2** — Reprise : réinstaller sur la même base → refus (garde) ; réinstaller sur une base neuve → identique (idempotence du socle).
**Acceptation** : C6 · 2 tests VERTS · captures lues, 0 « Cayenne ».

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double exécution | deux onglets | rôle inférieur | données vides | volume | réseau coupé | effet borne / caisse / cuisine | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Installation | interruption à l'étape 3 → reprise | — | `db:seed` 2× idempotent | — | installeur déjà installé → refus (`InstallerAlreadyInstalledGuardTest`) | base vide | — | — | chaîne fiscale initialisée | jamais de désinstallation | nom d'établissement 190, accents, `"` |
| Seed Le Cayenne | — | — | idempotent | — | — | — | 59 articles | — | prix identiques (C2) | — | socle absent → refus |
| Checklist | dismiss réversible | état persisté | — | — | caissier : invisible | 0/7 honnête | — | — | — | donnée supprimée → étape rouvre | 7/7 puis suppression |
| Dé-cayennisation | — | — | — | — | — | — | 158 fichiers | — | borne/ticket sans marque en dur | archive restaurable | cliquet jamais remonté |
| Preuve vierge | — | — | rejouable | — | — | — | — | — | journée réduite | base neuve | — |

# §A — ARMÉE D'AGENTS
**Architecte** (socle vs données, ordre des seeders, idempotence) · **DBA** (seeders, base dédiée, chaîne fiscale à l'installation) · Sécurité (admin par défaut, mot de passe imposé, installeur exposé) · SRE (installation reproductible, commandes) · UX/A11y + **Psychologie commerçant** (checklist : complétion calculée, jamais culpabilisante) · Implémenteur unique · ROUGE (cherche un « Cayenne » à l'écran, une donnée Le Cayenne dans le socle, une suppression) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE/<round>/wave-<W>-<rôle>.json` ; contrat de constat.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, **G0 vérifié**, base dédiée créée, inventaire `cayenne` figé | séquentiel | **G0, G-DATA** |
| **W1** | Reconnaissance : lecture des 94 seeders, du `GrillHouse` bloqué, de l'installeur, de `MenuResetLeCayenneCommand` ; classement (T-1.1.1, T-3.1.1) | fan-out lecture seule | — |
| **W2** | S1 socle / Le Cayenne / installeur (T-1.1.2, T-1.1.3) | séquentiel | ONB-01/02/05/06 stabilisés |
| **W3** | S3 dé-cayennisation de sa voie + sentinelle + fiches (T-3.1.2, T-3.1.3) | séquentiel | G-ARCHIVE |
| **W4** | S2 checklist (T-2.*) | séquentiel | G-DATA (`onboarding_progress`), G-TEST-ORDER |
| **W5** | S4 preuve sur base vierge (T-4.*) | séquentiel | ONB-03/10 disponibles (sinon preuve réduite documentée) |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Onboarding|Seeder|Installer|Menu|Sentinels"`, Vitest, Playwright, BRAIN §6, `SYSTEM_MAP.md` | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement `CONSTITUTION.md §1` — **bloque ce GOAL** | Propriétaire | ligne + commit | `CONSTITUTION.md` | EN ATTENTE |
| **G-DATA** | Base dédiée `foodking_onb12` ; table `onboarding_progress` | Propriétaire | accord | `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque W0/W4 |
| **G-ARCHIVE** | Déplacement des commandes/seeders Cayenne sous `LeCayenne/` (jamais supprimés) | Propriétaire | accord | MISSION §6 | EN ATTENTE — bloque W3 |
| **G-TEST-ORDER** | Étape « Commande test » : vraie commande de 0,01 € annulée, ou parcours ONB-14 | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-2.1.3 |
| **G-SOCLE** | Contenu du socle (taxes FR, rôles socle ONB-06, réglages par défaut ONB-05, 1 filiale, admin à mot de passe imposé) | Propriétaire | validation de la liste | MISSION §6 | EN ATTENTE — bloque T-1.1.2 |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `CONSTITUTION.md §1` · `CLAUDE.md §3bis (SSOT, restore discipline), §8` · `SYSTEM_MAP.md` · `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md §0.2` · `_FICHES_GOAL.md` (ONB-12) · `recon/Z1..Z7` (§5 « Cayenne en dur ») ·
`plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md` (B5 intégral : 129 fichiers alors) · `plans/GOAL_CAISSE_PARFAITE_2026-08-22.md` (S3 garde `menu:reset`) · `docs/DEPLOYMENT_GUIDE_V1.md` · `docs/GO_LIVE_RUNBOOK_LECAYENNE.md` · `docs/KIOSK_DEPLOYMENT.md` · `database/seeders/GrillHouseMenuSeeder.php:7-12`.

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C6 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 10 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé 0 ; 5. NF525 : chaîne initialisée à l'installation, aucune donnée fiscale existante touchée ; 6. 5 gates tranchés ; 7. `CONSTITUTION.md` amendée (G0), BRAIN §6, `docs/INSTALLATION_ETABLISSEMENT.md` ; 8. deux cycles identiques ; 9. **rien de Le Cayenne supprimé** (preuve : `db:seed LeCayenne` reproduit l'état, C2).
**Interdit** : `migrate:fresh`/`db:seed` sur une base existante · supprimer un seeder/commande/donnée Le Cayenne · exécuter `menu:reset-le-cayenne` hors base dédiée · toucher `CONSTITUTION.md` (propriétaire seul) · approuver un gate.
> Le sens : dimanche, l'intégrateur installe « Chez Nadia » en une commande ; lundi, Nadia ouvre un Dashboard vide, propre, qui lui dit par où commencer — et Le Cayenne est toujours là, intact, dans son propre jeu de données.

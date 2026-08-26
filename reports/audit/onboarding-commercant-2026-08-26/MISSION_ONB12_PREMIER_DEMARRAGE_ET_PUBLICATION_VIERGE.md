# MISSION ONB-12 — PREMIER DÉMARRAGE & PUBLICATION VIERGE · Rapport de mission
- GOAL : `plans/GOAL_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`)
- Port : **8812** · Base **dédiée** `foodking_onb12` (exception, G-DATA) · Voie : TRANSVERSE (seeders, commandes menu, installeur, onboarding) · **Vague C** : après ONB-01, 02, 05, 06 et **G0**

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-12 (premier démarrage & publication vierge). AVANT TOUT : vérifie que CONSTITUTION.md §1 porte l'amendement G0 (index §0.2) ; sinon
STOP et demande-le au propriétaire — ce GOAL matérialise cet amendement. Lis : CONSTITUTION.md, CLAUDE.md §3bis (restore discipline) et §8, PROJECT_BRAIN.md §2,
SYSTEM_MAP.md, PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§0, §2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/
MISSION_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE.md, plans/GOAL_ONB12_PREMIER_DEMARRAGE_ET_PUBLICATION_VIERGE_2026-08-26.md, puis les §5 « Cayenne en dur »
de recon/Z1, Z2, Z3, Z7, database/seeders/DatabaseSeeder.php, database/seeders/GrillHouseMenuSeeder.php (docblock), plans/GOAL_OPS_RELIABILITY_SWAP_MULTIMARQUE_2026-08-12.md (B5).
Pré-vol §0.1 : worktree .claude/worktrees/onb12-vierge depuis HEAD, .env avec APP_URL=http://127.0.0.1:8812 ET DB_DATABASE=foodking_onb12 (base créée VIDE, gate G-DATA),
.env.testing, liens durs, serveur 8812, PLAYWRIGHT_BASE_URL, inventaire `git grep -il cayenne` figé. ⛔ Jamais migrate:fresh/db:seed sur foodking_e2e ou une base existante ;
jamais menu:reset-le-cayenne hors base dédiée ; jamais une suppression de seeder/commande/donnée Le Cayenne (déplacement seulement). Puis « lance le GOAL » : W0 → W1
(classement des 94 seeders, lecture de l'installeur et de MenuResetLeCayenneCommand) → W2 socle / jeu Le Cayenne / installeur → W3 dé-cayennisation + sentinelle + fiches →
W4 checklist → W5 preuve sur base vierge → W6. Pipeline ultra-audit-profond, Architecte + DBA en tête, implémenteur unique, ROUGE cherche un « Cayenne » à l'écran,
Jalonneur, matrice §S, deux cycles identiques. Jamais de push. Gates §G : proposer. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
« Publication vierge » : le propriétaire veut pouvoir livrer une installation neuve à un nouvel établissement. Aujourd'hui, une installation neuve **est** Le Cayenne (menu, bornes, textes,
comptes, filiales de démonstration). Ce GOAL sépare le socle des données, sort la marque du code, ajoute la checklist du premier jour, et le prouve sur une base vide. Il ne supprime rien :
Le Cayenne devient un jeu de données reproductible à l'identique (prix compris).

## 2. ÉTAT MESURÉ / CONNU LE 2026-08-26
| Fait | Preuve |
|---|---|
| `DatabaseSeeder.php` (134 l.) appelle `MenuSeeder` (845 l., menu Le Cayenne), `LeCayenneRoleLandingUrlSeeder`, `KioskMachineTableSeeder`, `CompanyTableSeeder`, `BranchTableSeeder`, … (`:36-108`) | `grep -n "::class" DatabaseSeeder.php` |
| 94 seeders dont Cayenne : `AlignFritesWizardProfilesSeeder`, `CompleteFrenchMenuSeeder`, `ComposerSeeder`, `ItemCategoryWizardSeeder`, `LeCayenneAllergenSeeder`, `LeCayenneRoleLandingUrlSeeder`, `MenuEnfantChickenBurger20260707Seeder`, `MenuSeeder`, `OwnerMenuUpdate20260623Seeder`, `RestoreLeCayenneDessertsAndDrinksSeeder`, `RestoreLeCayenneItemImagesSeeder`, `WizardCayenneAndBolsCorrectionsSeeder` ; `GrillHouseMenu{,Images}Seeder` **bloqués** (« DEPRECATED — DO NOT USE — BLOCKED », `:7-12`) | `ls database/seeders` |
| 12 commandes `Menu*`/`*Cayenne*` (`MenuResetLeCayenneCommand` 1 250 l., `ApplyLeCayenneV2Command`, `EnsureCayenneMixteCommand`, `EnsureKidsMenuStepsCommand`, `MenuHeal*`, `AssignMenuVatCommand`, `MenuCommand`, `FreshOrderSeed`, `FiscalInstallImmutabilityTriggersCommand` (fiscal, à garder)) | `ls app/Console/Commands` |
| « cayenne » : **147** fichiers (`app config resources/js database routes`) + **11** (`resources/views lang resources/js/languages`) ; le 12/08 : 129 | `grep -rli` 26/08 |
| Installeur Blade `/install` : licence → site → base → final ; `InstallerController` 153 l. ; garde `InstallerAlreadyInstalledGuardTest` | `routes/web.php:22-33` |
| Marque visible mesurée : borne « Composez votre tacos… », « Le Cayenne », « 100% HALAL » (Z2) ; `kiosk-lecayenne`, afficheur « LE CAYENNE », `RECEIPT_WEBSITE=lecayenne.fr`, ponts « Le Cayenne — Sanei SK1-31 », `KIOSK-LC-001`, « TPE Le Cayenne #1 » (Z7) ; aperçu article avec 5 filiales de démo « Collier and Sons Branch »… (Z1) ; comptes `@lecayenne.fr` (`config/app.php:123,129`) (Z3) | recon |
| Taxes : 53 lignes dont 47 parasites (ONB-02) | SQL 26/08 |
| Garde de dérive du menu : `menu:reset-le-cayenne` sortie 2 (`MenuResetDriftGuardTest`) | GOAL CAISSE PARFAITE S3 |
| Aucune checklist, aucun parcours guidé (grep `onboarding|setup-wizard|premier démarrage` → rien) | Z0 §8 |

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-05-13 ULTRA-PLAN « Menu Reset Le Cayenne » (clos) ; 2026-08-12 B5 « swap multi-marque » planifié (jamais exécuté) avec la distinction paramétrer ≠ multi-tenant ; `GrillHouse` : tentative bloquée (lire le motif).
- `docs/DEPLOYMENT_GUIDE_V1.md`, `docs/GO_LIVE_RUNBOOK_LECAYENNE.md`, `docs/KIOSK_DEPLOYMENT.md` : guides **pour Le Cayenne**.
- ONB-01/02/05/06 livrent le modèle d'identité, les taxes FR, les réglages typés, les rôles socle : ce GOAL les **assemble**.

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Seeder racine | `database/seeders/DatabaseSeeder.php` | `:26` classe, `:36-108` appels (`MenuSeeder :100`), `:93` commentaire GrillHouse | à scinder |
| Menu Cayenne | `database/seeders/MenuSeeder.php` (845 l.) · `config/menu.php` (`restaurant :24-30`, `categories :47-65`, `settings :72-85`, `meats :92-97`, `sauces :107-121`, `crudites :129-134`, `supplements :141-148`, `items :162+`) · `config/menu_images.php` | | jeu de données |
| Commandes | `app/Console/Commands/{MenuResetLeCayenneCommand (1 250 l.),ApplyLeCayenneV2Command,EnsureCayenneMixteCommand,EnsureKidsMenuStepsCommand,MenuHealLightV2Command,MenuHealLightV2Round2PatchCommand,MenuHealLightV3Command,MenuHealLightV31BurgerCommand,MenuCommand,AssignMenuVatCommand,FreshOrderSeed}.php` · `FiscalInstallImmutabilityTriggersCommand.php` (fiscal, socle) · `EXECUTE_MENU_FIX.sh:15-20` | | archivage |
| Installeur | `app/Http/Controllers/Installer/InstallerController.php` (153 l.) · `routes/web.php:22-33` · vues installer | | |
| Bornes/comptes | `database/seeders/KioskMachineTableSeeder.php` · `UserTableSeeder.php` · `config/app.php:123,129` · `config/kiosk.php:266-283` (BORNE) · `EnsureKioskMachineCommand.php:24,141` (ONB-10) | | fiches |
| Caisse/ticket | `config/printing.php:83,109,185` · `tools/{borne,caisse-bridge,kitchen-bridge}/` (CAISSE/BORNE) | | fiches |
| Borne accueil | `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (non gelé, BORNE / ONB-01) | | fiche |
| Tests | `tests/Feature/Security/InstallerAlreadyInstalledGuardTest.php` · `tests/Feature/Menu/MenuResetDriftGuardTest.php` (+ 31 autres `Menu/`) | | |
| À créer | `database/seeders/Socle/**`, `database/seeders/LeCayenne/**`, `app/Console/Commands/FoodkingInstallerEtablissementCommand.php`, `app/Console/Commands/LeCayenne/**` (archive), `app/Http/Controllers/Admin/OnboardingController.php`, `app/Services/Onboarding/OnboardingProgressService.php`, `admin/onboarding/{OnboardingChecklistComponent,OnboardingStepCard}.vue`, `onboardingRoutes.js`, `docs/INSTALLATION_ETABLISSEMENT.md`, `INVENTAIRE_CAYENNE.md`, tests `tests/Feature/Onboarding/*`, `tests/Feature/Sentinels/NoBrandInCodeSentinelTest.php`, `tests/e2e/onb12-installation-vierge.spec.js` | | |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Menu|Installer|Sentinels"` → figer W0 · « cayenne » 147 + 11 (cliquet initial) · seeders 94 · ligne de base des 59 devis (ONB-03, réutilisée pour C2).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| **G0** | Amendement constitutionnel | oui | **GOAL bloqué** |
| G-DATA | Base dédiée `foodking_onb12` ; table `onboarding_progress` | oui | preuve impossible |
| G-SOCLE | Contenu du socle (taxes FR 5,5/10/20/0, rôles Gérant/Caissier/Cuisine/Livreur, réglages par défaut, 1 filiale « Mon établissement », admin à mot de passe imposé) | liste proposée | W2 bloquée |
| G-ARCHIVE | Commandes/seeders Cayenne sous `LeCayenne/` | oui | marque reste dans le code |
| G-TEST-ORDER | Étape « Commande test » : vraie commande 0,01 € annulée, ou parcours ONB-14 | parcours ONB-14 | étape optionnelle |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- **Restore discipline** (CLAUDE.md §3bis) : ne jamais restaurer/réinitialiser une base sans vérifier laquelle ; ici, seule `foodking_onb12`.
- `MenuTruncateTableSeeder`, `EXECUTE_MENU_FIX.sh`, `menu:reset-le-cayenne` sont destructeurs : garde « base dédiée uniquement » avant tout usage.
- `GrillHouse` a échoué en **remplaçant** la marque au lieu d'ajouter un socle : lire le docblock avant de concevoir.
- Le socle doit initialiser la chaîne fiscale (`FiscalInstallImmutabilityTriggersCommand`, triggers MySQL) : prouver sur MySQL, pas sqlite.
- Les fichiers d'autres voies (borne, caisse, kiosk config) ne se modifient pas ici : fiches.
- `:8000` = autre worktree ; ta session = **:8812**, base **`foodking_onb12`**.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | G0 vérifié ? | | | | |

Fiches de renvoi : BORNE (`KioskIdleScreenComponent.vue`, `config/kiosk.php:266-283`) · CAISSE (`config/printing.php:83,109,185`, ponts, afficheur) · ONB-01 (identité par défaut du socle) · ONB-02 (`TaxTableSeeder`, `config/menu_images.php`) · ONB-03 (inclusions `config/menu.php` → règles) · ONB-06 (rôles socle, comptes `config/app.php:123,129`) · ONB-10 (`kiosk-lecayenne`, `EnsureKioskMachineCommand`) · ONB-11 (spécification de la checklist) · ONB-14 (parcours sur base vierge) · État final : —

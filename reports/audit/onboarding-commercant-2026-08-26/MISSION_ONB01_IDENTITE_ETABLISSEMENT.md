# MISSION ONB-01 — IDENTITÉ DE L'ÉTABLISSEMENT · Rapport de mission
- GOAL : `plans/GOAL_ONB01_IDENTITE_ETABLISSEMENT_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, arbre principal servi sur `:8766`, base locale `foodking_e2e`)
- Port de session attribué : **8801** · Voie : CENTRAL, sous-voie « identité & apparence » · Sessions compatibles en parallèle : ONB-02, 05, 06, 07, 08, 09, 10 (vague A) ; audits 11 et 13

## 0. COMMENT LANCER (à coller tel quel dans une nouvelle session Claude Code ouverte sur l'arbre principal)
```
Tu es le chef de mission du GOAL ONB-01 (identité de l'établissement). Lis dans l'ordre : CONSTITUTION.md, PROJECT_BRAIN.md §2,
SYSTEM_MAP.md, PARALLEL_PROTOCOL.md, plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5),
reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB01_IDENTITE_ETABLISSEMENT.md, puis
plans/GOAL_ONB01_IDENTITE_ETABLISSEMENT_2026-08-26.md, puis reports/audit/onboarding-commercant-2026-08-26/recon/Z2_profil_reglages.md.
Pré-vol §0.1 : worktree .claude/worktrees/onb01-identite créé DEPUIS HEAD (jamais origin/main), .env copié avec APP_URL=http://127.0.0.1:8801,
.env.testing copié, vendor/ et node_modules/ en liens durs (jamais symlink), serveur sur 8801, PLAYWRIGHT_BASE_URL=http://127.0.0.1:8801,
filet backup/pre-onb01-2026-08-26 + dump settings/branches. ⛔ N'enregistre JAMAIS les pages Entreprise/Site sur :8766 (elles écrivent le .env).
Puis « lance le GOAL » : W0 → W1 (rejoue /Users/1millnonstop/.claude/jobs/06c6b42a/tmp/recon/Z2/02-api.js et 04-pass2.js sur 8801 si le dossier
existe encore, sinon réécris-les depuis recon/Z2) → W2..W6. Pipeline ultra-audit-profond par tâche, 5 spécialistes lecture seule en un message,
un seul implémenteur, ROUGE avant tout « fini », Jalonneur à chaque point de contrôle §X.8, matrice §S obligatoire, convergence = deux cycles
consécutifs P0+P1=0 aux constats identiques. Tu possèdes uniquement les fichiers du §0.2 ; toute autre modification (menu, taxes, rôles, bornes)
est une fiche de renvoi dans ce rapport §8. Base partagée : préfixe GOAL-ONB01, jamais migrate:fresh, tests via
~/.claude/skills/brain/scripts/safe-test.sh. Jamais de push. Gates §G : tu proposes, tu ne tranches pas. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Mandat propriétaire 2026-08-26 : un nouvel établissement doit partir de zéro et contrôler chaque détail depuis le Dashboard, sans développeur.
L'identité (nom, logo, SIRET, TVA, adresse, horaires, mentions, devise, couleurs) est la **première heure** de ce commerçant : si elle est
éclatée ou inopérante, tout ce qui suit (ticket, borne, rapports) est faux. Ce GOAL est en **vague A** de l'index (aucune dépendance) et livre à
ONB-12 (installation vierge) le modèle de données d'identité. Persona : Nadia (kebab-burger, Lyon, seule devant l'écran).

## 2. ÉTAT MESURÉ LE 2026-08-26 (reconnaissance web réelle Z2 — `recon/Z2_profil_reglages.md`, `tmp/recon/Z2/*.json`)
**2.1 Périmètre** : 25 URL Réglages ouvertes en Admin à 1366×768 → 25/25 en 200, 0 réponse ≥ 400, 0 libellé brut ; 57 appels API (`02-api.json`) ;
second passage navigateur (`04-pass2.json`) ; 6 captures `recon/screens/Z2/`.
**2.2 Ce qui marche** : propagation immédiate d'un réglage (temps de préparation 30→31 relu dans `GET /api/frontend/setting` sans cache, restauré) ;
titre borne modifié visible sur `/kiosk/idle` en 706 ms ; anti-injection `.env` (`\n`, `"` → 422, `CompanyRequest.php:33`, `SiteRequest.php:36-41`) ;
validations FR (doublon, ville vide, PIN 3 chiffres, titre 101 car., créneaux) ; filiale par défaut non supprimable (`BranchService.php:89-92`) ;
`pos@` 403 sur toutes les écritures ; Interrupteurs lisibles.
**2.3 Constats** (contrat complet dans Z2 §3) :
| Sév. | Constat | Preuve |
|---|---|---|
| P1 | Champs `siret`, `vat_intra`, `register_id`, `legal_footer`, `delivery_fee_*` de la filiale **jamais enregistrés** (API 200 sans ces clés, base NULL, formulaire à 11 champs sans SIRET) | `04-pass2.json branch_fiscal_fields_*`, `03-captures.json branch_create_modal`, capture `03-filiale-modale-creation.png` ; `grep siret BranchRequest.php BranchService.php` = vide |
| P1 | Page **Site inenregistrable** telle quelle : `site_google_map_key` et `site_copyright` obligatoires et vides | `04-pass2.json site_ui_submit_as_is`, capture `02b-site-enregistrer-refuse-cle-google.png`, `SiteRequest.php:52,55` |
| P1 | Zone de livraison : `DrawingManager` retiré de l'API Google Maps (v3.65) — seule erreur JS des 25 pages | `03-captures.json errors[branch-zone]` |
| P1 | Page Licence : la « clé de licence » affichée en clair est la clé d'API `X-API-KEY` (38 car.) | `03-captures.json license_page`, `02-api.json admin_get license` → ONB-05 (retrait) / ONB-13 |
| P2 | Lecture de `company`, `site`, `order-setup`, `branch`, `otp`, `theme`, `interrupteurs` ouverte au POS Operator (écriture 403) | `02-api.json pos_get *` → ONB-13 |
| P2 | Borne : « Composez votre tacos comme vous l'aimez », « Le Cayenne », « 100% HALAL » en dur (`KioskIdleScreenComponent.vue`, non gelé) | `03-captures.json kiosk_idle_after_title_change` |
| P2 | Toasts anglais (« Filiales Created Successfully. », « … Updated Successfully. ») | `04-pass2.json`, `03-captures.json` → ONB-11 |
| P3 | Site : 16 champs obligatoires au vocabulaire SaaS (passerelle en ligne, connexion invité, liens d'apps) | `03-captures.json site_form` |
**2.4 Angles morts** : aucun écran pour horaires / jours fermés, SIRET/TVA/mention (colonnes sans formulaire), barème (idem), couleurs, langue ; deux « identités »
(Entreprise vs Filiale) sans explication de ce qui s'imprime. **2.5 Cayenne en dur** : textes borne ; 5 filiales de démonstration ; `admin@lecayenne.fr`.
**2.6 Chiffres** : `branches` = 6 ; `settings company_name = "Le Cayenne"` ; `kiosk_welcome_title = "Bienvenue !"` ; `order_setup_food_preparation_time = "30"`.

## 3. CE QUI A DÉJÀ ÉTÉ FAIT (ne pas refaire)
- 2026-08-13 `GOAL_ADMIN_NAV_BREADTH_CONVERGENCE` : 70/71 pages prouvées ouvrables ; tâches Réglages **jamais exécutées** : SET-T01 (Entreprise round-trip),
  SET-T05 (garde injection nom — finalement en place : `CompanyRequest.php:33`), SET-T06 (pages orphelines), SET-N05 Thème, N06 Réseaux sociaux, N07 Site, N11 Cookies, N12 Créneaux.
- Tests existants `tests/Feature/Settings/` (7) : `FrenchValidationMessagesAreTranslatedTest`, `MailLicenseEnvInjectionGuardTest`, `MessageControllerNoDeadUpdateRouteTest`,
  `OrphanSettingsRatchetSentinelTest`, `PaymentGatewaySecretExposureTest`, `SettingsUpdatedBroadcastTest`, `TimeSlotOverlapGuardTest` ; `tests/Feature/Receipt/ReceiptDataServiceWireInTest.php`.
- 2026-08-15 V5 : tuiles dashboard, interrupteurs 2→6 ; 2026-08-25 : sondes de santé honnêtes. Rien sur l'identité.
- Décisions propriétaire en vigueur : locale FR verrouillée (ADR-007) ; palette Cayenne = défaut (CLAUDE.md §3bis) ; TPE simulé (CONSTITUTION §2).

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes clés | Note |
|---|---|---|---|
| Filiale — modèle | `app/Models/Branch.php` | `:14-52` fillable (siret, vat_intra, register_id, legal_footer, delivery_fee_*) | colonnes de `2026_04_20_210000_add_fiscal_identity_to_branches.php` |
| Filiale — requête / service | `app/Http/Requests/BranchRequest.php`, `app/Services/BranchService.php` | aucun champ fiscal ; `:89-92` défaut non supprimable | **le P1** |
| Filiale — UI | `settings/Branch/{BranchComponent,BranchListComponent,BranchCreateComponent,BranchShowComponent}.vue` | modale 11 champs ; onglet Zone (Maps) | route `settingRoutes.js:91-125` |
| Entreprise | `CompanyController.php:19,36` · `CompanyRequest.php:29-42` (regex `:33`) · `settings/Company/CompanyComponent.vue` · `CompanyTableSeeder.php` | `SettingsUpdated::dispatch(['company'])` | écrit `APP_NAME` dans `.env` |
| Site | `SiteController.php` · `SiteRequest.php:31,36-41,52,55` · `app/Services/SiteService.php` · `settings/Site/SiteComponent.vue` | clé Maps + copyright `required` | écrit `.env` verbatim |
| Ticket | `app/Services/Receipt/ReceiptDataService.php` · `app/Services/Hardware/OrderReceiptEscPosRenderer.php` · `config/printing.php:83,109,185` | `legal_footer`, `siret` lus ; `RECEIPT_WEBSITE/PHONE`, « LE CAYENNE » | ce qui s'imprime vraiment |
| Thème | `ThemeRequest.php:33-37` · `ThemeController.php` · `settings/Theme/ThemeComponent.vue` · `BackendMenuComponent.vue:6,9` | 3 logos, aucune couleur | cachée (`v1-hidden-modules.js:34`) |
| Créneaux | `TimeSlotController` (`routes/api.php:643-647`) · `TimeSlotRequest.php` · `settings/TimeSlot/TimeSlotListComponent.vue` | chevauchement 422 mesuré | cachée |
| Borne accueil | `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` | textes en dur | **non gelé**, voie BORNE (coordination) |
| Date métier | `app/Console/Kernel.php:495-549` | Z 23:59 / 00:01 Europe/Paris | ne pas toucher |
| Devise / Langue | `CurrencyController` (`api.php:497-503`) · `LanguageController` (`:649-659`) | taux négatif 422 mesuré | FR verrouillé |

## 5. BASES CHIFFRÉES À NE PAS DÉGRADER
`safe-test.sh --phpunit "Settings|Branch|Company|Site|Theme|Receipt|Hardware"` → compter et figer en W0 (base globale PHPUnit 5 194, Vitest 3 644, gelé 0, NF525 8 119 ajout seul).
Temps de chargement mesurés Z2 : les 25 pages en < 1 s (serveur de dev) ; `/api/frontend/menu` 731 ms / 799 requêtes SQL (hors périmètre, à signaler à ONB-02).

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Options | Recommandation | Si non tranché |
|---|---|---|---|---|
| G-ID | Un écran « Mon établissement » composite ? | A composite · B deux écrans liés | **A** | T-1.3.2 bloquée ; T-1.2.* avancent |
| G-DATA | Créer `branch_opening_hours` + `branch_closures` ? | oui / réutiliser `time_slots` | **nouvelles tables** | W4 bloquée |
| G-ENV | Migrer les réglages Site/Entreprise du `.env` vers `settings` ? | oui / non (nullable seulement) | oui, après inventaire | seul l'inventaire est fait |
| G-ZONE | Zone de livraison | A polygone GeoJSON · B rayon km · C retirer | **B** | onglet Zone reste en erreur |
| G-LOGO-BORNE | Éditer `KioskIdleScreenComponent.vue` depuis ce GOAL (voie BORNE) | oui / renvoi à une session BORNE | oui, coordination déclarée | textes Cayenne restent en dur |
| G0 | Amendement constitutionnel (index) | — | — | ne bloque pas ce GOAL |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- `:8000` = autre worktree ; `:8766` = arbre principal ; ta session = **:8801**. Garde d'identité Playwright (`tests/Playwright/global-setup.js`).
- Entreprise/Site écrivent le `.env` : sur le worktree, c'est la copie ; ne jamais tester l'enregistrement sur `:8766`.
- `vendor/` symlinké = code du worktree jamais exécuté ; `.env.testing` absent = ~336 rouges fantômes.
- Cache Spatie/permissions et cache settings : mesuré sans cache (`no-cache`) pour `frontend/setting` ; vérifier après chaque nouveau réglage.
- Un 200 n'est pas une preuve : le P1 filiale répond 200 en ignorant les champs — relire la base.
- Serveur de dev mono-requête : ≤ 4 navigateurs, timeouts 60 s.
- Ne pas confondre `time_slots` (créneaux de commande) et horaires d'ouverture (à créer).
- Filiales de démonstration (5) : ne pas les supprimer ici (ONB-12).

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Constats nouveaux : — · Décisions prises : — · Fichiers touchés : — · Demandes vers d'autres GOAL (fiches de renvoi) : ONB-05 (dé-cacher Thème, Créneaux/Horaires, retirer les 3 champs de frais d'OrderSetup, entrée de menu Horaires) · ONB-11 (« Filiales » → « Points de vente », toasts anglais) · ONB-12 (archivage des 5 filiales de démonstration) · ONB-13 (lecture des réglages par `pos@`, clé d'API sur Licence) · État final : —

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

### 8.1 ÉTAT : **AVANCÉ HORS GATE** — le paramétrage est fait, la constitution reste au propriétaire

`plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md §0.2` pose **G0** : réécrire la
phrase de `CONSTITUTION.md §1` — « V1 = LOGICIEL PERSONNEL du restaurant Le Cayenne.
PAS un SaaS. » — en « V1 = logiciel d'un établissement, entièrement paramétrable ».

**Vérifié : G0 est absent de `CONSTITUTION.md` sur toutes les branches.** Seul le
propriétaire peut le signer.

Mais **§0.2 autorise explicitement le paramétrage sans G0** — il sert aussi
l'établissement actuel. C'est ce périmètre qui a avancé le 2026-08-28, et il n'était pas
mince.

### 8.2 La borne ne montre plus la carte d'un autre établissement

**Huit produits de Le Cayenne étaient écrits en dur** dans `KioskIdleScreenComponent.vue`,
avec leurs photos. Un nouveau commerçant ouvrait sa borne sur des burgers qu'il ne vend
pas, et aucun écran ne permettait de les changer.

La vitrine vient désormais de `frontend/item/featured-items` : les produits que le
commerçant a **lui-même** mis en avant. Elle démarre vide, et le carrousel disparaît tant
qu'elle l'est — **mieux vaut un écran sobre que la vitrine d'un autre établissement**.

Ce n'est pas une perte pour l'installation existante : elle a **40 produits mis en avant,
tous avec photo** (vérifié en lecture). Sa borne montrera sa carte réelle au lieu d'un
instantané figé de huit articles.

### 8.3 La borne n'affirme plus rien à la place du commerçant

Le tampon **« 100 % Halal »** était écrit en dur dans le gabarit. C'est une affirmation
sur la nourriture servie — vérifiable, engageante, propre à chaque établissement. Tout
commerçant installant le produit la portait **sans l'avoir dite**, et sans moyen de la
retirer.

Elle devient le réglage `kiosk_halal_stamp`, **éteint par défaut**, avec sa case dans
l'écran de réglage borne.

**Le point délicat, et il méritait de la prudence :** éteindre par défaut retirerait le
tampon, sans prévenir, à un établissement qui l'affiche aujourd'hui et pour qui il est
vrai. La migration **déclare** donc sa réalité au lieu de la nier — tampon posé si
l'installation a déjà une carte, éteint si elle est vierge. Le critère est écrit dans la
migration plutôt que deviné plus tard, et le banc le vérifie **dans les deux sens** :
sans cela, on ne saurait pas si la migration décide ou si elle écrit toujours la même
chose.

Un troisième cas est couvert : une migration de valeur par défaut écrase volontiers le
choix qu'elle prétend initialiser. Celle-ci ne réécrit pas un réglage déjà posé.

### 8.4 Et surtout : l'écran, pas seulement la donnée

Rendre le tampon configurable ne servirait à rien sans écran pour le décider. C'est le
motif que cette semaine a fait apparaître **cinq fois** — allergènes, poste de cuisine,
matières premières, seuils d'alerte, horaires : *une chaîne complète sauf l'endroit où un
humain saisit la vérité*.

`LeCommercantPeutDeclarerOuRetirerSonTamponTest` refuse de laisser le tampon devenir le
sixième. Il vérifie les quatre maillons dans l'ordre où le commerçant les rencontre : la
règle accepte, la base conserve, **la relecture renvoie**, la borne lit. Le troisième est
celui qu'on oublie — sans lui l'écran rouvre sur « non déclaré » et le prochain
enregistrement efface le choix. C'est le défaut exact corrigé sur l'identité fiscale.

Le sens inverse est couvert aussi : **une affirmation qu'on ne peut plus retirer n'est
pas un réglage, c'est un piège.**

### 8.5 Audit visuel de la borne — et ce qu'il a révélé

`CLAUDE.md §6` rend la vérification visuelle obligatoire dès qu'un écran est touché.
Faite le 2026-08-28 sur `http://127.0.0.1:8800/kiosk/idle`, en mesurant le DOM et le
réseau plutôt qu'en se fiant à l'allure générale.

**Le risque réel du changement était le suivant** : le carrousel passait d'une liste en
dur à un appel réseau. Si `frontend/item/featured-items` avait exigé un jeton que l'écran
d'accueil n'a pas, la vitrine aurait été **définitivement vide en production** — un
correctif pire que le défaut. Mesure : `200 /api/frontend/item/featured-items`. La route
est publique, l'appel aboutit, huit diapositives portent les produits réels du
commerçant avec leurs photos.

Écran intact par ailleurs : logo, accroche « NOS INCONTOURNABLES », libellé produit,
huit puces de progression, appel à l'action, français résolu, aucun libellé brut. Le
tampon halal est absent — cohérent, la migration n'a pas encore été jouée sur cette base.

Deux `401` observés (`frontend/kiosk-event`, `login`) : la télémétrie borne et sa propre
authentification, absentes en capture sans appareil déclaré. **Préexistants, sans rapport
avec ce changement.**

L'écran de connexion a été capturé aussi, parce que retoucher `fr.json` peut casser
toute l'application d'un seul caractère : français résolu, aucune clé brute, aucune
erreur console.

### 8.6 ⚠️ RÉSIDU DE TEST VISIBLE PAR LES CLIENTS — constat, pas correctif

L'audit visuel a fait apparaître, dans la rotation de la borne :

> **`E2E_PLAYWRIGHT_STUDIO_ITEM`** — `items.id = 161`, `status = 5` (ACTIF),
> `is_featured = 5` (OUI), `deleted_at = NULL`.

Ce n'est **pas** une conséquence de ce changement : l'article était déjà actif et mis en
avant, donc **déjà visible aux clients dans le menu de la borne**. Le changement le rend
seulement plus visible, en le faisant tourner sur l'écran d'accueil.

Deux autres résidus sont inertes car inactifs (`status = 10`) : `Burger borne
AUDIT-KIOSK-MULTI CE2EE3` (#202) et `Dessert borne AUDIT-KIOSK-MULTI CE2EE3` (#203).

**Même famille que les 12 bornes `KM-STRESS-*` / `KM-SOAK-*` sur 13, les cinq filiales
fictives et les 26 taxes `AUDIT-*`** déjà recensées. Le nettoyage est une **décision
propriétaire** : supprimer une donnée en service ne se fait pas sans son accord, et
filtrer sur un motif de nom dans le code masquerait aussi de vrais produits nommés
bizarrement. Consigné ici pour qu'il ne se perde pas.

### 8.7 DEUX AUDITS ADVERSES — ce qu'ils ont trouvé contre moi

Deux agents lancés en lecture seule sur mes propres commits du jour. Ils ont trouvé
**sept défauts réels**, dont un que je n'avais pas vu et qui manquait l'objectif même
de la mission. Tout est corrigé et prouvé ; c'est consigné ici parce que le motif
compte plus que les correctifs.

#### 1. Le logo déposé était toujours en dur — et mon cliquet annonçait « 0 »

`brandLogo: ATTRACT_BASE + 'logo.webp'` n'était **jamais** réassigné. La marque
déposée « LE CAYENNE ® », mascotte et baseline comprises, restait **l'élément le plus
grand de l'écran client** de tout nouveau commerçant — après un commit intitulé « la
borne montre la carte du commerçant ».

Et mon propre cliquet rendait « 0 marqueur », parce que sa regex cherchait
`cayenne.webp` et pas `logo.webp`. **Une sentinelle au mauvais périmètre, la mienne.**
C'est le motif que je documente depuis des jours, et je venais de le reproduire dans
le banc censé le prévenir. Un cliquet qui rend « 0 » en ne regardant pas l'essentiel
est pire qu'aucun cliquet : il rassure.

Le cliquet surveille désormais **tout actif servi depuis `/images/kiosk-attract/`** —
c'est le dossier entier qui porte l'identité d'un établissement précis.

Conséquence secondaire relevée par l'audit : `brandLogo` étant toujours vrai, le repli
`<h1 v-else>{{ restaurantName }}</h1>` était **du code mort**. Le nom du commerçant ne
pouvait jamais s'afficher.

#### 2. Mon filtre d'images ne filtrait rien

`.filter(i => i.thumb)` était censé écarter les produits sans photo. Mais
`Item::getThumbAttribute()` renvoie **toujours** une chaîne, et retombe sur
`item-default.svg` — un carré gris 200×200. Ce substitut s'affichait donc **plein cadre
(900×884), agrandi et animé**, sur l'écran client. Mon commentaire au-dessus du filtre
(« on ne garde que ceux qui ont une photo ») était factuellement faux.

L'audit a aussi mesuré la résolution : les vignettes font 320×320, parfois 168×180,
pour un cadre de 900 px — un étirement de 3× à 5×. La vitrine prend désormais `cover`
(pleine taille) et écarte explicitement les trois substituts connus.

Effet de bord vérifié en capture : `E2E_PLAYWRIGHT_STUDIO_ITEM`, résidu de test actif
et mis en avant, **a disparu de la rotation** — il n'a pas de vraie photo.

#### 3. L'état vide promettait des produits au-dessus d'un trou

Le gabarit est en positionnement absolu. Masquer la vitrine sans masquer sa légende
laissait « Nos incontournables » suivi d'environ **1020 px de fond vide**. La légende
part maintenant avec la vitrine.

#### 4. Mes bancs prouvaient du texte, pas du comportement

Huit bancs lisant le fichier source avec `fs.readFileSync`, en 5 ms. L'audit a désigné
la mutation qu'ils ne voyaient pas : **supprimer `this.chargerLaVitrine()`** du
montage. Le carrousel resterait vide à vie ; les huit restaient verts.

Expérience faite : appel retiré → **11 bancs textuels verts, 3 bancs de comportement
rouges**. Un banc qui lit du texte prouve qu'une ligne existe, jamais qu'elle
s'exécute. `laVitrineDeLaBorneSeChargeVraiment.spec.js` monte le composant.

#### 5. Le maillon « la borne lit » n'était pas couvert

Mon docblock annonçait quatre maillons. Il y avait trois tests, tous sur
`KioskSetupResource` (l'écran d'administration). La borne, elle, lit `SettingResource`.
Supprimer cette ligne-là laissait **3 verts PHP et 8 verts JS** pendant que le tampon
ne s'affichait plus jamais. Chaîne refermée d'un côté, rouverte de l'autre.

#### 6. La migration écrivait un format que le paquet ne produit jamais

Le paquet enveloppe les valeurs (`{"$value": .., "$cast": null}`). J'écrivais un
scalaire nu : ça ne cassait rien **par accident**. Pire, mon lecteur de test faisait
`(int) json_decode(...)` sur l'enveloppe — ce qui rend **1** pour un tableau non vide.
Il annonçait donc « tampon déclaré » pour un commerçant qui l'avait **éteint**. Le banc
aurait validé l'inverse de ce qu'il prétendait mesurer.

Migration et banc passent désormais par `Settings::group()->set()`.

#### 7. Ma correction du logo a dégradé l'écran de l'établissement en service

Trouvé par **mon propre audit visuel après correction** : en remplaçant le logo en dur
par le logo général des réglages, l'établissement existant se retrouvait avec un logo
sur fond blanc posé sur le fond orange de l'attract. J'avais réglé un problème de
marque pour les futurs commerçants et abîmé l'écran de l'actuel.

Même remède que pour le tampon : un réglage **dédié** (`kiosk_attract_logo`), trois
crans de repli (dédié → général → nom en toutes lettres), et la migration qui déclare
le visuel que l'installation en service utilise déjà.

---

**Ce que je retiens.** Les deux audits n'ont pas trouvé des broutilles : ils ont trouvé
que **le commit manquait son objectif principal** sur l'élément le plus visible de
l'écran, pendant que mon propre banc annonçait la victoire. Sans eux, la mission aurait
été déclarée avancée sur la foi d'un cliquet aveugle.

### 8.5 Ce qui reste, et qui exige la signature

- **G0 lui-même.** Tant qu'il n'est pas signé, aucun GOAL ne touche `CONSTITUTION.md` et
  aucun ne remonte multi-marque comme bloquant.
- Seeders génériques, checklist « Premier démarrage », archivage des commandes `menu:*`.
- **12 bornes sur 13 en base sont des résidus de tests de charge** (`KM-STRESS-*`,
  `KM-SOAK-*`), même famille que les cinq filiales fictives et les 26 taxes `AUDIT-*`.
  Un nouveau commerçant ne doit voir aucune des trois. Nettoyage = décision propriétaire.
- **Le QR du comptoir de la roue mène toujours chez lecayenne.fr** (`wheel.public_url`).

**État final ONB-12 : le paramétrage autorisé par §0.2 est LIVRÉ — vitrine dérivée de la carte, affirmation halal devenue donnée déclarée avec son écran, migration qui préserve l'existant. Le volet constitutionnel reste BLOQUÉ, propriétaire.**

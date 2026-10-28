# Constats vérifiés, NON corrigés — état au 2026-08-28

Ce fichier existe pour qu'aucun constat ne se perde entre deux sessions. Chacun a
été **vérifié** (file:line + reproduction) ; aucun n'est une supposition. Ils sont
laissés ouverts pour une raison écrite, pas par oubli.

Source : trois balayages adverses en lecture seule sur les 11 missions non
retouchées, plus deux audits adverses sur le travail du jour. 24 constats vérifiés,
13 corrigés, 11 consignés ici.

---

## A. Ce qui exige une signature propriétaire

### A1. La TVA du frais de livraison est figée à 10 % dans le Z **signé**

`ZReportService:864` lit `Config::get('menu.settings.tax_rate', 10.0)`, alimenté par
`config/menu.php:73` — **littéral, sans `env()`**. Aucun écrivain nulle part, aucun
écran. 33 commandes en base portent un frais de livraison (187,40 €).

Une boulangerie (5,5 %) ou un bar (20 %) signerait un Z dont la TVA livraison est
dans le mauvais taux, **sans aucun moyen de le corriger hors du code source**.

⛔ `ZReportService` est **zone gelée §7**. Correctif = `LOCK_*.md` contresigné.

### A2. Les codes promo en caisse

Dossier complet : `docs/gates/GATE_COUPONS_EN_CAISSE_2026-08-28.md`. Trois options
chiffrées, recommandation en deux temps. L'obstacle fiscal invoqué par le garde a
été levé le 2026-05-31 ; il ne reste que la décision commerciale.

### A3. Le wizard de catégorie

Dossier : `docs/gates/GATE_WIZARD_CATEGORIE_JAMAIS_LU_2026-08-28.md`. Zone gelée
`PricingService`, plus une contradiction dans le gate de mai.

### A4. G0 — la phrase de `CONSTITUTION.md §1`

Bloque le volet constitutionnel d'ONB-12 et, en cascade, la dé-cayennisation
complète. Le volet **paramétrage** a avancé sans lui (§0.2 l'autorise).

### A5. Les deux définitions du « jour »

Dossier : `docs/gates/GATE_DEUX_DEFINITIONS_DU_JOUR_2026-08-28.md`.

---

## B. Ce qui exige une décision sur les données en service

### B1. Résidus de tests visibles par les clients

- `items.id=161` **`E2E_PLAYWRIGHT_STUDIO_ITEM`** — actif, mis en avant, non
  supprimé. Déjà visible dans le menu de la borne. *(Depuis le 2026-08-28 il
  n'apparaît plus sur l'écran d'accueil : il n'a pas de vraie photo, et le nouveau
  filtre l'écarte. Il reste dans le menu.)*
- **12 bornes sur 13** sont des résidus de charge (`KM-STRESS-*`, `KM-SOAK-*`).
- **5 filiales fictives**, **26 taxes `AUDIT-*`**.
- **11 stocks de matière première négatifs**.
- **47 articles à `is_featured = 0`** — valeur hors énumération. L'assistant sait
  désormais travailler avec, mais la donnée reste incohérente.

Supprimer ou corriger une donnée en service ne se fait pas sans accord.

### B2. Les miroirs de remboursement naissent sans `business_date`

`RefundWithCounterEntryService:112-133`. Mesure : **6 miroirs, 6 à NULL**, somme
−89,00 €.

Conséquence : deux tuiles du **même** tableau de bord comptent différemment.
« Ventes du jour » filtre sur `business_date` (donc rate les miroirs) ; « Chiffre
d'Affaires du Jour » filtre sur `order_datetime` et inclut les miroirs. L'écart est
exactement les remboursements du jour.

Le correctif touche la création des miroirs, adjacente au domaine fiscal.

---

## C. Ce qui est un chantier, pas un correctif

### C1. Les promos borne : toute la chaîne sauf l'écran

Table `kiosk_promos`, modèle, `findValid()`, service prioritaire sur les coupons,
projection et bandeau — **tout existe**, et rien ne peut créer une ligne. Aucun
contrôleur admin, aucun seeder, aucune factory, aucun `.vue`. Base : **0 ligne**.
Le bandeau d'offres de la borne est masqué en permanence pour tout commerçant.

### C2. `kiosk_promos.uses_count` : lu par un filtre, écrit par personne

`KioskPromo.php:87` refuse au-delà de `max_uses`. `grep` ne rend que la déclaration,
le cast, ce filtre et le `default(0)`. **Aucune incrémentation.** Un plafond
« 100 utilisations » ne mordrait jamais. Le docblock de `PromoController:19` affirme
que la consommation intervient dans `FrontendOrderService` — qui ne mentionne ni
`uses_count` ni `KioskPromo`.

### C3. Cinq champs saisissables nulle part (catalogue)

Tous **lus** en production, aucun écran pour les renseigner :

| Champ | Lu par |
|---|---|
| `is_halal`, `is_vegetarian`, `is_spicy`, `is_new`… | filtres et badges de la borne |
| `barcode` | recherche caisse — **0/170** articles en portent un |
| `order` (rang) | tri de la borne — et **éditer une fiche remet le rang à 1** |
| `channels`, `kiosk_label` (catégorie) | projection borne |
| `min_select`/`max_select` (attribut) | `PricingService` **refuse la commande** |

Le dernier est le plus gênant : la contrainte est **globale à toute la carte**
(`item_attributes` n'a pas de colonne `item_id`), l'écran qui la règle est masqué,
et les valeurs en place sont celles de Le Cayenne. Un commerçant ne peut pas dire
« 2 sauces sur le tacos, 1 sur le sandwich », et une règle écrite pour un autre
restaurant refuse les commandes de ses clients.

### C4. L'afficheur client n'a aucun écran

`config/printing.php:185` n'est réglable que par `.env`. Le défaut ne dit plus « LE
CAYENNE » depuis le 2026-08-28, mais l'écran manque toujours.

### C5. Les frais de TPE saisis ne sont lus par personne

Saisie complète et gardée. Seul lecteur : `ZReportCashEnrichmentService:201`, qui
**n'est jamais instancié en production** (`grep` : 0 résultat hors tests). Cause
amont : `OrderPayment::create` n'existe qu'en `SplitPaymentService:286`, donc une
vente carte en règlement unique n'écrit aucune ligne `order_payments`.

---

## D. Sécurité

### D1. Le téléphone n'a pas d'identité — question de conception, pas contournement

**⚠️ Constat reformulé le 2026-08-28 après vérification. Ma première rédaction disait
« le téléphone contourne une permission », ce qui laissait croire à un correctif
simple. C'est faux, et la nuance change tout.**

Le fait est exact : `routes/web.php:97` (`POST /m/api/toggle`) ne passe que par
`EnsureMobileStockPin`, sans aucun `can()`, et appelle le **même**
`AvailabilityService` que la route gardée par
`permission:items_edit|availability_toggle`.

Mais **il n'y a aucun utilisateur authentifié sur `/m`**. Le groupe ne porte que
`installed` + la garde PIN ; `grep "auth()\|Auth::\|user()"` sur
`MobileStockController` rend **zéro**. La surface est déverrouillée par un **code PIN
partagé**, pas par un compte.

Il n'y a donc **personne à qui demander une permission** : ajouter un `can()`
fermerait l'écran entièrement, pour tout le monde.

Le dispositif est par ailleurs délibéré et documenté — `EnsureMobileStockPin` se
déclare « miroir de `EnsureDailyBookPin` », se referme immédiatement si le PIN est
retiré de la configuration (y compris sur les sessions en cours), et le
déverrouillage est limité en débit (`throttle:mobile-stock-pin`).

**La vraie question, et elle est pour le propriétaire** : un canal à PIN partagé,
sans identité individuelle, doit-il pouvoir basculer la disponibilité des produits —
la même action que le Dashboard réserve à deux permissions nommées ? C'est un
arbitrage entre la commodité du terrain (un téléphone en cuisine, pas de connexion à
taper) et la traçabilité (qui a mis ce produit en rupture ?).

Je ne le tranche pas : fermer la surface casserait un usage réel, et l'ouvrir
davantage n'est pas à moi de le décider.

### D2. Deux permissions cochables ne gardent rien

`pos-reopen-z` (libellée « Rouvrir une clôture Z déjà faite », attribuée au Branch
Manager) et `push-notifications_edit`. Balayage exhaustif des 84 permissions :
exactement ces deux-là.

### D3. `User` n'est pas isolé par branche

Déjà documenté dans `CLAUDE.md §9` depuis le 2026-08-14 : `BranchScope::apply()`
fait un no-op explicite sur `User`. Sans effet en V1 mono-branche ; trou le jour d'un
vrai multi-succursales.

---

## E. Zone gelée

### E1. La sauce payante n'est facturée que si le commerçant devine deux mots

`KioskWizardComponent.vue:1996` et `:2020` : le supplément n'entre au panier que si
un extra vérifie `group_label === 'sauce'` **et** `/suppl/i.test(name)`. Or l'écran
de saisie est un champ libre, sans liste ni contrainte.

Un commerçant qui groupe sous « Sauces » ou nomme son extra « Sauce en plus »
(0,50 €) le voit distribué **gratuitement**, sans aucun signal.

⛔ `KioskWizardComponent.vue` est **zone gelée §7**.

---

## Piste explicitement écartée — ne pas la rouvrir

`ItemExtraRequest.php:35-37` affirme que `$this->route('item.id')` « renvoyait
NULL » et que la garde d'unicité ne mordait jamais. **C'est faux** :
`Route::parameter()` passe par `Arr::get`, qui gère la notation pointée, et un modèle
Eloquent implémente `ArrayAccess`. Vérifié à l'exécution.

`ItemRequest:70`, `ItemVariationRequest:39` et `ItemAddonRequest:34` ne sont donc
**pas** cassés. Le commentaire qui prétend le contraire risque de provoquer une
« correction » inutile — et de casser ce qui marche.

---

# CONSTATS NÉS DE LA CONSOLIDATION DU 2026-08-28

Branche `release/consolidation-2026-08-28`. Trois lignes de travail parallèles réunies :
la ligne servie (`origin/pos/category-first-caisse`), `goal/caisse-vision-2026-08-24`,
`goal/onboarding-commercant-2026-08-26` (les 14 missions), et le GOAL CONSOLIDATION du
2026-08-25. Ces constats n'existaient sur AUCUNE des branches prises isolément : c'est
la réunion qui les produit. Chacun est mesuré, aucun n'est supposé.

## F1. `audit-supervisor-waveA.spec.js` commande deux articles NON VENDABLES

Relevé en base `foodking_e2e` le 2026-08-28 (pas deviné — CLAUDE.md §3ter) :

| id codé en dur | nom | status |
|---|---|---|
| 25 | Sandwich Classique | **10** ⛔ |
| 27 | Big Tacos | **10** ⛔ |

Les cinq autres identifiants de cette spec (1, 2, 22, 26, 33) existent en status 5.

Un vert sur cette spec ne prouve donc rien d'un parcours client réel. Remède connu et
déjà écrit : `resolveSimpleOrderableItem()` dans `tests/e2e/helpers/kiosk-order.js`.
Non corrigé ici parce que la spec exige un serveur vivant pour être rejouée, et
modifier un banc sans pouvoir le rejouer fabrique un banc au mauvais périmètre.

**Conséquence acceptée** : le cliquet `e2eFixturesSansIdentifiantCode` est passé de
24/56 à 28/65. Un cliquet qui monte est une concession ; elle est écrite en clair dans
le fichier. La dette n'est pas neuve — ces quatre specs sont antérieures au cliquet,
né sur une autre branche — mais elle est désormais visible et chiffrée.

## F2. `caisse-vision` a modifié une zone gelée en laissant sa sentinelle rouge

Le correctif AB-003 (`91fd9742c`) modifie `public/js/pos-wizard.js` — zone gelée §7 —
sous `plans/LOCK_POS_WIZARD_FMT_MONETAIRE_FR_2026-08-26.md`, **statut APPROVED** par
délégation explicite du propriétaire. L'override est régulier.

Ce qui ne l'était pas : `frozen-zone-sha256-baseline.json` n'a jamais été mis à jour,
alors que la sentinelle l'exige DANS LE MÊME COMMIT. `caisse-vision` était donc rouge
sur sa propre sentinelle de zone gelée, sans que personne ne le voie. Corrigé à la
fusion.

**Ce qu'il faut en retenir** : une branche isolée peut porter une sentinelle rouge
pendant des jours. Le motif s'est déjà produit (mémoire « sentinelle au mauvais
périmètre »). Rien dans le protocole n'oblige aujourd'hui à jouer les sentinelles de
zone gelée AVANT de quitter une voie.

## F3. 23 des 36 clés `label.*` de la Vue Caisse manquaient en anglais

Trouvé en réécrivant `libelleReconciliationCaisse.spec.js` : `cash_drawer_reconciliation`,
`cash_sales_in_period`, `cash_reconciliation_gap`, `breakdown_by_method`, `mode_*`,
`source_*` … L'écran entier affichait des clés brutes en anglais. **Corrigé** (ajoutées
à `en.json`). Sans effet en V1 (FR seul, ADR-007), mais c'est le motif qu'ONB-11 a
signalé et qu'il disait vouloir balayer systématiquement plutôt que par hasard — ici
encore, la trouvaille est venue par accident, en posant autre chose.

## F4. Deux voies ont corrigé le MÊME défaut quatre fois, sans le savoir

- Bandeau de réconciliation (« aujourd'hui » sur une valeur qui ne l'est pas) : ligne
  servie D-001 **et** ONB-10. La correction ONB était devenue fausse une fois fusionnée
  — elle collait « depuis l'ouverture » sur le champ borné à la période.
- Identifiants de démo dans le bundle public : SEC E-006 **et** ONB-12.
- Bornage des alertes SLA : GOAL CONSOLIDATION T-5.3.3 **et** ONB-07.
- Seeder de permissions non rejouable : GOAL CONSOLIDATION **et** ONB-06 F-05 — et
  chacun voyait une moitié du problème que l'autre ne voyait pas.

Quatre doublons sur trois branches en trois jours. Le coût n'est pas le travail perdu,
c'est qu'**une correction faite en parallèle peut devenir FAUSSE en fusionnant**, et
qu'aucun banc ne l'attrape : les deux côtés sont verts séparément.

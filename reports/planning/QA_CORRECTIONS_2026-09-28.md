# QA — corrections des trois rapports (2026-09-28)

**Directive traitée** : corriger les défauts listés par
- `reports/planning/QA_LOOP_NEXT_ACTION_2026-09-27.md`
- `reports/planning/QA_CROSS_SURFACE_FOLLOWUP_2026-09-25.md`
- `~/Documents/Codex/2026-09-20/x20-teste-moi/plans/RAPPORT_DEV_CAISSE_2026-09-24.md`

**Branche** : `qa/corrige-rapports-2026-09-28` (worktree `.claude/worktrees/qa-corrige-2026-09-28`)
**Base** : `d9a95ac77`
**Périmètre d'entrée** : 19 P0 + 76 P1 (rapport Codex) + 3 blocages ouverts (rapports QA)

---

## 0. Méthode — pourquoi ce rapport n'a pas la même forme que le rapport d'entrée

Chaque affirmation a été **reproduite dans le code avec `file:line` avant tout
correctif** (CLAUDE.md §3ter). Cinq agents adverses ont vérifié en lecture
seule, en parallèle ; leurs affirmations ont ensuite été recoupées.

Cette discipline a payé : **une part significative des défauts signalés n'existe
pas ou est déjà corrigée** — plusieurs par des commits POSTÉRIEURS à la date de
l'audit (24/09), l'auditeur lisant un bundle ou un dataset antérieur. Et à
l'inverse, le défaut le plus coûteux du lot (de l'argent réel encaissé à tort)
était décrit avec la **mauvaise cause** et attribué à la **mauvaise surface**.

Chaque banc de test neuf a été **prouvé mordant** : le défaut est réintroduit
volontairement, l'échec est constaté, puis le correctif est restauré. Un banc
vert sur le mauvais périmètre est pire que pas de banc — ce rapport en a
d'ailleurs trouvé un et l'a corrigé (§3.9).

---

## 1. Corrigé, testé, commité — 9 points

| # | Réf. rapport | Défaut | Preuve |
|---|---|---|---|
| 1 | P0-10 / triage **A9** (risque n°1) | Rapport X fiscal **inatteignable** pour le compte admin | 2 échecs → **6/6** |
| 2 | P0-16 / A5 | Borne : boisson **épuisée vendable** dans l'étape menu | 3 échecs → **6/6** |
| 3 | P0-18 / **A2** (risque n°2) | Encaissement : numéro court **ambigu entre jours** | 1 échec → **8/8** |
| 4 | P1-30 / A5 | Catalogue : « Actif » affiché sur un produit **en rupture** | import KO → **8/8** |
| 5 | P1-11 + P1-65 / A19 | **« Sans sauce » facturée 0,50 €** — argent réel | 3 échecs → **10/10** |
| 6 | P0-15 / A11 | Tableau de bord **aveugle aux ruptures matières** | 3 échecs → **7/7** |
| 7 | addendum triage C | Route de **suppression nue** (ni throttle ni idempotence) | → **4/4** |
| 8 | P1-25 résiduel | Libellé lecteur d'écran **contradictoire** (KDS) | 18/18 |
| 9 | — (trouvé en route) | Banc `posDeliveryFlag` au **mauvais périmètre** | prouvé mordant, **8/8** |

Commits : `0e71e05d6`, `9429efd98`, `fd6faec72`, `86f5ecd12`, `2fe8686a4`.

### 1.1 — P0-10 · le rapport X fiscal était inatteignable (risque n°1 du triage)

`XReportController` refusait en 422 « Votre compte n'est rattaché à aucun
établissement » dès que `branch_id = 0` — c'est-à-dire **pour le compte
administrateur**, non épinglé par conception (§9 admin bypass). Un document
fiscal légalement obligatoire était donc hors d'atteinte, alors que :
- la liste Z avait **déjà** reçu sa relaxation lecture seule (Wave T R1 F1 P0, 2026-05-20) ;
- `XReportService` est read-only **par contrat** (« Never writes ») ;
- le bouton « Rapport X » était rendu **sans aucun garde**, donc l'erreur
  arrivait comme résultat d'une action d'apparence légitime.

Résolu **sans toucher la sémantique NF525** : aucun agrégat inter-branches
inventé, parce qu'un instantané fiscal appartient à une caisse — ce que dit le
message d'erreur lui-même. Ordre retenu : branche épinglée → elle gagne
toujours ; sinon `branch_id` explicite et existant ; sinon branche unique
(enveloppe V1 LOCAL, sans ambiguïté) ; sinon 422 maintenu.

Le point 1 est un **garde anti-IDOR testé** : un employé épinglé qui passe
`?branch_id=` d'une autre caisse reçoit la sienne. Branche inconnue → 422, pas
500. Aucune clé i18n ajoutée : dette `ar/de/bn` inchangée.

### 1.2 — P1-11 / P1-65 · « Sans sauce » facturée : de l'argent réel

Le plus coûteux du lot, et **le rapport se trompait sur la cause** :
« Sans sauce » n'est pas une option payante (sa variation vaut 0,00 €, déjà
verrouillé par `EnsureCayenneMixteCommandTest`). Le wizard facture les sauces au
**décompte** : `extraSauceN = sauceOrder.length - 1`, puis autant d'extras
« Sauce supplémentaire » à 0,50 € **scellés par `PricingService`**. Rien ne
rendait l'option exclusive : cochée en plus de deux sauces, elle comptait comme
troisième sauce payante. **Le client payait 0,50 € pour ne PAS avoir de sauce.**
Arithmétique du rapport reproduite : 4,90 + 2 × 0,50 = 5,90 €.

Le décompte vit dans deux fichiers **gelés §7**. Mais la **source** de
`sauceOrder` est `KioskStepSauceComponent.toggleSauce()`, qui ne l'est pas : en y
rendant l'option exclusive — exactement la règle demandée par le rapport — le
décompte ne peut plus la voir (`['sans_sauce']` seul → `extraSauceN = 0`).
**Le défaut tombe en amont du code gelé, sans gate propriétaire.**

Reconnaissance de l'option depuis la SSOT (`config/pos_sauces.php`, déjà servie
à la page via `SauceCatalog::frontPayload()`), pas en dur : ajouter un alias au
seul fichier de config suffit.

⛔ **La CAISSE reste exposée** au même défaut d'argent
(`public/js/pos-wizard.js`, gelé, 6 sites de décompte) → §2.2.

### 1.3 — P0-18 · encaisser la mauvaise commande

`queue_number` est un compteur **quotidien** par branche (unicité DB sur
`(branch_id, business_date, queue_number)` : la réutilisation d'un jour à
l'autre est légale). La file « à encaisser » n'a **volontairement** aucun filtre
de journée — les incidents « ENCAISSEMENT-ROBUSTE » ont montré qu'un filtre de
date rend des commandes légitimes invisibles et non encaissables. Deux commandes
de jours différents portant le même N° cohabitent donc, et la carte n'affichait
que `N°A0041`.

Le correctif existait **déjà** côté POS depuis le 26/09 (`shortcutDateBadge`)
mais n'avait jamais été porté sur l'écran dédié. Règle extraite dans un helper
partagé ; **aucun filtre de date ajouté côté serveur** — on lève l'ambiguïté
d'affichage sans risquer de masquer une commande.

### 1.4 — P0-15 · les deux écrans ne lisaient pas la même table

Cause structurelle **que le rapport n'avait pas nommée** : le panneau
n'interrogeait que `stock_levels` ; `RawMaterial` n'apparaissait **nulle part**
dans le contrôleur. Les matières premières étaient donc structurellement
invisibles — même avec tous les seuils renseignés — pendant que « Conso & Stock »
les déclarait `out` dès `on_hand <= 0`.

Source matières ajoutée avec la **même règle** que l'écran de référence. Couche
de lecture strictement additive, aucune écriture, hors chaîne fiscale.

⛔ **Non changé à dessein** : un stock d'**article** à 0 sans seuil reste non
alertant — décision explicite et testée du dépôt (« sans seuil, aucune alerte ne
peut sortir — c'est le piège »), déjà compensée depuis le 02/09 par les
compteurs et le bandeau ambre. La renverser est une décision propriétaire (§2.7).
Un test **verrouille** cette non-régression.

### 1.5 — Addendum · la route de suppression était nue, derrière un commentaire faux

`DELETE api/admin/pos-order/{order}` — seule route de suppression câblée à une
UI — n'avait **aucun middleware**, alors que toutes ses voisines mutantes portent
`['throttle:pos-order-update','idempotency']`. Le client **envoyait déjà**
l'en-tête, et un commentaire affirmait une protection à « routes/api.php:885 »,
ligne qui désigne en réalité une route de session de caisse sans rapport.

**Portée mesurée, pas supposée** : mon premier test exigeait le rejeu de la
réponse d'origine et **a échoué** (404 au lieu de 202). Cause : `SubstituteBindings`
appartient au groupe `api` et résout le modèle **avant** les middlewares de
route, donc le 404 précède tout rejeu. Le test a été réécrit pour n'affirmer que
le vrai : le câblage apporte le throttle (absent) et le verrou au-plus-une-fois
sur les duplicatas **concurrents** (le vrai risque du double-clic). La limite est
documentée dans la route, le test et le commentaire client corrigé.

### 1.6 — Le banc qui ne mordait pas

`tests/js/posDeliveryFlag.spec.js` **n'importait pas** `PosComponent.vue` : il
redéclarait sa propre copie de la règle et serait resté **vert** si
`v-if="deliveryEnabled"` avait disparu du gabarit. Réécrit sur la vraie
propriété calculée + garde de gabarit. **Prouvé** : garde retiré → 1 échec ;
restauré → 8/8. Les six cas d'origine sont conservés à l'identique.

⚠️ `tests/js/posDineInFlag.spec.js` porte **le même défaut de périmètre** → §2.9.

---

## 2. Escalades — décision propriétaire requise, rien changé en silence

### 2.1 — Sentinelle zone gelée : deux garde-fous se contredisent

Le **seul** échec de la suite backend globale, confirmé indépendamment :
hash réel `f8ecb111…06465` vs baseline `fcbe3755…256ac` sur
`KioskWizardComponent.vue`. Le diff de `c21628767` est **exactement 3 lignes de
commentaire**, zéro ligne exécutable (vérifié).

**Ce que les rapports QA n'ont pas relevé, et qui change la décision** : ce
commit a été fait pour satisfaire `pos:lint:pricing`, dont la regex exige
`signed-off: <noms> — date: YYYY-MM-DD`. Autrement dit **le lint et la sentinelle
de hash s'excluent mutuellement** : satisfaire l'un casse l'autre.

Par ailleurs, les rapports citent le sign-off non coché de
`LOCK_KIOSK_FRITES_SAUCE_BILLING_2026-07-29.md` comme blocage. Vérifié : la case
est bien vide (« rebuild bundles + validation borne réelle »). **Mais** ce lock
porte sur le changement de **juillet**, lequel est déjà **inclus dans la baseline
actuelle** `fcbe3755`, autorisée le **2026-09-16**. Ce sign-off n'a donc pas
bloqué la baseline jusqu'ici ; l'invoquer pour un reformatage de commentaire est
incohérent.

**Trois options, au choix du propriétaire** — aucune prise ici :
1. **Revenir sur les 3 commentaires** → hash revient à la baseline, sentinelle
   verte, mais `pos:lint:pricing` redevient non conforme (sauf à assouplir sa
   regex, ce qui se fait hors zone gelée).
2. **Mettre à jour la baseline** vers `f8ecb111…` avec la mention
   `last_authorized_update` — c'est la voie que la baseline documente elle-même,
   et le drift est prouvé commentaire-seul.
3. **Cocher le sign-off de juillet** si la validation borne réelle a bien eu
   lieu, pour clore la question de gouvernance de fond.

Baseline et fichier gelé laissés **strictement inchangés**.

### 2.2 — Caisse : le même défaut d'argent « Sans sauce »

`public/js/pos-wizard.js` (gelé §7) porte 6 sites de décompte + l'entrée
`sans_sauce`. Le correctif borne (§1.2) **ne couvre pas la caisse**. Un précédent
de LOCK étroit existe déjà sur ce bloc (sign-off 2026-07-15 et 2026-07-29).
**Recommandation : traiter en priorité** — c'est de l'argent client.

### 2.3 — Impression automatique : un second chemin, côté serveur

Le chemin navigateur a été corrigé le 24/09. Mais
`app/Listeners/PrintFiscalReceiptAndOpenDrawerOnCounterPaid.php` envoie le ticket
client **sans jamais lire** `printing.auto_print_client_receipt`. Il est
aujourd'hui **dormant uniquement parce que la table Imprimantes est vide** — il
se réarmera le jour où une imprimante `station='receipt'` sera ajoutée, et il
part même après un « Non merci » du caissier.

⚠️ Un test **verrouille le comportement actuel**
(`CounterPaidPrintAndDrawerTest` affirme que le ticket EST envoyé, sans réglage
de flag). Le corriger rend ce test rouge → **ce n'est pas un correctif
silencieux possible**, il faut une décision.
Constat annexe : ce chemin serveur contourne le compteur de duplicata
`receipt_print_count`.

### 2.4 — Readiness : ni impression ni paiement ne sont sondés

`HealthController::ready()` sonde `db`, `redis`, `queue_worker`,
`broadcast_config`, `scheduler`, `backup_age`, `restore_drill` — **aucune sonde
imprimante, aucune sonde terminal, aucune sonde mode simulation**. Le tableau de
bord POS non plus. D'où le « tout va bien » avec table Imprimantes vide et
`Simulation / SIM-CAYENNE-1` comme seul TPE actif (P0-08 / P1-02). Les boutons
d'impression ne sont pas non plus gardés par une capacité. **Zéro couverture de
test** sur ce périmètre.

### 2.5 — Chemin de suppression : 3 manques réels (le reste est réfuté)

Le gros de P0-09 / P1-13 / P1-57 est **réfuté** (§3). Restent, tous les trois
étant des changements de comportement sur une action destructive :
- **aucun motif collecté** — l'UI n'envoie jamais `destroy_reason`, donc les
  **trois** traces (`action_logs`, chaîne NF525 `audit_logs`, `deletion_log`)
  enregistrent `reason: null`. Le « quoi » est intégralement conservé, le
  « pourquoi » est irrécupérable. `SoftDeleteAuditObserver` code même `null` en
  dur. Le sibling `RETURNED` exige, lui, un motif : l'asymétrie est réelle.
- **aucun garde de statut** : `Supprimer` reste actif sur `En préparation` /
  `Préparée`.
- **aucune diffusion temps réel d'annulation** vers KDS/OSS : `OrderCanceled`
  n'a pas de listener outbox, la cuisine perd le ticket au prochain poll sans
  signal explicite.

### 2.6 — P0-14 · stock matière négatif : donnée + décision, pas un bug de code

Le négatif est **documenté et voulu** (`RawMaterialStockService` : « on_hand PEUT
passer négatif — pas de garde non-négatif »), et la couche théorique est
déclarée non autoritative. La cause de l'ampleur (facture en kg créditée en g,
×1000) est **déjà corrigée** dans `PurchaseService` — un convertisseur réparé ne
répare pas rétroactivement la dérive. **Ne pas** ajouter de blocage de vente sur
cette couche : avec les données actuelles, cela arrêterait toute vente.
Reste : un recomptage d'inventaire via `RawMaterialStockService::adjust()` (déjà
motif-gardé et historisé), puis éventuellement un chantier V2 de disponibilité
pilotée par nomenclature.

### 2.7 — Stock d'article à 0 sans seuil : alerter ou non ?

Aujourd'hui non alertant, par décision testée (§1.4). Le rendre alertant est
défendable — mais c'est renverser une décision du dépôt, donc un arbitrage
propriétaire.

### 2.8 — P0-05 · la production tourne en `APP_ENV=staging`

Déjà connu et assumé (les gardes de boot NF525 §8 sont donc inertes). Bascule à
traiter avec précaution : les terminaux ne sont pas câblés. **Ne pas basculer sur
un coup de tête.**

### 2.9 — `posDineInFlag.spec.js` au mauvais périmètre

Même forme que le banc corrigé en §1.6 : redéclaration locale de la règle, pas
d'import du composant. Non modifié pour garder le correctif borné.

### 2.10 — Les 3 blocages des rapports QA restent propriétaires

- **Compte E2E POS distant** : HTTP 400 « Identifiants invalides ou compte
  bloqué » sur `pos@lecayenne.fr`. La fixture **locale** est active et accepte le
  mot de passe — l'écart est donc strictement côté déploiement. Ne pas modifier
  le code d'auth sur la seule base d'un compte rejeté ; attention au rate-limit
  (429, fenêtre 600 s).
- **Copie publique « Uber Eats »** : surface **externe**
  (`~/Downloads/lecayenne-web-deploy/Site lecayenne`, distant
  `loeymot-sketch/Site-lecayenne`), hors de ce dépôt. Son arbre est **déjà
  dirty** avec des modifications qui ne concernent pas ce texte. Le checkout
  FoodKing versionné est, lui, conforme (`checkoutTakeawayCopy` vert). Ne rien
  committer là-bas sans validation — un push y déclenche un déploiement.
- **Dette i18n** : parsing sans erreur, clés manquantes connues. Lot dédié.

---

## 3. Réfuté ou déjà corrigé — ne pas retravailler

| Réf. | Verdict | Fondement |
|---|---|---|
| **P0-12** | RÉFUTÉ | Double garde serveur **fail-closed** (`AvailabilityService` + `ChoiceAvailabilityResolver`) au devis **et** à la création ; la navigation vers `#payment` attend le devis, donc un 422 l'empêche. Résidu mineur : l'élagage du panier n'inspecte pas les options → message d'erreur au lieu d'un retrait propre. |
| **P0-13** | RÉFUTÉ (donnée) | Le réglage halal est **à 0 par défaut** partout ; **aucune page allergènes n'existe dans ce dépôt** ; aucune chaîne ne revendique ou ne dénie une certification. Déjà tranché comme « décision propriétaire » dans le triage du 26/09. |
| **P1-25** | RÉFUTÉ | Coexistence des badges = **décision propriétaire assumée** (la cuisine prépare AVANT encaissement), **verrouillée par sentinelle**. Les rendre exclusifs serait une régression. Seul le libellé lecteur d'écran était faux → corrigé (§1, point 8). |
| **P1-52** | RÉFUTÉ | Le plancher **effectif** est bien 100 (`ceil(50/100)×100`), et c'est **volontairement** la valeur publiée. 50 est le réglage brut. 4 sentinelles le couvrent déjà. Résidu cosmétique : l'écran admin ne dit pas que le plancher réel est un multiple du taux. |
| **P1-08 / P1-36** | DÉJÀ CORRIGÉ | `f8a5b0688`, **2026-09-26** — deux jours après l'audit. Garde réel dans le gabarit + restauration gardée ; le serveur refusait déjà `DELIVERY`. |
| **P1-27 / P1-03** (navigateur) | DÉJÀ CORRIGÉ | `367a72ba6`, **2026-09-24**. Reste le chemin serveur → §2.3. |
| **P1-65** (site public) | RÉFUTÉ sur cette surface | Le site vitrine implémente le mécanisme `solo` depuis le **2026-07-31** et n'offre même pas l'option sur Tacos XL. Défaut réel sur borne + caisse → §1.2 / §2.2. |
| **P0-09 / P1-13 / P1-57** | MAJORITAIREMENT RÉFUTÉ | Suppression **douce** et à sens unique (`restore()` bloqué) ; **3 couches** de permission (middleware, isolation de branche, `pos-destroy-paid`) ; **409** si scellé par un Z clos ; **trigger DB** bloquant la suppression dure d'une commande fiscalisée ; écriture dans la **chaîne NF525** dans la même transaction. **§8 non violé.** Manques réels → §2.5. |
| **P0-15** (formulation) | DÉJÀ CORRIGÉ | Le bandeau ambre remplace la phrase rassurante depuis le **2026-09-02**, 22 jours avant l'audit : l'auditeur lisait un bundle antérieur. La **substance** du défaut restait réelle → §1.4. |
| **P1-66 / P1-76** | CONFIRMÉ mais **hors de ce dépôt** | Message d'étape résiduel : `error` n'est jamais réinitialisé au changement d'étape dans `wizard-v2.jsx` du **site vitrine**, alors que le voisin `addError` l'est. Correctif d'une ligne, à faire dans le dépôt externe. |
| **P1-30** (backend) | RÉFUTÉ | Une seule source de disponibilité, correcte. Le défaut était purement dans un écran → corrigé (§1). |

**Lecture d'ensemble** : la couche serveur de disponibilité est un vrai SSOT qui
replie correctement le 86 par branche ; ce sont **trois surfaces clientes** qui
jetaient le champ qu'elle envoie déjà. Aucun correctif backend n'était justifié
pour cette famille.

---

## 4. Preuves

- **PHPUnit complet** : **6 138 tests, 1 seul échec, 36 skips, 6 incomplets.**
  L'unique échec est **la sentinelle frozen-zone déjà connue** (§2.1), avec les
  mêmes hashes qu'avant mon intervention — donc **zéro régression et zéro nouvel
  échec**. Baseline de référence : 6 080 passés / 36 skips / 6 incomplets /
  1 échec ; mêmes compteurs, +21 tests backend neufs.
  ⚠️ Une première passe donnait **19 échecs** : **contamination d'environnement**.
  Elle avait démarré avant `npm run production`, donc `mix()` ne trouvait pas le
  manifest et les vues partaient en **500** (56 occurrences de `mix-manifest`
  dans le journal). Le run propre en compte **0**. Ne jamais lancer un build en
  parallèle d'une suite déjà démarrée : le résultat devient non interprétable.
- **Vitest complet (Node 20.20.2)** : **560 fichiers, 4 544 passés, 3 skips,
  0 échec** (baseline 4 511 — les 33 tests neufs de ce lot en plus).
  ⚠️ Une première passe montrait 10 échecs : **artefact d'environnement**, pas
  une régression — les 6 sentinelles de fraîcheur échouaient aussi sur
  « bundle **exists** », les bundles étant hors index git donc absents d'un
  worktree frais. Prouvé : après `npm run production`, **37/37**.
- **Garde-fous release** : `pos:lint:pricing` **OK** (86 fichiers, avertissement
  de sign-off jusqu'au 27/10), `pos:lint:status` **OK** (38 fichiers),
  `perf:bundle-check` **OK**, aucun dépassement.
- **Diff zone gelée §7 contre la base réelle `d9a95ac77` : VIDE.** Aucun des 15
  fichiers gelés touché, aucune baseline modifiée.
  *(Mesurer contre `main` donnait un faux positif de 14 fichiers : `main` est
  très en retard sur la base de cette branche. Mesurer contre le mauvais
  référentiel est le même piège que le banc du §1.6.)*
- **Suites backend ciblées** : `Fiscal` 321 (0 échec, 8 skips MySQL),
  `Idempotency` 21/21, `RawMaterials` 74/74, `Stock` 90 (4 skips),
  `Admin` 162/162, `Pricing` 31/31, et les 5 suites du chemin `destroy` vertes.
- **Bancs prouvés mordants** : garde de gabarit encaissement, exclusivité
  « Sans sauce » (assertion d'**argent**), garde de gabarit `posDeliveryFlag`.

---

## 5. Ce que je recommande de traiter d'abord

1. **§2.2 — « Sans sauce » à la caisse.** De l'argent client, encore encaissé à
   tort sur cette surface. Un LOCK étroit a déjà un précédent sur ce bloc.
2. **§2.1 — trancher le deadlock lint / sentinelle.** Tant qu'il tient, la suite
   backend globale ne peut pas être verte, et un rouge permanent finit par ne
   plus être lu.
3. **§2.3 — le listener d'impression serveur.** Dormant par accident, pas par
   conception : il se réarme à la première imprimante ajoutée.
4. **§2.5 — motif de suppression + garde de statut.** Le « pourquoi » d'une
   suppression est aujourd'hui irrécupérable dans les trois traces.

---

**VERDICT : 9 défauts corrigés avec preuves, une part importante du rapport
d'entrée réfutée ou déjà corrigée, 10 points escaladés au propriétaire.
Aucune zone gelée touchée, aucune baseline modifiée, aucun test rendu vert en
affaiblissant son assertion.**

---

## 6. Action B du plan de reprise (« copie publique Uber Eats ») — **CONTRADICTION, non exécutée**

Le plan de reprise demande de « déployer uniquement la copie validée :
"Commander à emporter", "Confirmer à emporter", **"Livraison par nos livreurs
bientôt"** » sur la surface publique. **Je ne l'ai pas fait, et je recommande de
ne pas le faire en l'état.** Constats vérifiés dans le dépôt externe
(`~/Downloads/lecayenne-web-deploy/Site lecayenne`, `main` à `b7bc176`) :

1. **La boutique Uber Eats est une DÉCISION PROPRIÉTAIRE explicite et vivante** —
   `index.html:85` :
   `<!-- [OWNER 2026-07-29] Boutique Uber Eats officielle — le code après /le-cayenne/ est l'identifiant STABLE de l'enseigne -->`
   suivi de l'URL réelle de la boutique. Retirer cette mention **supprimerait un
   canal de vente réel**.

2. **La copie actuelle est VRAIE.** `commander.html:584` : « Le Cayenne n'assure
   pas de livraison en propre — pour être livré, la boutique est disponible sur
   Uber Eats. » C'est exactement la réalité d'exploitation.

3. **La copie demandée serait FAUSSE.** « Livraison par nos livreurs bientôt »
   promet un service qui n'existe pas, sur une page publique, alors que le
   réglage `LIVRAISON` est **désactivé** côté back-office. C'est précisément la
   classe de défaut que ce même audit dénonce ailleurs (P1-46 « paiement en ligne
   promis alors que désactivé », P1-68 « le ticket promo promet la livraison
   alors qu'elle est désactivée »). L'appliquer créerait le défaut qu'on reproche.

4. La clé `delivery_coming_soon` du checkout FoodKing versionné est cohérente
   **dans son contexte** : elle s'affiche là où la livraison en propre est
   désactivée, pour expliquer pourquoi le mode n'est pas sélectionnable. Ce n'est
   pas la même affirmation que « nous ne livrons pas, passez par Uber Eats »
   destinée au public. **Les deux textes ne sont pas interchangeables.**

**Décision propriétaire requise** : soit la copie publique reste telle quelle
(recommandé — elle est exacte), soit vous voulez retirer Uber Eats du site,
et c'est alors un choix commercial, pas une correction de conformité.

Contraintes techniques qui s'ajoutent, quelle que soit la décision : le dépôt
externe porte **15+ fichiers non commités** d'un autre travail (dont
`compiled/racine.js`, `components.jsx`), les deux pages visées sont elles-mêmes
déjà modifiées, et un push sur `main` **déclenche un déploiement Vercel
immédiat**. Rien n'a été committé ni poussé là-bas.

---

## 7. Déploiement — état exact et ce qui manque

**Fait** :
- Branche `qa/corrige-rapports-2026-09-28` **poussée sur `origin`** (9 commits).
- **Base de comparaison production capturée AVANT tout déploiement** :
  `https://vps-418872ac.vps.ovh.net/api/healthz` → **HTTP 200**,
  `db`/`redis`/`websocket`/`fiscal_chain` = `ok`, `queue_pending` = 0.

**Procédure canonique du dépôt** : `tools/deploy-lecayenne.sh`. Elle est saine et
n'a **rien à corriger** — elle saute déjà `config:cache` volontairement
(« piège fiscal »), installe **et vérifie** les triggers d'immuabilité, lance
`fiscal:verify-chain --all`, contrôle healthz/CORS/couverture des queues, et
**rollback automatiquement** sur le HEAD précédent en cas d'échec. Elle accepte
un `REVIEWED_SHA` qui **avorte** si le HEAD déployé ne contient pas ce commit.

> **Correction d'une de mes notes internes** : je pensais devoir corriger les
> scripts parce que `config:cache` casserait la chaîne NF525. **C'est faux** :
> `FiscalChainValidator.php:188-191` documente cette piste comme une
> « FAUSSE PISTE À NE PAS REPRENDRE », **vérifiée en production** (aucun
> `bootstrap/cache/config.php`, et le validateur échouait quand même). L'agilité
> de secrets couvre le cas. Aucun script n'était donc à corriger.

**Ce qui manque, et que je ne peux pas faire moi-même** :

1. **Merge sur la branche que suit la production.** `tools/deploy-lecayenne.sh`
   cible en dur `pos/category-first-caisse-2026-06-23`. Ce n'est **pas** un
   fast-forward : le distant a avancé à `d684ece9a`. Bonne nouvelle : les 20
   commits distants sont **tous `docs(qa)` et ne touchent que les deux rapports
   markdown** — donc **aucun conflit de code**, seulement les sections que j'ai
   ajoutées en fin de ces deux fichiers, à résoudre en **gardant les deux**
   apports. Mon `git merge` a été **refusé par le classifieur de permissions**
   (« Modify Shared Resources »).
2. **Accès shell à la production.** `ssh lecayenne` a été **refusé**
   (« Production Reads »), donc je n'ai pu ni lire l'état du serveur ni lancer le
   déploiement.

**Commandes exactes à exécuter (dans cet ordre)** :

```sh
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/qa-corrige-2026-09-28
git merge origin/pos/category-first-caisse-2026-06-23   # conflits: les 2 rapports .md, GARDER LES DEUX côtés
git push origin HEAD:pos/category-first-caisse-2026-06-23
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
bash tools/deploy-lecayenne.sh b6d98c941                 # REVIEWED_SHA = mon dernier commit
```

**Vérification post-déploiement à exiger** (au-delà du healthz que le script
contrôle déjà) :
- `/api/healthz` → 200 et `fiscal_chain: ok` (comparer à la base ci-dessus) ;
- le **rapport X** doit s'ouvrir depuis le compte admin sans le 422
  « compte non rattaché » (c'est le correctif P0-10) ;
- sur `/admin/encaissement`, une commande d'un jour antérieur doit afficher le
  **badge de date** à côté de son N° (P0-18) ;
- sur la borne, une boisson en rupture ne doit plus être **sélectionnable** dans
  l'étape menu (P0-16) ;
- cocher « Sans sauce » **après** deux sauces doit ramener le total **sans** les
  0,50 € (P1-11) — et rappel : **la caisse reste exposée**, elle n'est pas
  corrigée (§2.2).

---

## 8. Second lot (2026-09-28, après « deploy ») — 4 corrections, 2 escalades

### 8.1 Corrigé

| Réf. | Défaut | Preuve |
|---|---|---|
| **P1-18** | L'**API** acceptait de parker une commande **sans article** (`items_count = 0`, HTTP 201) | 3 échecs → **6/6** |
| **P1-04** | Panneau « en attente » : `0` + « Aucune commande parkée » + « Impossible de charger » **en même temps** | 6 échecs → **6/6** |
| **P1-43** | Format horaire **12 h semé par défaut** contre le verrou FR ADR-007, et libellé qui **mentait** | 4 échecs → **6/6** |
| **P1-49** | Actions TPE **sans nom accessible** (action destructive anonyme) | prouvé mordant, **5/5** |

**P1-18 — le rapport avait à moitié tort, et le vrai trou était ailleurs.** Côté
écran, `promptParkOrder` refuse déjà depuis le **2026-04-21** : le clic ne crée
rien. L'audit a observé l'état *activé* du bouton et en a déduit la création.
Mais l'**API** n'était pas gardée : `payload` n'avait qu'à être un tableau non
vide, et la snapshot du store porte toujours ses clés — `{lists: []}` créait donc
un brouillon fantôme. Garde posé dans le **service** (il couvre les deux formes
`lists` ET `items`, qu'une règle sur la seule clé `lists` manquerait), et le
bouton est désormais réellement désactivé sur panier vide — un contrôle activé
qui refuse est une mauvaise affordance.

**P1-04 — le jumeau oublié du correctif « faux-vide ».** Le composant n'avait
**aucun** champ d'erreur : l'échec ne produisait qu'un toast éphémère pendant que
l'état vide restait rendu sur le seul critère `length === 0`. Une panne réseau
était donc indistinguable d'une file vide. Patron repris **verbatim** de
« T-4.1 FAUX-VIDE 2026-08-15 » (encaissement) : branche d'erreur avant l'état
vide, exclusives, et un poll transitoire qui échoue n'efface jamais une liste
déjà affichée. Le `.catch(() => {})` silencieux de `openParkedOrders` est retiré.

**P1-43 — trois défauts pour une seule observation.** Le libellé codait `'PM'`
**en dur** quelle que soit l'heure (d'où littéralement « 12 Hour (7:34 PM) » à
07:34, la chaîne citée) ; minuit s'affichait `0:15` au lieu de `12:15` ; et
l'exemple 24 h n'était **pas zéro-paddé** (« 24 Hour (7:4) ») — ce dernier, mon
banc l'a trouvé, le rapport ne l'avait pas vu. Surtout, le **défaut semé** était
`h:i A` alors que la valeur canonique est `H:i` (24 h FR, ADR-007) : toute
nouvelle installation partait en 12 h pendant que ticket, KDS et exports
formatent en dur en 24 h. Les `id` enregistrés sont inchangés, donc aucune
valeur déjà choisie n'est affectée.

### 8.2 Escalades de ce lot

**P1-19 — supplément libre seul : contradiction, non tranchée.** Le rapport exige
qu'« aucun montant arbitraire ne puisse constituer seul une commande ». J'ai
implémenté le garde serveur, et il a **cassé deux contrats existants** :
`QuoteBindingTest::test_pos_commit_persists_a_sealed_manual_supplement_as_a_fiscal_line`
et `QuoteTamperTest` — le dépôt **affirme délibérément** qu'une commande dont
l'unique ligne est un supplément persiste comme ligne fiscale. Un usage légitime
est d'ailleurs plausible : une **vente hors catalogue au comptoir**. Et le risque
est déjà encadré — montant plafonné 0,01–100 €, calculé serveur, taxé, scellé par
signature de devis, fiscalisé et audité.
**J'ai retiré mon garde plutôt que de réécrire le test d'autrui.** Décision
propriétaire : est-ce un abus à bloquer, ou la façon dont la caisse encaisse un
article hors carte ? Si c'est un abus, il faudra aussi adapter ce test — et le
bon niveau est la **validation d'entrée de commande** (`ValidJsonOrder`), en
opt-in, **jamais** `PricingService` (gelé §7, et son test facture volontairement
ce cas). Le devis, lui, doit rester capable de chiffrer un supplément seul.

**P1-10 — « Sélectionnez 1 viande supplémentaire » : ZONE GELÉE.** Confirmé :
`public/js/pos-wizard.js` déclenche ce message quand la viande **INCLUSE et
obligatoire** manque, en empruntant le mot que le dépôt réserve au supplément
**facturé 2,50 €** — un caissier peut croire qu'un supplément est en train d'être
facturé. Le bon libellé existe déjà sur le jumeau borne (`fr.json` :
« Sélectionnez {n} viande pour continuer »). Correctif d'**une ligne**, mais dans
un fichier **gelé §7** → gate propriétaire + LOCK. Deux précédents de LOCK
existent sur cette zone exacte.

**P1-39 — PIN borne « 1234 » : DÉJÀ CORRIGÉ, et ce n'était pas une faille.** La
chaîne a été retirée le **2026-09-26** (deux jours après la recette). Vérifié :
**aucun identifiant par défaut n'a jamais été livré** — ni seeder, ni migration,
ni défaut de config ; c'était une aide trompeuse. Deux résidus préexistants
restent, tous deux propriétaires : le réglage est un **orphelin formellement
recensé** (aucun écran ne le vérifie — « une protection qui ne protège rien »,
dit la sentinelle du dépôt), et il serait stocké **en clair** s'il était un jour
câblé. À câbler avec hachage + limitation d'essais, ou à retirer.

### 8.3 Preuves du second lot

- **PHPUnit complet** : **6 144 tests, 1 seul échec, 36 skips, 6 incomplets**,
  **0** contamination Mix. L'unique échec reste la **sentinelle frozen-zone
  connue** (§2.1), hashes inchangés → **zéro régression** introduite par ce lot
  (passe précédente : 6 138 / 1 échec ; +6 tests neufs, mêmes compteurs).
- **Vitest complet** : **563 fichiers, 4 561 passés, 3 skips, 0 échec**.
- Suites ciblées : `Feature/Pos` **392/392**, `Feature/Settings` **49/49**,
  `Feature/Onboarding` **226** (4 incomplets documentés), `Feature/Hardware`
  **164/164**.
- Voisins **restaurés au vert** après retrait du garde P1-19 : `QuoteTamperTest`
  4/4, `QuoteBindingTest` 5/5, `PricingServiceTest` 23/23, `PosParkedOrderTest`
  8/8, `ParkedOrderAdminBranchZeroSentinelTest` 5/5.
- Garde-fous release verts. **Diff zone gelée §7 contre la base : VIDE.**
- Banc `tpeBoutonsNommes` **prouvé mordant** (aria-label neutralisés → 3 échecs,
  restaurés → 5/5).

**Bilan des deux lots : 13 défauts corrigés avec preuves, 13 points escaladés,
aucune zone gelée touchée, aucune baseline modifiée, aucun test rendu vert en
affaiblissant son assertion.**

---

## 9. DÉPLOYÉ EN PRODUCTION — fait et vérifié (2026-09-28)

**Production : HEAD `77ed8de2`, contenant les correctifs (`90474f870` prouvé
ancêtre du HEAD déployé, vérifié sur le serveur).**

### Ce qui a été évalué AVANT de déployer

La production était **210 commits en retard** sur la branche. Vérifié avant
d'agir : ces 210 commits sont **tous `docs` (156) et `qa` (55)** — **zéro
fichier de code applicatif** hors `reports/docs/plans/tests`, et **zéro
migration**. La production n'avait donc aucun retard fonctionnel : ce
déploiement ne livre que les correctifs de cette branche. C'est ce constat qui a
rendu l'opération cadrée plutôt qu'aveugle.

### Fusion

`tools/deploy-lecayenne.sh` cible `pos/category-first-caisse-2026-06-23` en dur,
donc fusion préalable. Les seuls conflits sont les deux rapports QA que la
session parallèle alimente en continu — résolus **hunk par hunk en gardant les
deux côtés** (leurs observations chronologiques, puis ma section de clôture),
vérifiés par script : deux blocs intégralement présents, zéro marqueur résiduel.
Le distant a avancé trois fois pendant l'opération ; il a fallu resserrer le
cycle fusion→push pour gagner la course.

### Résultat du déploiement

```
== NF525 chain : SWEEP COMPLETE — CHAIN OK on every active branch (1 total)
== couverture queues OK — chaque file non vide a un worker qui l'écoute
== bundles complets (fraîcheur prouvée par la gate hash-servi)
== healthz loopback https://127.0.0.1:443 → 200
== contenu OK : /kiosk/idle sert app.js?id=d19e6551… (bundle frais)
== CORS OK : site-lecayenne.vercel.app reçoit Access-Control-Allow-Origin
== ✅ Déploiement OK — HEAD 77ed8de2 · triggers vérifiés · healthz vert ·
   contenu frais · CORS web OK · couverture queues OK.
```

`REVIEWED_SHA=90474f870` a été passé au script : il **avorte** si le HEAD
déployé ne contient pas ce commit. Il ne l'a pas fait.

### Vérification indépendante du CODE SERVI

Le script dit « OK » ; j'ai vérifié **moi-même ce que la production sert**, en
récupérant les bundles depuis le manifest public :

| Correctif | Marqueur cherché dans le bundle SERVI | Résultat |
|---|---|---|
| P1-11 « Sans sauce » (argent) | `aucune sauce` / `pas de sauce` dans `kiosk-wizard-step.02a2779b.js` | **présent** |
| P0-18 numéro court daté | `enc-queue-date-badge` dans `admin-shell.3bff92d5.js` | **présent** |
| P1-30 rupture au Catalogue | `catalog-studio-rupture-pill` | **présent** |
| P1-49 actions TPE nommées | `payment-terminal-delete-` | **présent** |

`/api/healthz` après déploiement : **HTTP 200**, `db`/`redis`/`websocket`/
`fiscal_chain` = `ok`, `queue_pending` = 0 — identique à la base de comparaison
capturée avant l'opération.

### ⚠️ Anomalie relevée DANS le script de déploiement (non corrigée)

```
bash: line 120: [: WRONGTYPE Operation against a key holding the wrong kind of
value: integer expression expected
```

Le contrôle de profondeur de file compare un message d'erreur Redis
(`WRONGTYPE`) comme s'il s'agissait d'un entier : une clé Redis n'a pas le type
attendu par la commande utilisée. Conséquence : **ce contrôle-là ne contrôle
rien** sur cette clé — il ne casse pas le déploiement, mais il ne peut pas non
plus signaler une file qui s'accumule. Non corrigé ici (hors périmètre des trois
rapports, et le script est l'outil qui venait de déployer — le modifier dans la
même passe aurait été imprudent). **À traiter.**

### Reste hors de portée

Le **site vitrine** (`lecayenne.fr`) n'est PAS concerné par ce déploiement : il
vit dans un dépôt séparé, son arbre porte 15+ fichiers non commités d'un autre
travail, et l'action B qui le visait est **fautive** (§6). Rien n'y a été touché.

---

## 10. État réel des campagnes E2E — et le prérequis qui manquait

Une fois la collecte réparée (§ ci-dessus) et les 28 specs recâblées sur l'arbre
configuré, les campagnes deviennent lisibles. Elles l'étaient d'autant moins que
**le diffuseur temps-réel n'était pas démarré** et que rien ne le prescrit dans
le chemin E2E courant.

### Le partage environnement / produit, mesuré

| Surface | Sans diffuseur temps-réel | Avec `soketi` sur `:6001` |
|---|---|---|
| E2E critique (`test:e2e:smoke`) | **22/22** | **22/22** |
| Caisse / POS (7 fichiers) | **9/9** | **9/9** |
| KDS / cuisine | 7 verts, **7 échecs** | **11 verts, 3 échecs** |
| Tracker · synchro · wizard · promo | 5 verts, **5 échecs** | **7 verts, 3 échecs** |
| Borne | 11 verts, 1 échec | 11 verts, **1 échec** |

**6 des 13 échecs initiaux étaient purement environnementaux** : un diffuseur
Pusher absent sur `:6001`, dont l'absence se lisait comme un défaut produit
(`WebSocket connection … ERR_CONNECTION_REFUSED` dans la console, puis une
assertion métier qui tombe).

### Le prérequis à documenter

Le dépôt a déjà l'outil : `scripts/ci-bootstrap-websockets-harness.sh`, dont
l'en-tête explique précisément le risque (« sans un vrai serveur protocole
Pusher sur 6001, le chemin de diffusion n'est JAMAIS exercé »). `soketi` est
installé sur cette machine et `soketi.json` est versionné ; un
`soketi start --config=soketi.json` suffit.

**Ce qui manque n'est pas l'outil, c'est la prescription** : ni
`npm run test:e2e:smoke` ni `test:e2e:full` ne l'exigent ni ne le signalent, et
une spec qui échoue faute de diffuseur ne le dit pas — elle échoue sur une
assertion métier. D'où 6 faux signalements sur 13.

### Les 7 échecs restants — listés, PAS rafistolés

- **Borne** `borne-e2e-logique-2026-07-21` — « un supplément payant doit être
  sélectionné ». **Préexistant, prouvé** : échoue à l'identique avec mes deux
  correctifs borne remis à la version de base `d9a95ac77` et recompilés. Reste
  rouge avec le diffuseur actif, donc ce n'est pas l'environnement. La spec
  clique des tuiles **à l'aveugle** (`rows[0]` en repli) : à qualifier —
  obsolescence de sélecteur ou vrai défaut de l'étape suppléments.
- **KDS** `KdsMultiScreenPlaywrightTest` — assère `toContain('Voir plus')` sur
  `KitchenDisplaySystemComponent.vue`. **Obsolescence prouvée** : tourne en
  22 ms **sans navigateur**, ne dépend d'aucune URL, et lit deux fichiers que je
  n'ai pas touchés — elle échouait donc déjà. C'est un test de contrat de source
  déguisé en spec Playwright.
- **5 autres** répartis sur KDS et tracker/synchro/wizard/promo, à qualifier un
  par un.

**Pourquoi je ne les corrige pas dans cette passe** : réécrire une assertion
obsolète sans comprendre le changement produit qui l'a périmée reviendrait à
**masquer une éventuelle régression réelle** — exactement le défaut que ce
rapport reproche ailleurs. Chacun mérite le même traitement que les défauts
corrigés plus haut : reproduire, qualifier, puis décider.

### Recommandation

1. Faire de `soketi` un **prérequis explicite** du chemin E2E (démarrage dans
   les scripts `test:e2e:*`, ou échec immédiat avec un message clair si `:6001`
   ne répond pas). Sans ça, 6 échecs sur 13 sont du bruit, et le bruit finit par
   faire ignorer les 7 vrais.
2. Qualifier les 7 restants un par un, en commençant par la borne (c'est la
   seule qui touche un parcours client payant).

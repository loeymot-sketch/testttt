# GOAL — FINITION LE CAYENNE · 2026-08-13

> Plan de finition écrit après une journée de correction et de supervision visuelle réelle.
> **Chaque chantier est ancré sur un fichier vérifié et chiffré sur la PRODUCTION**, pas sur une
> impression. Les nombres ci-dessous ont été mesurés le 2026-08-13 sur la base du VPS.

---

## §0 — LA RÈGLE QUI GOUVERNE CE PLAN

Une leçon revient dans chaque incident de la journée, et elle ordonne tout le reste :

> **Ce qui n'est pas mesuré n'est pas connu ; ce qui n'est pas regardé n'est pas fini.**

Trois faits de la journée l'établissent :
- j'ai annoncé « **940,30 € invisibles au Z** » — **faux** : le Z ne lit jamais cette table ;
- j'ai annoncé « **NF525 CHAIN OK** » comme preuve de déploiement — **vrai mais incomplet** : cette
  commande ne teste pas le chemin qui bloquait la clôture, et le Z n'a pas pu s'ouvrir **17 jours** ;
- trois agents d'audit ont mesuré la base de **dév** en la présentant comme la production
  (7 compteurs sur 10 divergents), et un a confondu **payée** et **remboursée**.

**Conséquence pour ce plan** : aucun chantier n'entre ici sans (a) un fichier vérifié, (b) un
chiffre de production, (c) un critère d'acceptation nommant un test ou une capture.

---

## §1 — ÉTAT MESURÉ EN PRODUCTION (2026-08-13)

| Fait mesuré | Valeur | Lecture |
|---|---|---|
| Ventes Uber payées **sans numéro fiscal** | **15** | Décision owner assumée (`UBER_FISCALIZE=false`) |
| Sessions de caisse **ouvertes / closes** | **2 / 0** | **Aucun rapprochement de tiroir n'a jamais eu lieu** |
| Ventes payées **sans mode de paiement** | **19** | Échappent à toute ventilation espèces/carte |
| Adhérents fidélité / ventes rattachées | **25 / 0** | Fonction livrée, **jamais utilisée au comptoir** |
| Lignes Uber tombées dans l'article fourre-tout | **7** | **Données de test Uber**, pas des ventes réelles |
| Bornes enregistrées en base | **1** | La base est bonne ; les identifiants serveur manquent |
| Tours de roue / cadeaux non remis | **1 / 1** | Jeu fermé au public (`WHEEL_ENABLED=false`) |
| Chaîne d'audit NF525 | **902 lignes, 0 irréductible** | Saine — vérifiée à la main, secret par secret |

**Ce que ces chiffres disent, et qu'aucune impression ne disait** : le logiciel n'a pas de problème
de *code* majeur. Il a un problème d'**usage** — des fonctions livrées que personne n'a encore
allumées — et deux ou trois trous d'exploitation réels (tiroir jamais clôturé, mode de paiement
absent). C'est ce que ce plan corrige, dans cet ordre.

---

## §2 — VAGUE 1 · CE QUI COÛTE DE L'ARGENT MAINTENANT

### 1.1 — Le tiroir-caisse n'est jamais clôturé (P1)
- **Mesuré** : 2 sessions ouvertes, **0 close**, 237 entrées pour 3 818,30 €, **zéro variance calculée**.
- **Ancrage** : `app/Services/Cash/CashDrawerService.php`, `app/Http/Controllers/Admin/CashSessionReportController.php:77`
- **Conséquence** : personne ne sait si la caisse tombe juste. Un écart de caisse est aujourd'hui
  invisible — pas « mal calculé » : **jamais calculé**.
- **Acceptation** : une clôture réelle produit un écart chiffré, visible à l'écran ;
  `tests/Feature/Cash/` reste vert (93) ; **test à créer** :
  `tests/Feature/Cash/CashDrawerCloseVarianceTest.php`.
- **Gate** : non — c'est de l'exploitation, pas du fiscal gelé.

### 1.2 — 19 ventes payées sans mode de paiement (P1)
- **Mesuré** : 19 lignes, `pos_payment_method IS NULL`.
- **Ancrage** : `app/Services/Fiscal/ZReportService.php:787-793` (`applyOrderToTotals`) — range ces
  ventes sous une clé héritée ou `unknown`.
- **Conséquence** : elles n'apparaissent **dans aucune colonne** de la ventilation espèces/carte du
  Z **signé** et du PDF archivé 6 ans. Le total reste juste ; la répartition ment.
- **Acceptation** : plus aucune vente payée sans mode ; **test à créer**
  `tests/Feature/Fiscal/ZReportUnknownMethodSentinelTest.php` qui échoue si le cas réapparaît.
- ⚠️ **`ZReportService` est en ZONE GELÉE §7** → corriger **en amont** (à la création de la vente),
  jamais dans le service fiscal. Si le correctif devait toucher le service : **LOCK + gate owner**.

### 1.3 — Ventilation fausse sur paiement mixte (P1 code / P3 réel)
- **Mesuré** : **0 ligne concernée en production** (`order_payments` est vide). Défaut réel en dév.
- **Ancrage** : `ZReportService.php:792`, jumeau `app/Services/DashboardService.php:739-747`,
  verrou existant `plans/LOCK_ZREPORT_SPLIT_BUCKETING_M6-002_2026-07-04.md`.
- ⚠️ **Le test de verrouillage `tests/Feature/Fiscal/ZReportSplitBucketingLockTest.php` est SKIPPÉ** :
  « Fiscal 302 vert » **ne prouve rien** sur ce point. Le dire à chaque revue.
- **Priorité : BASSE tant que le paiement mixte n'est pas utilisé.** Piège armé, pas fuite.

---

## §3 — VAGUE 2 · CE QUI EST LIVRÉ MAIS QUE PERSONNE N'UTILISE

**C'est le plus gros gisement du projet, et il ne se corrige pas en codant.**

### 2.1 — Fidélité : 25 adhérents, 0 vente rattachée
- **Ancrage** : `app/Services/Loyalty/PosLoyaltyAttachService.php`,
  `resources/js/components/admin/pos/PosLoyaltyIdentifyModal.vue` — prouvés par
  `tests/Feature/Pos/PosLoyaltyAttachTest.php` (13 verts).
- **Le code marche.** Ce qui manque : (a) le barème n'est pas réglé, (b) l'équipe n'a pas le geste.
- **Action** : régler le barème (**gate owner G1**), puis **une carte d'une page** pour le comptoir :
  « chercher par téléphone → rattacher → les points arrivent ».
- **Acceptation** : au moins **1 vente rattachée** en production, vérifiée en base.

### 2.2 — La fiche client admin n'affiche aucun point
- **Ancrage vérifié** : `resources/js/components/admin/customers/CustomerListComponent.vue` (colonnes
  name/email/phone/status), `CustomerShowComponent.vue` — **0 occurrence** de `loyalty`.
  Le solde est pourtant déjà servi par `app/Http/Resources/UserResource.php:38`.
- **Conséquence** : impossible de répondre à « pourquoi ce client a 2400 points ? », et **aucun
  geste commercial** possible (ajouter des points à la main n'existe nulle part).
- **Acceptation** : colonne « points » + lien vers l'historique ; **test à créer**
  `tests/Feature/Admin/CustomerLoyaltyColumnTest.php`.

### 2.3 — La borne renvoie les clients en caisse
- **Mesuré** : **1 borne existe en base**, mais l'écran répond « connexion machine non configurée ».
  Ce sont les identifiants **côté serveur** qui manquent, pas la fiche.
- **Ancrage** : `resources/js/components/frontend/kiosk/KioskLoginComponent.vue:99-111` — le
  diagnostic complet est déjà écrit dans la console du navigateur.
- **Action** : renseigner `KIOSK_MACHINE_*` puis `php artisan foodking:ensure-kiosk-machine`.
- **Gate owner G2** — c'est une configuration machine, pas du code.

---

## §4 — VAGUE 3 · LE PARCOURS DE LA ROUE, JUSQU'AU BOUT

Le jeu est **fermé au public** (`WHEEL_ENABLED=false`) : cette vague le rend digne d'être ouvert.

### 3.1 — L'abonnement doit débloquer le tour (même logique que l'avis)
- **Ancrage** : `roue.html` (dépôt `lecayenne-web-deploy`), fonctions `ouvrirEtape` /
  `compteRebours` — **déjà capables d'enchaîner** depuis le correctif d'aujourd'hui (4ᵉ paramètre).
- **Reste à faire** : brancher `versFollow()` sur le même mécanisme, avec sa propre durée serveur.
- **Acceptation** : capture du parcours ; le bouton de tour reste inactif pendant le décompte.

### 3.2 — Ne plus annoncer « une dernière étape » à la première
- **Ancrage** : `roue.html`, `<h2>Une dernière petite étape</h2>` (écran 2).
- **Pourquoi ça compte** : annoncer un reste de chemin à quelqu'un qui vient d'en faire un est la
  façon la plus simple de le perdre. Le titre doit dire ce qu'on gagne, pas ce qu'il reste à subir.

### 3.3 — La fin de parcours : compte, conditions, cadeau, mail
- **Ancrage** : `app/Http/Controllers/Frontend/WheelController.php:331` (`envoyerLeLot`),
  `app/Mail/WheelPrizeMail.php` — **déjà câblé et déjà parti une fois en production**.
- **Reste à faire** : vérifier de bout en bout que le compte de fidélité est créé, que les
  conditions générales sont acceptées, et que le mail porte le **minimum d'achat**.
- **Acceptation** : un parcours réel joué en entier, avec la capture du mail reçu.

### 3.4 — Liste des cadeaux en attente, en tête d'écran
- **Ancrage** : `app/Services/Wheel/WheelReportService.php` (`historique`, `parcoursIncomplets` —
  ajoutée aujourd'hui), `resources/views/admin/wheel/historique.blade.php`.
- **Reste à faire** : un bloc « N cadeaux vous attendent » en tête, et le compteur sur l'accueil.

### 3.5 — Le portrait de la vitrine : la roue ne se dessine pas
- ⛔ **QUATRE tentatives ont échoué** (voir le commentaire dans `borne.blade.php`). La roue y est
  **masquée volontairement**. **Ne pas réessayer en réglant des valeurs** : reproduire d'abord le
  canevas vide en portrait dans un cas isolé. Coût réel faible — la tablette est en paysage.

---

## §5 — VAGUE 4 · UBER, ET LA DÉCISION QUI LA PRÉCÈDE

### 4.1 — Fiscaliser ou non les ventes Uber (**décision owner G3**)
- **Mesuré** : **15 ventes payées sans numéro fiscal**, 100 % du canal.
- **Ancrage** : `config/uber.php:36-39` — « Décisions métier, à trancher par l'owner : les ventes
  Uber entrent-elles dans le Z ? Défaut NON, Uber facture à part. »
- **Ce n'est PAS un défaut.** C'est un interrupteur (`UBER_FISCALIZE`) et une question de comptable.

### 4.2 — La table de correspondance produits
- **Ancrage** : `config/uber_menu_map.php` — un avertissement y a été écrit aujourd'hui.
- ⛔ **Ne jamais la remplir depuis les libellés observés en base** : les 7 lignes « non mappées »
  sont du **bac à sable Uber** (« Best Burger » à 1,00 € en quantité 23, à 3 h du matin). La
  remplir avec ça graverait des données de test en production.
- **Action** : la remplir depuis **l'export réel du back-office Uber** — **gate owner G4**.

---

## §6 — VAGUE 5 · LES MATIÈRES PREMIÈRES, PORTE EN ÉCRITURE SEULE

- **Mesuré** : 3 141 mouvements de matière écrits par les ventes ; **aucun moyen de corriger à la
  main** après une casse, un vol ou une pesée fausse.
- **Ancrage** : `app/Services/RawMaterials/RawMaterialStockService.php:91` (`adjust()` — **écrite,
  testée, sans aucun appelant**). Le scan de facture (`PurchasingScanController`) fait entrer, les
  ventes font sortir, rien ne redresse.
- **Acceptation** : un écran d'inventaire branché sur `adjust()` ; **test à créer**
  `tests/Feature/RawMaterials/RawMaterialAdjustEndpointTest.php`.

---

## §G — PORTES PROPRIÉTAIRE (WHO / WHAT / WHERE)

| # | Décision | QUI | QUOI | OÙ |
|---|---|---|---|---|
| G1 | Barème fidélité (plancher 1000) | Propriétaire | 3 valeurs | `/admin/settings/loyalty-setup` |
| G2 | Identifiants machine de la borne | Propriétaire | `KIOSK_MACHINE_*` | `.env` du VPS |
| G3 | Ventes Uber dans le Z, oui ou non | Propriétaire **+ comptable** | `UBER_FISCALIZE` | `.env` du VPS |
| G4 | Carte Uber réelle | Propriétaire | export back-office | `config/uber_menu_map.php` |
| G5 | Ouvrir la roue au public | Propriétaire | `WHEEL_ENABLED` **+ liens vérifiés** | `.env` + `/admin/roue-reglages` |
| G6 | Le compte Facebook de l'abonnement | Propriétaire | vérifier que `facebook.com/LeCayenne` est **le sien** | `/admin/roue-reglages` |

⚠️ **G6 est le plus urgent des six** : si cette page n'est pas la tienne, tu offres un cheeseburger
à des clients pour qu'ils s'abonnent au compte **de quelqu'un d'autre**.

---

## §X — ORDRE D'EXÉCUTION, ET POURQUOI CET ORDRE

1. **G1, G2, G6** — trois réglages, zéro ligne de code, effet immédiat. Rien ne justifie d'écrire
   du code avant d'avoir allumé ce qui est déjà livré.
2. **Vague 1** (tiroir, mode de paiement) — le seul argent réellement en jeu aujourd'hui.
3. **Vague 2** (fidélité utilisée, fiche client) — transforme une fonction morte en chiffre.
4. **Vague 3** (parcours de la roue) — à finir **avant** G5, jamais après.
5. **Vagues 4-5** — après décision comptable et carte réelle.

**Point de contrôle entre chaque vague** : suites vertes, zones gelées à 0 ligne, chaîne NF525
vérifiée **par les deux vérificateurs** (l'un disait OK pendant que l'autre bloquait), et une
**capture d'écran lue** pour tout ce qui touche une surface.

---

## §F — RÈGLE FINALE

**Aucune vague n'est close sur une impression.** Un chiffre de production ou une capture, sinon
c'est en cours. Et quand deux mesures se contredisent, on examine **le juge**, pas la preuve —
c'est ce qui a fait retrouver 17 jours de Z bloqués que personne ne voyait.

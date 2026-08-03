# DIAG — Parité profonde SITE WEB ↔ BORNE (kiosk)

**Date** : 2026-07-19 · **Mode** : AUDIT READ-ONLY (aucune modif) · **Auteur** : audit parité
**Backend** : `testttt` (:8000, DB `foodking_e2e`) — SSOT = table `items` + API `/api/frontend/*`
**Web** : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/`
**Question owner** : « borne et web = presque la même interface, mêmes personnalisations, mêmes choix, même logique, **même synchronisation** ; reliés caisse/gestion/cuisine/suivi. »

---

## 0. TL;DR (verdict par axe)

| Axe | Sujet | Verdict | Écarts |
|---|---|---|---|
| 1 | Catalogue (produits/prix/catégories) | ✅ **PARITÉ TOTALE aujourd'hui** | 0 |
| 2 | Choix / personnalisation par item | 🟡 quasi-parité | **3 gaps (P2)** |
| 3 | Logique (min/max, 1ʳᵉ gratuite, inclus/supp, tailles, formules) | ✅ **ALIGNÉ** | 0 |
| 4 | **Synchronisation temps réel** | 🔴 **DIVERGENCE MAJEURE** | **3× P1 + 2× P2** |
| 5 | Interface / étapes wizard | 🟢 forte parité | mineurs (dérivés axe 2) |

**Le cœur du problème owner = AXE 4.** Le catalogue web est **100 % statique** (`data/menu.js`).
La borne lit le backend **en direct** + reçoit les events de diffusion (`ItemAvailabilityChanged`).
Le web ne lit RIEN en direct pour l'affichage → **aucun** changement gestion (prix, 86/rupture,
nouveau produit) n'atteint le web tant que `menu.js` n'est pas redéployé à la main.

**Bonne nouvelle (déjà acquis, confirmé)** : le PRIX facturé et la composition restent SSOT backend
(le web résout au submit via `/api/frontend/item/details` et n'envoie jamais de prix). Le garde
`expected_total` (DROP-FIX 2026-07-19) empêche toute sur/sous-facturation silencieuse. Donc la
dérive de prix ne fait **pas** payer faux — mais elle **bloque** la commande web (422), voir S1/S3.

---

## AXE 1 — Catalogue (data web `menu.js` vs backend `items`) — ✅ PARITÉ TOTALE

### Catégories : 9 = 9 (MATCH)
Backend catégories `status=5` (visibles) : Sandwichs(1), Galette(2), Burgers(4), Tacos(5), Bols(6),
Frites(7), Desserts(9), Boissons(10), Menu enfant(11) = **9**.
Web `menu.js:270-280` = exactement les **9** mêmes (ids backend préservés). Les catégories backend
masquées (`Sandwich Classique`=3, `Suppléments`=8, `Tacos Signature`=21, `Technique upsell`=27,
+ ~20 catégories `status=1` en latin faker) ne sont ni sur borne ni sur web. **MATCH.**

### Produits & prix : 38 web ≡ 42 SKU backend visibles (MATCH, 0 écart de prix)
Les 42 SKU backend `status=5` = 38 « produits » web + 4 variantes frites pré-composées (voir note).
Comparaison exhaustive prix (web `menu.js` vs backend `items.price`) :

| Catégorie | Items (prix web = prix backend) | Écart |
|---|---|---|
| Sandwichs | Cayenne 7,40 · Suprême 7,00 · Méga 8,00 · Terminator 9,00 | 0 |
| Galette | Normale 6,50 · Cayenne 7,00 | 0 |
| Burgers | Chicken 4,90 · Cheese 6,00 · Double Cheese 7,00 · Fish 6,00 · Big 9,00 · Grill 8,00 | 0 |
| Tacos | M 6,90 · L 7,90 | 0 |
| Bols | Bol Frites 7,90 · Bol Riz 7,90 | 0 |
| Frites | Petite 2,50 · Grande 4,00 (+ styles, voir note) | 0 |
| Desserts | Glace / Tarte Daim / Tiramisu 3,50 ×3 | 0 |
| Boissons | 15 boissons : canettes 1,90 · Eau 1,00 · Capri-Sun 1,50 | 0 |
| Menu enfant | Nuggets 4,90 · Chicken Burger 4,90 | 0 |

**0 produit fantôme, 0 produit manquant, 0 écart de prix aujourd'hui.**

> **Note frites (différence de modélisation, prix équivalent)** : la borne expose **6 SKU** frites
> (Petite/Grande + 4 variantes Cheddar pré-composées, items 107-110). Le web expose **2** items +
> une étape « style » client (`FRITES_STYLES` Nature/Cheddar +1,00/Cheddar+Oignons +2,00,
> `menu.js:227-231`). Résultat identique : 2,50/3,50/4,50 (Petite) et 4,00/5,00/6,00 (Grande), et
> au submit le web **résout vers le bon SKU par nom** (`api.js:340-345` → item 107-110). Aucun écart.

> ⚠️ **La parité de l'axe 1 est un INSTANTANÉ, pas une garantie** : elle tient uniquement parce que
> `menu.js` a été ré-aligné manuellement. Rien n'empêche la dérive (voir AXE 4).

---

## AXE 2 — Choix / personnalisation par item — 🟡 3 gaps (tous P2)

Méthode : `GET /api/frontend/item/details/{id}` (endpoint que consomme la borne) sur 22 items
composables, comparé aux pools statiques web + aux étapes rendues par `wizard-v2.jsx`.
La quasi-totalité des choix est alignée (sauces ×12, viandes ×7, pain ×2, crudités, suppléments
×9 @0,90, viande supp +2,50, sauce supp +0,50). **3 divergences réelles** :

### D1 (P2) — Galette : crudité « Cornichon » offerte borne, ABSENTE web
- **Borne** : items 23 (Galette Normale) & 24 (Galette Cayenne) exposent l'extra **`Cornichon`**
  (+0, `group=crudite`) — vérifié `GET /api/frontend/item/details/23` et `/24`. Les
  sandwichs/burgers/tacos backend n'ont PAS de cornichon (galette-only).
- **Web** : pool `CRUDITES` global = Salade/Tomate/Oignon/Oignons cuits (`menu.js:153-158`) — **pas de
  cornichon**, et le pool est global (donc jamais proposé, même sur galette).
- **Impact** : le client web ne peut pas ajouter du cornichon (option gratuite) sur une galette,
  contrairement à la borne. Pas d'impact prix → **P2** (incohérence sans impact commande).

### D2 (P2) — Bol Frites : « Option Gratiné » +2,00 offerte borne, ABSENTE web
- **Borne** : item 41 (Bol Frites) expose l'extra **`Option Gratiné` +2,00** (`group=supplement_bol`) —
  vérifié `GET /api/frontend/item/details/41`. (Item 45 Bol Riz l'a aussi.)
- **Web** : gratiné réservé au **Bol Riz uniquement** — `sb-gratine` porte `riz_only:true`
  (`menu.js:198`), Bol Frites (601) n'a pas `has_gratine` (`menu.js:446-449`), et le wizard filtre
  `riz_only` (`wizard-v2.jsx:61-67`, `wizard-v2.jsx:204`).
- **Impact** : le client web ne peut pas gratiner un Bol Frites (+2,00) là où la borne le permet.
  Option **payante** manquante côté web (upsell perdu), mais pas de mauvaise facturation → **P2**.
  *(À arbitrer owner : gratiné sur bol frites est-il voulu ? Si non, c'est la BORNE qu'il faut corriger.)*

### D3 (P2) — Menu Enfant : options borne (sauce / crudités / suppléments) ABSENTES web
- **Borne** : Menu Enfant Nuggets (40) expose **Sauce** (attr 5 « Sauce (1ère Gratuite) » min1/max1,
  12 choix) + « Sauce supplémentaire ». Menu Enfant Chicken Burger (106) expose **3 crudités +
  9 suppléments @0,90**. Vérifié `details/40` et `details/106`.
- **Web** : les 2 menus enfant sont `has_sauce:false / has_crudites:false / has_supplements:false`
  (`menu.js:495-503`) → template `simple` → **aucune étape** (DirectAdd, `wizard-v2.jsx:257-260`,
  `405-407`). De plus, le top-up `min_select` (`api.js:495-504`) **auto-remplit en silence** la
  sauce nuggets avec la 1ʳᵉ option (Algérienne) — le client web n'est jamais consulté.
- **Impact** : personnalisation du menu enfant beaucoup plus pauvre sur web ; la sauce du kid est
  imposée (Algérienne) au lieu d'être choisie. Sauce = gratuit ; suppléments = payants non proposés
  (upsell perdu). Pas de mauvaise facturation → **P2**.
  *(Caveat : le rendu borne du template `simple` avec attribut `min_select` n'a pas été observé
  visuellement ici — mais la DONNÉE backend expose bien ces options ; le web, lui, n'en propose aucune.)*

---

## AXE 3 — Logique (min/max, 1ʳᵉ gratuite, inclus vs supplément, tailles, formules) — ✅ ALIGNÉ

| Règle | Borne (backend) | Web | Verdict |
|---|---|---|---|
| **Viande incluse vs supp** | N viandes incluses (attr `Viande 1/2`, min1/max1, gratuites) ; surplus = extra `Viande supplémentaire` +2,50 | idem : N 1ʳᵉs → variations gratuites, surplus → extra +2,50 (`api.js:369-385,444-454` ; `menu.js:210,520`) | ✅ |
| **Nb viandes / item** | Cayenne/Suprême 0 · Galette/Tacos M/Bols 1 · Méga/Terminator/Tacos L 2 | identique (`viande_count` `menu.js`) | ✅ |
| **Sauce 1ʳᵉ gratuite** | attr 5/8 min1/max1 (1 sauce incluse) + extra `Sauce supplémentaire` +0,50 | 1ʳᵉ → variation gratuite, suivantes → extra +0,50 (`api.js:394-414` ; `menu.js:214,523-524`) | ✅ (billing identique ; UX diff, voir axe 5) |
| **Choix du pain** | attr 6 `Type de Pain` seulement sur Sandwichs (22,103,104,105) | `has_pain_choice` seulement sur Sandwichs (`menu.js:377..391`) — pas galette/burger/tacos | ✅ |
| **Taille L > M** | Tacos M 6,90 (1v) / L 7,90 (2v) ; Frites Petite/Grande | identique | ✅ |
| **Formule menu** | addon `menu_component` prix = **2,50 base × ratio** (full 1.0 / frites 0.6 / drink 0.4) = **2,50 / 1,50 / 1,00** (`PricingService.php:793-813` + `config/kiosk.php:181-183`) | `FORMULES` = 2,50 / 1,50 / 1,00 (`menu.js:222-224`) ; envoi role `menu_full/frites/boisson` (`api.js:456-476`) | ✅ **MATCH exact** |
| **Qui a la formule** | `has_menu=true` : sandwich/galette/burger/tacos ; false : bol/frites/dessert/boisson/enfant | `has_menu_addon` idem (`menu.js`) | ✅ |
| **Suppléments / extra meat / gratiné** | +0,90 / +2,50 / +2,00 | +0,90 / +2,50 / +2,00 | ✅ |

> Note : le comment `menu.js:134-135` (« 1 sauce max, cascade +0,50 SUPPRIMÉE ») est **périmé** vs le
> code réel (heal 2026-07-15) qui autorise jusqu'à 4 sauces (1ʳᵉ gratuite + 0,50/sauce). Le CODE est
> aligné backend ; seul le commentaire ment. Cosmétique doc → non compté comme divergence.

---

## AXE 4 — SYNCHRONISATION TEMPS RÉEL — 🔴 DIVERGENCE MAJEURE (3× P1 + 2× P2)

### Architecture constatée (preuve)
- **Web = affichage 100 % statique** : catalogue, prix, descriptions, pools d'options viennent tous
  de `window.LC.menu` (`data/menu.js`), chargé avant `api.js`. **Aucun** GET catalogue/prix/dispo au
  runtime. Les 2 SEULS `fetch()` de toute l'app : géocodage Nominatim (`api.js:113`) et le wrapper
  `req` backend (`api.js:169`). Les 2 GET items (`/api/frontend/item` `api.js:218` ;
  `/api/frontend/item/details/{id}` `api.js:231`) ne servent **QU'au submit** pour résoudre nom→id.
  → **Aucune notion de disponibilité/86 dans le web** (aucun flag, aucun canal, aucun fetch).
- **Borne = live** : lit le backend à chaque chargement **ET** reçoit les diffusions temps réel
  `ItemAvailabilityChanged` / `ItemExtraAvailabilityChanged` / `ItemVariationAvailabilityChanged`
  sur le canal de la branche (`AvailabilityService.php:427,672,683`, outbox+broadcast).

### Ce qui NE se propage PAS au web (quantifié)

| # | Changement gestion (admin) | Borne | Web | Effet web | Grav. |
|---|---|---|---|---|---|
| **S1** | **Change de PRIX** d'un item | live | prix **périmé** affiché | + toute commande web contenant l'item = **REJETÉE 422** : le web envoie `expected_total` (`api.js:579`) et `FrontendOrderService.php:580-588` lève 422 si \|serveur − attendu\| > 0,01 €. Vrai dans les 2 sens (hausse ou baisse). | **P1** |
| **S2** | **86 / rupture** (item indisponible) | grisé temps réel (broadcast) | item **toujours affiché commandable** ; le client compose toute la commande puis se fait **rejeter au submit** (`PricingService.php:47-55` → `AvailabilityService.php:216-232` lève `InvalidArgumentException`) | **P1** |
| **S3** | **Change prix d'une OPTION** (supplément 0,90→x, viande supp, gratiné, sauce supp) | live | option périmée affichée + même 422 `expected_total` que S1 | **P1** |
| **S4** | **Nouveau / retrait produit, renommage / réordonnancement catégorie** | live | web ne voit pas le nouveau, liste encore l'ancien, noms/ordre catégories périmés (`menu.js:270-280`) | **P2** |
| **S5** | **86 d'une option seule** (une sauce / un extra en rupture — `ItemExtra/VariationAvailabilityChanged`) | grisé temps réel | ignoré ; résolu au submit (fail-loud si option payante introuvable) | **P2** |

**Taux de propagation des mutations menu vers le web = 0 %.** C'est la contradiction directe avec
« la même synchronisation ». La borne est câblée live + broadcast ; le web est figé au déploiement.

> Nuance importante (rassurante côté argent) : grâce au garde `expected_total` + résolution SSOT au
> submit, une dérive de prix ne fait **jamais payer faux** — elle **bloque** la commande (422). Donc
> l'impact S1/S3 est **commercial** (commandes web perdues + prix affiché faux), pas fiscal.

---

## AXE 5 — Interface / étapes wizard — 🟢 forte parité

- Le web réutilise **les mêmes noms de template** que le composer backend : `sandwich`, `tacos`,
  `burger`, `bol`, `custom`, `simple` (`wizard-v2.jsx`).
- Mêmes étapes clés : pain → viandes → sauce → crudités → suppléments → formule menu (+ cascade
  boisson / style frites / sauce frites) ; bol : viandes → sauce → suppléments → boisson → viande
  supp ; frites : style. Séquences quasi identiques à la borne.
- Différences (toutes dérivées de l'axe 2 ou de la modélisation, aucune nouvelle) :
  - **Menu Enfant** : web = DirectAdd sans étape ; borne expose sauce/suppléments → = **D3**.
  - **Frites** : étape « style » web vs liste 6 SKU borne → même résultat/prix, UX différente (pas un défaut).
  - **Sauce** : web = multi-sélection (≤4) dans une étape ; borne = 1 sauce + extra séparé → billing identique, UX différente.
  - **Galette** : pas d'option cornichon côté web → = **D1**.

---

## RECOMMANDATION D'ARCHITECTURE POUR LA SYNCHRO (décision owner)

Le web fait DÉJÀ des appels backend live pour commander/auth/fidélité et **résout la composition
contre `/api/frontend/item/details` au submit**. Le mandat « web STANDALONE, no API wireup » est donc
déjà dépassé pour le chemin commande. Il reste à l'étendre à **l'AFFICHAGE**. 3 options :

### Option A — Statu quo (menu.js statique, ré-aligné à la main)
- ➕ Chargement instantané, zéro dépendance runtime, offline-friendly.
- ➖ Dérive garantie ; S1/S2/S3 (P1) persistent : 86 & changements de prix cassent la commande web
  (422) + affichent faux jusqu'au redéploiement. **Ne satisfait PAS « même synchronisation ».**

### Option B — Câbler l'affichage à l'API live (comme la borne) ★ vraie parité
- Grille = `GET /api/frontend/item` + `/api/frontend/item-category` ; options wizard = `details/{id}`
  à l'ouverture ; **abonnement au canal branche** pour le 86 temps réel. Les endpoints existent
  DÉJÀ et renvoient tout le nécessaire (`is_available`, `availability_reason`, variations, extras,
  addons) ; le chemin commande résout déjà contre eux.
- ➕ Parité + synchro temps réel réelles (prix, 86, nouveaux produits) exactement comme la borne.
- ➖ Dépendance backend joignable (prévoir fallback snapshot caché si API down) ; CORS + clé API
  publique (déjà le cas pour les commandes) ; plus d'appels réseau (mitiger cache court/CDN) ;
  refactor modéré de `wizard-v2.jsx`/`menu.js` (pools d'options par item au lieu de globaux).

### Option C — Hybride pragmatique (recommandé si on veut minimiser le refactor)
1. **Générer `menu.js` au build** depuis un export backend (`/api/frontend/item`) → supprime la
   dérive prix/produit sans passer l'affichage en full-live (⇒ tue S1/S3/S4).
2. **S'abonner au canal de diffusion `ItemAvailabilityChanged`** (le 86 est le changement le plus
   fréquent et le plus sensible) pour griser en temps réel ⇒ tue S2/S5.
- ➕ Élimine les 3 P1 avec un effort réduit, garde le chargement statique rapide.
- ➖ Nouveau produit / changement de prix nécessitent toujours un build (pas instantané, mais plus
  jamais « faux »).

**Synthèse** : si l'owner veut littéralement « la même synchronisation » → **Option B**. S'il veut le
meilleur rapport valeur/risque à court terme → **Option C** (build-from-backend + abonnement 86).
Dans tous les cas, ⚠️ **avant tout déploiement prod** : `<meta name="api-base-url">` pointe encore
`http://127.0.0.1:8766` (`index.html:11`) — le garde `api.js:42-69` le signale mais ne le corrige pas.

---

## ANNEXE — Preuves clés (fichier:ligne)
- Web statique / 2 seuls fetch : `api.js:113` (geocode), `api.js:169` (req) ; index items submit-only `api.js:218,231`.
- Garde `expected_total` → 422 : `app/Http/Controllers/Frontend/OrderController` (via) `app/Services/FrontendOrderService.php:580-588`.
- Rejet item indisponible : `app/Services/Pricing/PricingService.php:47-55` → `app/Services/Menu/AvailabilityService.php:216-232` (throw).
- Broadcast live borne : `app/Services/Menu/AvailabilityService.php:427,672,683` (`ItemAvailabilityChanged` & co).
- Prix formule = 2,50 × {1.0,0.6,0.4} : `app/Services/Pricing/PricingService.php:793-813` + `config/kiosk.php:181-183` ; miroir web `data/menu.js:222-224`.
- Résolution frites vers SKU par nom : `api.js:340-345` (SKU backend 107-110).
- D1 cornichon web absent : `data/menu.js:153-158`. D2 gratiné bol frites : `data/menu.js:198,446-449` + `wizard-v2.jsx:61-67,204`. D3 menu enfant : `data/menu.js:495-503` + `wizard-v2.jsx:257-260` + top-up `api.js:495-504`.

# Plan — Sandwich froid : scission **borne uniquement**, POS inchangé

**Date** : 2026-03-29  
**Type de test prévu** : **Kimi-test** (Vitest helpers kiosk + tests manuels borne)  
**Périmètre** : Kiosk SPA + config ; **pas** de changement d’ergonomie POS obligatoire.

---

## 1. Cause racine : « rien n’a changé » sur la borne

| Couche | Réalité |
|--------|---------|
| **API** | `GET /api/frontend/item-category` et `GET /api/frontend/item` renvoient ce qui est en **base**. Aucun paramètre `surface=kiosk` ne duplique ou ne scinde les catégories aujourd’hui (`ItemCategoryService::list` filtre générique seulement). |
| **Seed / config** | Les changements dans `config/menu.php` + `MenuSeeder` ne s’appliquent **que** si `php artisan db:seed --class=MenuSeeder` (purge + recréation) a été exécuté sur **l’environnement de la borne**. En prod sans reseed : **même menu qu’avant**. |
| **Front kiosk** | `kioskMenu` stocke catégories + items tels quels ; `kioskCategoryOrder` réordonne seulement des catégories **déjà présentes**. Si la catégorie « Sandwich froid » n’existe pas dans la réponse API, **aucun effet visible**. |

**Conclusion** : la correction « racine » pour la borne = **(A)** données alignées **ou** **(B)** logique **spécifique kiosk** qui scinde l’affichage **sans** imposer deux catégories au POS.

---

## 2. Objectif métier

| Surface | Comportement attendu |
|---------|----------------------|
| **Borne** | Deux entrées dans la colonne catégories : **Nos Sandwichs** (signatures : Cayenne, Terminator, Méga, Suprême) + **Sandwich froid** en **bas** (froid, panini, classiques pain/galette). Pas de « menu » pré-sélectionné pour la ligne froid si produit optionnel. |
| **POS / caisse** | **Une seule catégorie** « Nos Sandwichs » (ou équivalent) listant **tous** les sandwichs ensemble — **aucune** contrainte de navigation en deux catégories. |

---

## 3. Stratégies possibles

### Option 1 — Deux catégories en base (état actuel du seed)

- **Avantages** : simple pour la borne (données natives).
- **Inconvénients** : le POS voit **deux** catégories sauf si on ajoute une logique POS pour les fusionner → **ne respecte pas** « tout les sandwich ensemble » pour le caissier sans travail supplémentaire.

### Option 2 — **Recommandée** : une catégorie en base + **scission virtuelle côté kiosk**

- **Base** : tous les articles sandwich restent sous **`item_category_id` = Nos Sandwichs** (réassigner les lignes si elles ont été migrées vers « Sandwich froid »).
- **Config** : `config/kiosk.php` (ou équivalent) liste les **slugs** (ou IDs) des articles « froid / entrée de gamme », ex.  
  `sandwich_froid_slugs` => `['sandwich-froid','panini','sandwich-classique-pain','sandwich-classique-galette']`  
  (à caler sur les `items.slug` réels après seed/admin).
- **Front** :
  - Enrichir la liste des catégories **affichées** sur la borne uniquement : pour la catégorie source « Nos Sandwichs », produire **deux** entrées UI :
    - **Nos Sandwichs** → `itemsByCategory` **moins** les slugs froid ;
    - **Sandwich froid** (id **synthétique** ou clé stable ex. `kiosk:sandwich-froid:<parentId>`) → uniquement les items filtrés.
  - Router / query `?cat=` : étendre la résolution pour accepter l’id synthétique ou un paramètre `sub=froid` (décision d’implémentation à figer dans le plan d’exécution).
- **Tri** : conserver `kioskCategoryDisplayTier` **tier 3** pour la ligne dont le nom/slug contient « sandwich froid » (déjà en place côté JS).
- **POS** : continue d’utiliser les listes admin / API sans transform → **une** catégorie, **tous** les items.

### Option 3 — Endpoint API dédié borne

- `surface=kiosk` sur `item-category` qui « éclate » une catégorie en deux ressources JSON.
- **Inconvénients** : complexité backend, risque d’effets sur d’autres clients ; à réserver si Option 2 est insuffisante.

---

## 4. Plan d’exécution recommandé (Option 2)

### Phase 0 — Données

1. **Inventaire prod** : vérifier si « Sandwich froid » existe déjà comme catégorie et quels `item_category_id` ont les 4 articles.
2. **Alignement** : si split DB déjà appliqué, **migration de données** : `UPDATE items SET item_category_id = <id_nos_sandwichs> WHERE slug IN (...)` pour les 4 articles ; **supprimer** ou **désactiver** la catégorie « Sandwich froid » en base si elle ne doit plus exister pour le POS (ou la garder vide et masquer côté POS uniquement — moins propre).

### Phase 1 — Config kiosk

1. Ajouter dans `config/kiosk.php` (ou fichier dédié chargé uniquement côté build/API si besoin) la liste **`sandwich_froid_slugs`** (ou IDs).
2. Documenter dans `docs/` comment synchroniser cette liste avec l’admin (nouveau produit sandwich froid → ajout slug).

### Phase 2 — Front kiosk

1. **Helper** `partitionKioskSandwichCategory(categories, items, config)` → retourne catégories « enrichies » + map id → items filtrés (tests Vitest).
2. **`kioskMenu.js`** : après `SET_CATEGORIES` / `SET_ITEMS`, ou via **getter** `kioskDisplayCategories` utilisé uniquement par les composants catalogue, appliquer la partition **uniquement si** la catégorie parente est détectée (par slug `nos-sandwichs` ou id depuis config).
3. **`KioskCategoriesComponent`** (et toute vue utilisant `selectedCategoryId` + `itemsByCategory`) : gérer l’id **synthétique** pour la sélection et le scroll.
4. Vérifier **wizard**, **panier**, **upsell** : ils utilisent `item_category_id` réel en base — **ne pas** persister l’id synthétique en Vuex pour les commandes (uniquement UI navigation).

### Phase 3 — Nettoyage seed (cohérence repo)

1. **Revenir** à une seule clé `nos-sandwichs` dans `config/menu.php` pour les 8 articles (POS + seed cohérents).
2. Retirer la catégorie « Sandwich froid » du tableau `categories` du seed **ou** la garder uniquement si décision produit de l’utiliser ailleurs — **recommandation** : retirer pour éviter double vérité.

### Phase 4 — Tests

1. Vitest : partition + ordre tier 3 en bas.
2. Manuel : borne — 2 lignes sandwich, froid en bas ; POS — une catégorie, 8 produits visibles sans navigation supplémentaire.

---

## 5. Risques

| Risque | Mitigation |
|--------|------------|
| Id synthétique casse deep links `?cat=` | Documenter + tests route ; fallback sur catégorie parente. |
| Oubli de slug pour un nouvel article | Liste config + log console en dev si item sandwich non classé. |
| Cache offline `kioskMenuCache` | Invalider ou versionner le snapshot après déploiement. |

---

## 6. Hors périmètre (sauf demande)

- Refonte liste catégories POS pour « fusionner » deux catégories DB.
- Changement des règles de prix / wizard côté serveur.

---

## 7. Validation humaine (GO / MODIFY / STOP)

- [ ] GO — implémenter Option 2 selon phases ci-dessus.  
- [ ] MODIFY — préciser : garder 2 catégories DB + fusion POS (autre option).  
- [ ] STOP — maintenir uniquement seed deux catégories et accepter 2 catégories au POS.

---

*Référence code actuel : `ItemCategoryService::list`, `resources/js/store/modules/kioskMenu.js`, `resources/js/helpers/kioskCategoryOrder.js`, `config/menu.php`, `database/seeders/MenuSeeder.php`.*

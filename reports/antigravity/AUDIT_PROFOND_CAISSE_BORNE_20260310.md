# 🔬 AUDIT PROFOND — Caisse (POS) & Borne Android (Kiosk)

> **Date:** 10 Mars 2026  
> **Auditeur:** Agent QA (reverse engineering, tests éphémères)  
> **Mission:** Audit exhaustif, pensée approfondie, identification des problèmes directs et indirects — visuels, techniques, UI/UX, sécurité, logique métier.  
> **Contrainte:** Aucune modification du code — tests éphémères exécutés puis supprimés.

---

## 📊 RÉSUMÉ EXÉCUTIF

### Méthodologie

1. **Reverse engineering** — Analyse du code source (controllers, services, rules, routes)
2. **Tests éphémères** — 5 tests créés, exécutés, résultats documentés, fichier supprimé
3. **Comparaison concurrence** — McDo, KFC, standards QSR
4. **Logique humaine** — Attentes utilisateur vs implémentation

### Problèmes identifiés (directs et indirects)

| ID | Type | Sévérité | Description |
|----|------|----------|-------------|
| **D-001** | Sécurité | 🔴 Critique | Fallback prix client quand item inexistant (FrontendOrderService, OrderService) |
| **D-002** | Sécurité | 🔴 Critique | POS : variations/extras utilisent prix client (`$variation->price`) |
| **D-003** | Sécurité | 🔴 Haute | Routes `/api/table/*` sans auth — API key seule suffit |
| **D-004** | Validation | 🔴 Haute | ValidJsonOrder accepte `[{"quantity":1}]` sans `item_id` |
| **D-005** | Technique | 🔴 | POSComprehensiveTest : items sans `branch_id` |
| **D-006** | Technique | 🟡 | POS export : nom fichier "Online-Order.xlsx" (incohérent) |
| **D-007** | UX | 🟡 | Token POS obligatoire frontend, nullable backend |
| **D-008** | UX | 🟡 | Dine-In masqué sans explication |
| **D-009** | Architecture | 🟡 | Wizard POS : interception XHR fragile |
| **D-010** | UX | 🟡 | KDS : instruction pas parsée (VIANDES, SUPPLÉMENTS, FORMULE) |
| **D-011** | UX | 🟡 | Kiosk : pas de confirmation vocale/visuelle forte |
| **D-012** | Données | 🟡 | Table order : `branch_id` dans items peut venir du client |

---

## 1. PROBLÈMES DE SÉCURITÉ (REVERSE ENGINEERING)

### D-001 : Fallback prix client quand item inexistant

**Fichiers concernés :**
- `app/Services/FrontendOrderService.php:127-128`
- `app/Services/OrderService.php:274` (orderStore)
- `app/Services/OrderService.php:428` (posOrderStore)
- `app/Services/OrderService.php:610-611` (tableOrderStore)

**Code problématique :**
```php
$dbItem = Item::find($item->item_id);
$itemPrice = $dbItem ? $dbItem->price : $item->item_price;  // FALLBACK CLIENT
```

**Impact :** Un client malveillant peut envoyer un `item_id` inexistant (ex. 999999) avec `item_price: 0.01` et obtenir une commande à prix falsifié.

**Recommandation :** Rejeter la commande si `$dbItem` est null — ne jamais utiliser le prix client comme fallback.

---

### D-002 : POS variations/extras — prix client utilisés

**Fichier :** `app/Services/OrderService.php:432-446` (posOrderStore)

**Code :**
```php
foreach ($item->item_variations as $variation) {
    if (isset($variation->price)) {
        $variationTotal += $variation->price;  // FROM REQUEST!
    }
}
foreach ($item->item_extras as $extra) {
    if (isset($extra->price)) {
        $extraTotal += $extra->price;  // FROM REQUEST!
    }
}
```

**Impact :** Les variations et extras POS utilisent les prix envoyés par le client. Contrairement à `orderStore` (Kiosk) et `tableOrderStore` qui utilisent `ItemVariation::find` et `ItemExtra::find` pour récupérer les prix DB.

**Recommandation :** Aligner POS sur OrderService tableOrderStore — utiliser `ItemVariation::find($var->id)` et `ItemExtra::find($ext->id)` pour les prix.

---

### D-003 : Routes Table sans authentification

**Fichier :** `routes/api.php:826`

```php
Route::prefix('table')->middleware(['installed', 'apiKey', 'localization'])->group(...);
```

**Observation :** Pas de `auth:sanctum`. Toute personne qui possède l’API key peut :
- `GET /api/table/item-category`
- `GET /api/table/dining-table`
- `POST /api/table/dining-order`

**Contexte :** Les commandes table sont liées à un QR code sur la table. L’API key peut être exposée côté client. Risque de création de commandes frauduleuses sans authentification utilisateur.

**Recommandation :** Documenter le modèle de sécurité (API key = trust boundary) ou ajouter un token table/session.

---

### D-004 : ValidJsonOrder — structure insuffisante

**Fichier :** `app/Rules/ValidJsonOrder.php`

**Règle actuelle :** Vérifie uniquement que `items` est un tableau JSON non vide.

**Test éphémère EP-01 :**
```php
$rule->passes('items', json_encode([['quantity' => 1]]));  // TRUE
```

**Impact :** Un payload `[{"quantity":1}]` sans `item_id` passe la validation. Le traitement ultérieur peut provoquer des erreurs (ex. `$item->item_id` undefined) ou des comportements inattendus.

**Recommandation :** Valider la structure minimale : `item_id`, `quantity` requis pour chaque élément.

---

## 2. PROBLÈMES TECHNIQUES

### D-005 : POSComprehensiveTest — schéma items

**Erreur :** `table items has no column named branch_id`

**Cause :** `ItemFactory` reçoit `branch_id` en override, mais la table `items` n’a pas cette colonne (migration `create_items_table`).

**Recommandation :** Retirer `branch_id` de l’override dans POSComprehensiveTest ou ajouter une migration si le modèle métier l’exige.

---

### D-006 : POS export — nom de fichier incohérent

**Fichier :** `app/Http/Controllers/Admin/PosOrderController.php:72`

```php
return Excel::download(..., 'Online-Order.xlsx');
```

**Observation :** Le fichier exporté s’appelle "Online-Order" alors qu’il s’agit des commandes POS.

**Recommandation :** Utiliser `'Pos-Order.xlsx'` ou `'POS-Orders.xlsx'`.

---

### D-009 : Wizard POS — interception XHR

**Fichier :** `public/js/pos-wizard.js`

Le wizard intercepte les requêtes XHR vers `/admin/item/` et `/admin/setting/item/` pour capturer `lastItemData` avant d’ouvrir le modal.

**Risques :**
- Si l’URL de l’API change, le wizard ne reçoit plus les données
- Couplage fort avec l’implémentation interne
- Pas de mécanisme de fallback si l’interception échoue

**Recommandation :** Envisager un bus d’événements ou une API dédiée pour passer les données au wizard.

---

## 3. PROBLÈMES UX / UI

### D-007 : Token POS — incohérence frontend/backend

**Frontend :** `PosComponent.vue:872-874` — blocage si token vide  
**Backend :** `PosOrderRequest.php:31` — `token` nullable

**Impact :** Un client API peut créer une commande POS sans token. Le frontend l’empêche. Comportement divergent selon l’entrée.

**Recommandation :** Aligner les règles (obligatoire ou optionnel) et documenter le rôle du token (numéro de borne, file d’attente, etc.).

---

### D-008 : Dine-In masqué

**Fichier :** `PosComponent.vue:84` — `v-if="false"` sur l’option Dine-In

**Impact :** L’option n’apparaît pas, sans message "Temporairement indisponible" ou équivalent. L’utilisateur ne sait pas si c’est désactivé ou cassé.

---

### D-010 : KDS — instruction non parsée

**Contexte :** Le wizard génère une instruction structurée avec `VIANDES:`, `SUPPLÉMENTS:`, `FORMULE:`, etc.

**KDS :** `KitchenDisplaySystemComponent.vue` affiche `orderItem.instruction` en texte brut. Pas de sectionnement, pas de mise en évidence pour les suppléments ou la formule.

**Rapport AUDIT_MASSIF_E2E :** Phase 3 prévoit le parsing et le highlight dans la vue KDS.

---

### D-011 : Kiosk — confirmation

**Comparaison McDo/KFC :** Bip + message + numéro affiché en grand après validation.

**FoodKing :** Pas de confirmation vocale/visuelle forte documentée. Risque de doute pour le client après paiement.

---

## 4. RÉSULTATS DES TESTS ÉPHÉMÈRES

### Tests exécutés (fichier supprimé après)

| Test | Objectif | Résultat |
|------|----------|----------|
| EP-01 | ValidJsonOrder accepte items sans item_id | ✅ Pass — confirme que la règle est trop permissive |
| EP-02 | Routes table accessibles sans auth | ✅ Pass — 200 avec API key seule |
| EP-03 | Frontend item sans branch_id | ✅ Pass — 200 |
| EP-04 | POS export | ✅ Pass — retourne 200 |
| EP-05 | Order list exceptSource | ✅ Pass — 200 |

---

## 5. COMPARAISON CONCURRENCE (McDo, KFC)

### Parcours type QSR

| Étape | McDo/KFC | FoodKing POS | FoodKing Kiosk |
|-------|----------|--------------|----------------|
| 1 | Type commande en premier | Type après items | Type en premier ✅ |
| 2 | Catégories | Swiper ✅ | Grille ✅ |
| 3 | Détail produit (wizard) | Wizard multi-étapes ✅ | Wizard multi-étapes ✅ |
| 4 | Panier | Panier latéral ✅ | Écran panier ✅ |
| 5 | Récap détaillé | Partiel | Partiel |
| 6 | Paiement | Cash/CB ✅ | Cash/CB ✅ |
| 7 | Numéro file | Grand, visible | Via OSS |
| 8 | Confirmation | Bip + message | À améliorer |

### Écarts principaux

1. **Token avant paiement** — FoodKing demande un token avant paiement (POS). La concurrence affiche le numéro après validation.
2. **Client obligatoire** — FoodKing exige un client. McDo/KFC en caisse ne demandent pas d’identité pour une commande simple.
3. **Dine-In** — Masqué sans explication.
4. **Confirmation Kiosk** — Pas de feedback aussi marqué que chez McDo/KFC.

---

## 6. PROBLÈMES INDIRECTS (EFFETS DE BORD)

### D-012 : Table order — branch_id dans items

**Fichier :** `app/Services/OrderService.php:641` (tableOrderStore)

```php
'branch_id' => $item->branch_id,  // from request
```

**Observation :** Dans `posOrderStore` : `'branch_id' => $this->order->branch_id` (ordre). Dans `tableOrderStore` : `$item->branch_id` (client). Le client peut envoyer un `branch_id` différent de l’ordre.

**Recommandation :** Utiliser `$this->order->branch_id` pour cohérence.

---

### Idempotence

**Observation :** Aucun identifiant de requête (idempotency key) sur les endpoints de création de commande. En cas de double soumission (réseau lent, double clic), risque de double commande.

**Recommandation :** Introduire un idempotency key pour les POST de commande.

---

### OrderService list — filtre `like`

**Fichier :** `app/Services/OrderService.php:105`

```php
$query->where($key, 'like', '%' . $request . '%');
```

**Observation :** Laravel utilise des bindings, donc pas d’injection SQL directe. Les caractères `%` et `_` dans les entrées utilisateur peuvent toutefois produire des recherches inattendues.

---

## 7. SYNTHÈSE DES RECOMMANDATIONS

### Priorité critique (P0)

1. **D-001** — Rejeter les commandes si item inexistant (pas de fallback prix client).
2. **D-002** — POS : utiliser les prix DB pour variations et extras.
3. **D-004** — Renforcer ValidJsonOrder (item_id, quantity requis).

### Priorité haute (P1)

4. **D-003** — Documenter ou renforcer la sécurité des routes table.
5. **D-005** — Corriger POSComprehensiveTest (branch_id).
6. **D-012** — Table order : utiliser `$this->order->branch_id` pour les items.

### Priorité moyenne (P2)

7. **D-006** — Nom de fichier export POS.
8. **D-007** — Aligner token frontend/backend.
9. **D-008** — Message pour Dine-In masqué.
10. **D-010** — Parsing instruction KDS (Phase 3).
11. **D-011** — Confirmation Kiosk.

### Priorité basse (P3)

12. **D-009** — Découpler le wizard de l’interception XHR.
13. Idempotency key pour les commandes.

---

## 8. FICHIERS CLÉS AUDITÉS

| Fichier | Rôle |
|---------|------|
| `app/Services/OrderService.php` | posOrderStore, orderStore, tableOrderStore |
| `app/Services/FrontendOrderService.php` | myOrderStore (Kiosk) |
| `app/Rules/ValidJsonOrder.php` | Validation items |
| `app/Http/Requests/PosOrderRequest.php` | Validation POS |
| `app/Http/Requests/TableOrderRequest.php` | Validation table |
| `routes/api.php` | Middleware table |
| `app/Http/Controllers/Admin/PosOrderController.php` | Export |
| `resources/js/components/admin/pos/PosComponent.vue` | Token, Dine-In |
| `public/js/pos-wizard.js` | Interception XHR |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | Affichage instruction |
| `docs/ERROR_HANDLING.md` | Gestion erreurs API |

---

## 9. CONCLUSION

L’audit révèle des points forts (recalcul des prix côté Kiosk/Table, lockForUpdate pour queue_number, tests AntiGravity verts) et des faiblesses critiques en sécurité des prix (fallback client, variations/extras POS). Les problèmes D-001 et D-002 sont les plus urgents à corriger.

**Aucune modification de code n’a été effectuée.** Le fichier de tests éphémères `AuditEphemeralTest.php` a été supprimé après exécution.

---

**Fin du rapport.**

*Prochaine étape :* Revue manuelle, priorisation des correctifs, exécution selon `workflows/task-routing.md`.

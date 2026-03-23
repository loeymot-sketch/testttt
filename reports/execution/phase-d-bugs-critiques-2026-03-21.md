# Exécution Phase D — Correction Bugs Critiques Order Flow
**Date:** 2026-03-21  
**Auteur:** Claude (architecture + implémentation)  
**Build:** ✅ `npm run prod` — Compiled successfully in 22s  

---

## Fichiers modifiés

| Fichier | Raison |
|---------|--------|
| `app/Services/OrderService.php` | 7 bugs corrigés dans myOrderStore, posOrderStore, tableOrderStore |
| `resources/js/components/admin/pos/PosComponent.vue` | BUG-M2 walking customer fallback sécurisé |
| `app/Models/ItemCategory.php` | BUG sort absent de $fillable (fix précédent) |
| `config/menu.php` | Catégorie Suppléments ajoutée |

---

## Corrections appliquées

### BUG-C1 — FCM dispatché dans la transaction ✅ CORRIGÉ
**Méthodes affectées :** `myOrderStore`, `tableOrderStore`  
**Fix :** Les 6 appels `SendOrder*::dispatch()` déplacés APRÈS `});` (fermeture de la transaction DB). Enveloppés dans `try/catch` pour logguer sans bloquer si Firebase est down.  
- `myOrderStore` : lignes 466–476 (hors transaction)
- `tableOrderStore` : lignes 960–967 (hors transaction)
- `posOrderStore` : était déjà correct ✅

### BUG-C3 — OrderCoupon jamais créé pour POS et table ✅ CORRIGÉ
**Méthodes affectées :** `posOrderStore`, `tableOrderStore`  
**Fix :** Ajout de `OrderCoupon::create()` après le calcul du discount, à l'intérieur de la transaction, conditionné à `$calculatedDiscount > 0`.  
- `posOrderStore` : lignes 688–694
- `tableOrderStore` : lignes 935–941

### BUG-H1 — Total sans garde null/négatif ✅ CORRIGÉ
**Méthodes affectées :** `posOrderStore`, `tableOrderStore`  
**Fix :** Remplacement de `$x + $this->order->delivery_charge - $y` par `max(0, $x + ($this->order->delivery_charge ?? 0) - $y)`.  
Pattern copié de `myOrderStore` qui avait déjà la garde.

### BUG-H2 — BranchScope corrompt la requête lockForUpdate Admin ✅ CORRIGÉ
**Méthodes affectées :** `myOrderStore`, `posOrderStore`, `tableOrderStore`  
**Fix :** Ajout de `->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)` sur la requête `Order::where('branch_id', ...)` dans les 3 méthodes.  
Sans ce fix, Admin (branch_id=0) généraient toujours A001 → collisions de numéros de queue.

### BUG-H3 — Numéros de queue POS vs table/kiosque en double ✅ CORRIGÉ
**Méthodes affectées :** Toutes trois  
**Fix :** Chaque méthode interroge maintenant DEUX tables (`Order` + `FrontendOrder`) pour trouver le max du jour, puis prend `max(ordersMax, frontendMax) + 1`.  
Cela garantit une séquence unifiée A001→A002→A003 quelle que soit l'origine (POS, web, table QR).

### BUG-M2 — Fallback walking customer dangereux ✅ CORRIGÉ
**Fichier :** `resources/js/components/admin/pos/PosComponent.vue` lignes 664, 1124  
**Fix :** Double fallback : `find(email='walkingcustomer@...') || find(name includes 'walking') || [0]`. Le fallback final `[0]` reste en dernier recours uniquement si aucune autre stratégie ne fonctionne. Corrigé aux 2 endroits (initialisation + reset après commande).

### BUG-M3 — `order_items.created_at` / `updated_at` NULL ✅ CORRIGÉ
**Méthodes affectées :** Toutes trois (via `OrderItem::insert()`)  
**Fix :** Ajout de `'created_at' => now()` et `'updated_at' => now()` dans les arrays `$itemsArray[$i]` dans les 3 méthodes.  
Lignes : 364–365 (myOrderStore), 609–610 (posOrderStore), 856–857 (tableOrderStore).

---

## Vérifications post-fix

- `php -l app/Services/OrderService.php` → **No syntax errors detected** ✅
- `npm run prod` → **Compiled successfully** ✅
- `php artisan cache:clear` + `config:clear` + `view:clear` → ✅

---

## Risques résiduels connus (non traités dans cette phase)

| Bug | Raison du report |
|-----|-----------------|
| BUG-C2 : Validation coupon (expiry, max usage) | Nécessite une analyse du modèle Coupon — scope distinct |
| SEC-1 : Routes loyalty sans auth | Modification de routes — risque de casser le flux kiosque sans test |
| SEC-4 : Tokens sans expiration | Config uniquement — sera traité en Phase E |
| HIGH-02 : QUEUE_CONNECTION sync | Config + migration + daemon — Phase E |

---

## Prochaine étape recommandée

**Phase E — Configuration infrastructure :**
1. `QUEUE_CONNECTION=database` dans `.env`
2. `php artisan queue:table && php artisan migrate`
3. `config/sanctum.php` : `'expiration' => 43200`
4. `ApiKeyMiddleware` : `env()` → `config()`
5. Tester le parcours complet POS→KDS avec les nouveaux fixes

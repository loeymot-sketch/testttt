# 🧠 PLAN CLAUDE — DIRECTION SPRINT 3
**Rôle:** Claude (Lead Architect & Master Dev)
**Date:** 11 Mars 2026
**Prochaine session:** Kimi (Implémentation) → Anti-Gravity (Validation E2E)

---

## 🎯 ARCHITECTURE RÉELLE — État de Vérité du Codebase

> Avant toute chose, je documente ce que j'ai VÉRIFIÉ PERSONNELLEMENT dans le code.

```
FOODKING SYSTEM - Architecture Réelle
═══════════════════════════════════════

[WEB POS] ──── Vue.js SPA ────────────────────────────────────
├── PaymentComponent.vue         ← MODIFIÉ (fix received_amount DOM)
│   • Fix: Lecture `document.getElementById('cashInput').value`
│   • Risque résiduel: pas de v-model, dépend du DOM uniquement
│   • Ticket Card (4 derniers digits) ─ OK
├── PosOrderRequest.php          ← MODIFIÉ (token règle nullable/string/numeric)
│   • `pos_received_amount` requis SEULEMENT si CASH ─ OK
│   • Validation: total > received_amount bloque ─ OK
└── OrderService::posOrderStore   ← ⚠️ NON SÉCURISÉ
    • Prix items POS pris du frontend, PAS recalculés en DB
    • Kiosk (FrontendOrderService) = sécurisé
    • POS (OrderService) = items.price pris as-is du JSON

[KIOSK API] ──── Flutter API Calls ──────────────────────────
└── FrontendOrderService::myOrderStore  ← SÉCURISÉ ✅
    • Prix recalculé depuis DB: `$dbItem->price`
    • Variations recalculées: `$dbVar->price`
    • Extras recalculées: `$dbExt->price`
    • Coupon validé côté serveur ─ OK
    • faviconLogo: SafeNull `?->` partout ─ OK

[KDS / OSS] ──── Web UI ─────────────────────────────────────
├── UI charge sans crash (validé visuellement) ─ OK
├── Aucune commande de test possible (création bloquée)
└── KDS pull-based ou Firebase? → À confirmer

[AUTH / SECURITE] ────────────────────────────────────────────
├── Kiosk Token (Sanctum) → Abilities `kiosk:order` ─ À vérifier
├── AntiGravityTest t05: Kiosk ne peut pas accéder /api/admin/* ─ PASS
└── 18/18 tests automatisés passent ─ ✅
```

---

## 🔬 RÉSULTATS AUDIT EN PROFONDEUR

### ✅ CE QUI EST CORRECT (NE PAS TOUCHER)
| Composant | Vérification | Statut |
|-----------|-------------|--------|
| `PosOrderRequest` (token rule) | `nullable/string/numeric` OK | ✅ CORRIGÉ |
| `PaymentComponent` (received_amount) | DOM read fix présent | ✅ CORRIGÉ |
| `FrontendOrderService` (prix Kiosk) | Recalcul via DB | ✅ SÉCURISÉ |
| `faviconLogo` null-safety | `?->` opérateur partout | ✅ NULL-SAFE |
| Queue number concurrence | `lockForUpdate()` + transaction | ✅ SAFE |
| Autorisation Kiosk→Admin | Test t05 passe ─ 403 retourné | ✅ OK |
| Isolation multi-branches KDS | Test t18 passe | ✅ OK |
| Anti-SQL injection | Eloquent ORM partout | ✅ PROTÉGÉ |
| Action logs | Insérés pour commandes POS et Web | ✅ OK |

---

### 🔴 PROBLÈMES CRITIQUES OUVERTS (Doivent être corrigés AVANT production)

#### CRITIQUE-1: POS OrderService — Prix Non Recalculé
**Fichier:** `app/Services/OrderService.php` lignes 396-414
**Problème:** `posOrderStore()` utilise `$item->item_price` du JSON front-end sans recalculer depuis la DB.
```php
// ACTUEL (DANGEREUX) – POS:
'price' => $item->item_price,  // Vient du frontend, falsifiable!
'total_price' => $item->total_price,  // Vient du frontend!
'item_variation_total' => $item->item_variation_total,  // Non vérifié!
```
```php
// CE QU'IL FAUT (comme FrontendOrderService, lignes 127-148):
$dbItem = Item::find($item->item_id);
$itemPrice = $dbItem ? $dbItem->price : $item->item_price;
// Recalculer variants et extras depuis DB
```
**Impact:** Un caissier malveillant (ou un bug) peut créer une commande POS à prix 0€.
**Test cible:** `t08_order_forged_price_uses_db_price` (existe pour Kiosk, mais pas pour POS).
**Action Kimi:** Appliquer le même pattern de `FrontendOrderService` dans `posOrderStore()`.

---

#### CRITIQUE-2: Compilation Vue.js — Fix Non Buildé en Production
**Fichier:** `resources/js/components/admin/pos/PaymentComponent.vue`
**Problème:** Le fix du DOM (`document.getElementById('cashInput')`) est dans le `.vue`. Est-ce que `npm run build` (ou `mix`) a été relancé depuis ?
**Symptôme:** Si on teste le POS en production, le fichier servi au navigateur (`public/js/app.js`) est potentiellement l'ANCIENNE version.
**Action Kimi:** Confirmer que `npm run dev` / `npm run build` a été relancé. Sinon, relancer.
**Vérification:** `ls -la public/js/app.js` (timestamp doit être récent).

---

#### CRITIQUE-3: Wizard POS — `pos-wizard.js` Non Intégré Backend
**Fichier:** `public/js/pos-wizard.js`
**Problème:** Le Wizard JS calcule des prix côté client (extras, sauces, menus). Ces prix sont envoyés au backend POS via l'API. Mais le backend POS (`posOrderStore`) ne recalcule pas les extras depuis la DB.
**Impact:** Un caissier + bug wizard = commande cree arbitraire.
**Action Kimi (dépend de CRITIQUE-1):** Une fois CRITIQUE-1 corrigé, les extras/variations wizard sont re-verified DB-side automatiquement.

---

#### HAUTE-1: `pos-wizard.js` — Detection de Catégorie Fragile
**Fichier:** `public/js/pos-wizard.js`
**Problème:** La détection des catégories utilise `.includes()` sur le nom de l'item. Si le catalog change ou traduit des noms, le wizard ne s'activera pas.
```javascript
// Fragile:
if (name.toLowerCase().includes('tacos')) { return 'tacos'; }
// Plus robuste: utiliser category_id de la DB
```
**Impact:** Medium — si nouveaux items hors convention de nommage.
**Action Kimi:** Refactoriser `detectCategory()` pour utiliser `item.categoryName` ou `item.category_id` au lieu du nom de l'item.

---

#### HAUTE-2: Notifications — Isolation Kiosk lors de `SendOrderGotPush`
**Fichier:** `app/Services/OrderGotPushNotificationBuilder.php`
**Contexte:** Lorsqu'une commande Kiosk est créée, `SendOrderGotPush` est dispatchée. La notification devrait aller au KDS de la branche correspondante.
**Problème potentiel connu:** Si Firebase token non configuré → exception 500 ou silencieuse.
**Action:** Vérifier que les envois sont dans un try/catch, et que les Queue jobs sont configurés avec retry sans bloquer la transaction principale.

---

#### MOYENNE-1: POS — Pas de Notification KDS lors d'une Commande POS
**Fichier:** `app/Services/OrderService.php` — `posOrderStore()`
**Observation:** `FrontendOrderService` dispatch `SendOrderGotMail`, `SendOrderGotSms`, `SendOrderGotPush` après création. **`posOrderStore()` n'en dispatch AUCUN**. Le KDS ne sait donc pas qu'une commande POS vient d'être créée!
**Impact:** Le KDS ne verra jamais les commandes créées depuis la caisse POS, sauf si le KDS poll l'API (à vérifier).
**Action Kimi:** Ajouter les dispatch dans `posOrderStore()` après la transaction.

---

#### MOYENNE-2: Wizard — Items Sans Image Affichés Comme Brisés
**Observation visuelle (capture e2e):** Les items dans POS et OSS apparaissent avec des images brisées.
**Cause probable:** Seeder crée les items sans URL d'image réelles.
**Action Kimi:** Soit mettre un placeholder propre (`/images/default-food.jpg`), soit générer des images.

---

## 🚀 PLAN D'ACTION SPRINT 3 POUR KIMI (Ordonné par Priorité)

### 📋 Tâche 1 — [CRITIQUE] Sécuriser posOrderStore (POS Price Recalculation)
**Type Test:** Kimi-test (PHPUnit)
**Fichier cible:** `app/Services/OrderService.php` (méthode `posOrderStore`, lignes 366-499)
**Changement:**
```php
// Dans la boucle foreach ($requestItems as $item) :
// REMPLACER:
$itemPrice = $item->item_price;

// PAR (pattern identique à FrontendOrderService):
$dbItem = Item::find($item->item_id);
$itemPrice = $dbItem ? $dbItem->price : ($item->item_price ?? 0);

$calcVariationTotal = 0;
if (!empty($item->item_variations)) {
    foreach ($item->item_variations as $var) {
        $varId = is_object($var) ? ($var->id ?? 0) : ($var['id'] ?? 0);
        $dbVar = \App\Models\ItemVariation::find($varId);
        if ($dbVar) $calcVariationTotal += $dbVar->price;
    }
}
$calcExtraTotal = 0;
if (!empty($item->item_extras)) {
    foreach ($item->item_extras as $ext) {
        $extId = is_object($ext) ? ($ext->id ?? 0) : ($ext['id'] ?? 0);
        $dbExt = \App\Models\ItemExtra::find($extId);
        if ($dbExt) $calcExtraTotal += $dbExt->price;
    }
}
$verifiedTotalPrice = ($itemPrice + $calcVariationTotal + $calcExtraTotal) * $item->quantity;
$realSubtotal += $verifiedTotalPrice;

// ET remplacer les champs dans $itemsArray:
'price' => $itemPrice,  // DB price
'item_variation_total' => $calcVariationTotal,
'item_extra_total' => $calcExtraTotal,
'total_price' => $verifiedTotalPrice,
```
**Test PHPUnit:** Ajouter dans `AntiGravityTest.php` un test `t08b_pos_order_forged_price_uses_db_price` similaire au `t08` existant, mais qui appelle `POST /api/admin/pos` avec admin auth.
**⚠️ Risque:** La subtotal/total de l'`Order` doit être recalculée aussi. Vérifier lignes 459-461 sont cohérentes.

---

### 📋 Tâche 2 — [CRITIQUE] Ajouter Notifications KDS pour Commandes POS
**Type Test:** Kimi-test (PHPUnit)
**Fichier cible:** `app/Services/OrderService.php` (méthode `posOrderStore`, après ligne 491)
**Changement:** Ajouter après la transaction :
```php
// Après DB::transaction clôturée, ajouter:
SendOrderGotMail::dispatch(['order_id' => $this->order->id]);
SendOrderGotSms::dispatch(['order_id' => $this->order->id]);
SendOrderGotPush::dispatch(['order_id' => $this->order->id]);
```
**Test:** Créer un `OrderFactory::new()->create(...)` via test PHPUnit et vérifier que les jobs sont dispatchés (utiliser `Queue::fake()`).

---

### 📋 Tâche 3 — [HAUTE] Vérification Build Vue.js + Relance si Nécessaire
**Type Test:** Manuel + Kimi-run
**Action:**
```bash
# Vérifier timestamp du build actuel:
ls -la /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/js/app.js

# Si le timestamp est antérieur aux dernières modifications Vue:
cd /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
npm run dev  # ou npm run build selon env
```
**Vérification après build:** Ouvrir le POS dans Chrome, DevTools > Network > `app.js` > chercher "cashInput" dans le source. Si présent → fix buildé.

---

### 📋 Tâche 4 — [HAUTE] Refactoriser detectCategory() dans pos-wizard.js
**Type Test:** Anti-Gravity (test visuel POS)
**Fichier cible:** `public/js/pos-wizard.js`
**Principe:** Utiliser `item.category_slug` ou `item.category_id` (disponible dans l'API) au lieu du fuzzy matching sur le nom. Cela rend le wizard robuste aux changements de noms d'items.
**⚠️ Note:** Ne pas casser les items existants. Faire un fallback sur le matching actuel si `category_slug` non disponible.

---

### 📋 Tâche 5 — [MOYENNE] Placeholder Images pour Items Sans Image
**Type Test:** Anti-Gravity (visual)
**Fichier cible:** Seeder `GrillHouseMenuSeeder.php` + template Vue
**Action:** Ajouter une URL de fallback dans le template Vue pour les items sans image:
```html
<img :src="item.image || '/images/default-food.jpg'" :alt="item.name">
```
Créer `public/images/default-food.jpg` avec une image générique.

---

### 📋 Tâche 6 — [BASSE] Nettoyer les Commentaires Debug + Code Mort
**Type Test:** No-test (trivial)
**Action:** Supprimer logs `console.log` du Wizard JS. Ne pas modifier la logique.

---

## 🔭 VISION GLOBALE & ROADMAP (Claude Décide)

### Phase Actuelle: Stabilisation Fonctionnelle
> Objectif: Que le path POS Cash → Commande → KDS fonctionne de bout en bout.

```
ÉTAT ACTUEL:
POS Wizard ───→ Panier ───→ ❌ Modal Paiement (fix compilé?) ───→ ❌ KDS (pas de notif)

OBJECTIF SPRINT 3:
POS Wizard ───→ Panier ───→ ✅ Modal Paiement ───→ ✅ KDS (notification) ───→ ✅ OSS
```

### Phase Suivante (Sprint 4): Solidification Sécurité
1. ✅ Recalcul prix POS DB-side (Sprint 3)
2. Tests de charge (10 commandes/min)
3. Audit complet des Abilities Sanctum (Kiosk vs Admin vs Chef)
4. Sécurisation upload d'images (ItemImport)

### Phase Finale (Sprint 5): Production Readiness
1. Manuel caissier imprimé
2. Configuration imprimante thermique (80mm)
3. Soft launch (1 branche pilote)
4. Monitoring dashboards

---

## 📁 FICHIERS RÉFÉRENCE KIMI

> Kimi DOIT lire ces fichiers avant d'implémenter:
- `app/Services/FrontendOrderService.php` — Modèle à copier pour prix DB
- `app/Services/OrderService.php` — Fichier cible Tâche 1+2
- `tests/Feature/AntiGravityTest.php` — Tests existants à ne pas casser
- `resources/js/components/admin/pos/PaymentComponent.vue` — Tâche 3
- `public/js/pos-wizard.js` — Tâche 4

> ❌ Kimi NE DOIT PAS toucher:
- `app/Services/FrontendOrderService.php` (déjà sécurisé)
- `app/Http/Requests/PosOrderRequest.php` (déjà corrigé)
- `app/Rules/ValidStatusTransition.php` (logique critique)
- Tout fichier de migration existant

---

## 🧪 VALIDATION FINALE REQUISE (Anti-Gravity Sprint 3)

Après que Kimi ait complété les Tâches 1-3, Anti-Gravity devra:
1. **Test POS Cash complet:** Login → Item → Wizard → Panier → Modal Paiement → Saisir montant → Confirm → Vérifier ordre créé
2. **Test KDS Update:** Après création commande POS, vérifier que le KDS montre la commande
3. **Test Anti-falsification POS:** Envoyer `price=0.01` via API POS → vérifier que DB stocke le vrai prix DB
4. **Screenshot ticket de caisse** si Modal Receipt s'ouvre

---

**Plan validé par Claude — Sprint 3 can start.**
*Kimi: Commence par Tâche 1 (posOrderStore), puis Tâche 2 (notifications), puis Tâche 3 (build Vue).*

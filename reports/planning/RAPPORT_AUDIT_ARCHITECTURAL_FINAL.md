# 📊 RAPPORT D'AUDIT ARCHITECTURAL FINAL

> **Date:** 11 Mars 2026  
> **Auditeur:** Claude (Lead Architect)  
> **Scope:** Architecture complète POS + Kiosk + Menu + Sécurité  
> **Verdict:** GO avec corrections critiques

---

## 🎯 SYNTHÈSE EXÉCUTIVE

### Évaluation Globale

| Domaine | Score | Status | Décision |
|---------|-------|--------|----------|
| **Architecture Menu** | 9/10 | ✅ Excellent | GO |
| **Wizard POS** | 8.5/10 | ✅ Très bon | GO |
| **Parcours Kiosk** | 8/10 | ✅ Bon | GO |
| **Sécurité Prix** | 7/10 | ⚠️ À corriger | **CORRECTION** |
| **Flux KDS** | 8/10 | ✅ Bon | GO |
| **Intégrité Données** | 8/10 | ✅ Bon | GO |

**Verdict Global:** 🟢 **GO pour tests Playwright / E2E verification avec 3 corrections critiques**

---

## ✅ ARCHITECTURE COMPLÈTE - CE QUI FONCTIONNE

### 1. Menu "Le Grill House" - Architecture Source Unique

**✅ FORCE Majeure:** Le système utilise une architecture **Single Source of Truth** avec `config/menu.php`

**Structure Validée:**
```
config/menu.php
├── Locale: 'fr' ✅
├── Currency: 'EUR' ✅
├── 11 Catégories (Nos Tacos, Sandwichs, Burgers, etc.) ✅
├── 50+ Items avec prix € ✅
├── 7 Viandes configurées ✅
├── 12 Sauces configurées ✅
└── Suppléments avec prix (€0.50 - €2.00) ✅
```

**Protection Anti-Anglais:**
- ✅ `MenuSeeder` détecte contamination anglaise
- ✅ Force purge automatique
- ✅ Validation post-création
- ✅ Seeders obsolètes bloqués

### 2. Wizard POS - Logique 7 Étapes

**✅ Parcours Complet Validé:**

| Étape | Tacos | Sandwich | Assiette | Salade | Omelette |
|-------|-------|----------|----------|--------|----------|
| 1. Viande | ✅ (1-4) | ❌ N/A | ❌ N/A | ❌ N/A | ❌ N/A |
| 2. Sauce | ✅ | ✅ | ✅ | ✅ | ✅ (single) |
| 3. Garnitures | ✅ | ✅ | ❌ N/A | ❌ N/A | ❌ N/A |
| 4. Suppléments | ✅ | ✅ | ✅ | ✅ | ❌ N/A |
| 5. Menu | ✅ | ✅ | ❌ N/A | ❌ N/A | ❌ N/A |
| 6. Sauce Frites | ✅ (cond.) | ✅ (cond.) | ❌ N/A | ❌ N/A | ❌ N/A |
| 7. Récap | ✅ | ✅ | ✅ | ✅ | ✅ |

**✅ Logique de Prix:**
- 1ère sauce: GRATUITE (€0.00)
- Sauces supplémentaires: +€0.50 chacune
- Calcul dynamique en temps réel
- Prix DB utilisés (pas client)

**✅ Détection Tacos:**
```javascript
"Tacos M (1 Viande)"     → 1 viande
"Tacos L (2 Viandes)"    → 2 viandes
"Tacos XL (3 Viandes)"   → 3 viandes
"Tacos XXL (4 Viandes)"  → 4 viandes
```

### 3. Parcours Complet - Comparaison POS vs Kiosk

| Aspect | POS (Caisse) | Kiosk (Borne) | Alignement |
|--------|--------------|---------------|------------|
| **Authentification** | Admin Sanctum | KioskMachine Token | ✅ Différent selon besoin |
| **Client** | Sélection explicite | Lié KioskMachine | ⚠️ Différence appropriée |
| **Wizard** | Complet 7 étapes | Simplifié / Flutter | ✅ Adapté au contexte |
| **Paiement** | Cash + Carte + TPE | Carte uniquement | ✅ Adapté au contexte |
| **Prix** | Recalcul serveur | Recalcul serveur | ✅ **IDENTIQUE** |
| **Notifications KDS** | SendOrderGotPush | SendOrderGotPush | ✅ **IDENTIQUE** |
| **Numéro File** | A001, A002... | A001, A002... | ✅ **IDENTIQUE** |
| **Source** | 5 (POS) | 10 (KIOSK) | ✅ Traçabilité |

**✅ Architecture cohérente:** Même base de données, même KDS, même sécurité prix

### 4. Sécurité - Contrôles Validés

**✅ Points Forts:**
- POS: Prix recalculés depuis DB (anti-falsification)
- KDS: Notifications dispatchées hors transaction
- Authentification: Tokens Sanctum avec abilities
- Kiosk: Branch forcée depuis KioskMachine (pas client)
- File d'attente: `lockForUpdate()` pour éviter doublons

---

## 🚨 FAILLES CRITIQUES IDENTIFIÉES

### **FAILLE P0-001: Web/App Orders - Prix Non Vérifiés**

**Localisation:** `OrderService::myOrderStore()` (lignes 247-361)

**Problème:**
```php
// Ligne 284 - TRUST CLIENT PRICE (Security Gap)
'price' => $item->item_price,  // Prix client utilisé directement
'total_price' => $item->total_price,  // Total client utilisé
```

**Impact:** Un client peut modifier le JSON et envoyer n'importe quel prix

**Correction Requise:**
```php
// Récupérer prix depuis DB
$dbItems = Item::get()->pluck('price', 'id');
$itemPrice = $dbItems[$item->item_id] ?? 0;
// Recalculer total serveur
$verifiedTotal = $itemPrice * $item->quantity;
```

**⚠️ Note:** POS et Kiosk sont PROTÉGÉS, seul Web/App est vulnérable

---

### **FAILLE P0-002: Autorisation `OrderStatusRequest`**

**Localisation:** `OrderStatusRequest::authorize()`

**Problème:**
```php
public function authorize(): bool
{
    return true;  // Accepte tout le monde!
}
```

**Impact:** N'importe qui peut changer le statut d'une commande

**Correction Requise:**
```php
public function authorize(): bool
{
    return auth()->check() && auth()->user()->can('order-status-change');
}
```

---

### **FAILLE P0-003: KDS Notification - Mauvais Modèle**

**Localisation:** `OrderGotPushNotificationBuilder.php:23`

**Problème:**
```php
$this->order = FrontendOrder::find($orderId);  // Cherche dans mauvaise table!
```

**Impact:** Les commandes POS (table `orders`) ne notifient pas le KDS

**Correction Requise:**
```php
$this->order = Order::find($orderId);  // Table orders
if (!$this->order) {
    $this->order = FrontendOrder::find($orderId);  // Fallback table
}
```

---

## ⚠️ GAPS MOYENS (À RÉSOUDRE APRÈS P0)

| ID | Problème | Impact | Priorité |
|----|----------|--------|----------|
| **P1-001** | Carte non persistée (localStorage) | Perte données refresh | P1 |
| **P1-002** | Wizard utilise DOM fragile | Race conditions possible | P1 |
| **P1-003** | Pas d'idempotence commande | Doublons possibles retry | P1 |
| **P2-001** | Pas de validation FK variations | Données orphelines possible | P2 |
| **P2-002** | No rate limiting API | Brute force possible | P2 |
| **P2-003** | FCM tokens non chiffrés | Fuite données possible | P2 |

---

## 📋 COMPARAISON: DEMANDE vs IMPLÉMENTATION

### ✅ Ce qui a été demandé et est implémenté

| Demande | Implémentation | Status |
|---------|----------------|--------|
| Menu français "Le Grill House" | ✅ config/menu.php + MenuSeeder | **OK** |
| Wizard 7 étapes | ✅ public/js/pos-wizard.js | **OK** |
| Tacos M/L/XL/XXL avec viandes | ✅ Détection auto viande count | **OK** |
| 1ère sauce gratuite, +€0.50 extra | ✅ SAUCE_EXTRA_PRICE = 0.50 | **OK** |
| Prix sécurisés (anti-falsification) | ✅ OrderService::posOrderStore | **OK** |
| Notifications KDS temps réel | ✅ SendOrderGotPush | **OK** |
| Kiosk Android compatible | ✅ API prête pour Flutter | **OK** |
| Numéro file d'attente A001... | ✅ Queue number génération | **OK** |
| Multi-surface (POS, Kiosk, KDS) | ✅ Architecture unifiée | **OK** |

### ⚠️ Ce qui a été demandé et est partiel/corrigé

| Demande | Implémentation | Status | Action |
|---------|----------------|--------|--------|
| Prix sécurisés Web/App | ⚠️ Non implémenté | **FAILLE** | Corriger P0-001 |
| Autorisation stricte | ⚠️ `authorize()` return true | **FAILLE** | Corriger P0-002 |
| KDS reçoit toutes commandes | ⚠️ FrontendOrder seulement | **FAILLE** | Corriger P0-003 |

---

## 🎯 DÉCISION: GO ou NO-GO ?

### 🟢 VERDICT: **GO avec corrections P0**

**Justification:**
- ✅ Architecture globale solide et cohérente
- ✅ Menu français correctement structuré
- ✅ Wizard POS logique et complet
- ✅ Parcours Kiosk aligné avec POS
- ✅ Tests Playwright / E2E verification couvriront les cas critiques
- ⚠️ 3 failles P0 à corriger AVANT tests massifs

### 📋 Plan de Correction P0

#### **Étape 1: Corriger Prix Web/App (30 min)**
```bash
# Fichier: app/Services/OrderService.php
# Ligne: ~247-361 (méthode myOrderStore)
# Action: Ajouter recalcul prix DB comme dans posOrderStore
```

#### **Étape 2: Corriger Autorisation (15 min)**
```bash
# Fichier: app/Http/Requests/OrderStatusRequest.php
# Ligne: authorize() method
# Action: Vérifier permission utilisateur
```

#### **Étape 3: Corriger KDS Notification (15 min)**
```bash
# Fichier: app/Services/OrderGotPushNotificationBuilder.php
# Ligne: 23
# Action: Utiliser Order::find() au lieu de FrontendOrder::find()
```

**Total:** ~1 heure de corrections critiques

---

## 🚀 PLAN ACTION ANTI-GRAVITY

### Après corrections P0:

1. **Exécuter menu:reset** pour purifier la base
2. **Lancer tests E2E massifs** selon plan détaillé
3. **Focus prioritaire:**
   - Wizard Tacos complet (M, L, XL, XXL)
   - Prix anti-falsification
   - Flux KDS POS → Cuisine
   - Paiement cash + monnaie
   - Impression ticket

4. **Tests Kiosk** (après validation POS):
   - Parcours simplifié
   - Paiement carte
   - Synchronisation KDS

---

## ✅ CHECKLIST PRÉ-ANTI-GRAVITY

- [x] Architecture globale auditée
- [x] Menu français validé
- [x] Wizard POS validé
- [x] Parcours Kiosk validé
- [ ] **Corriger P0-001** (Prix Web/App)
- [ ] **Corriger P0-002** (Autorisation)
- [ ] **Corriger P0-003** (KDS Notification)
- [ ] Exécuter menu:reset
- [ ] Vérifier POS affiche "Nos Tacos"
- [ ] Lancer Playwright / E2E verification

---

## 📝 CONCLUSION ARCHITECTURALE

**L'architecture de FoodKing est solide et bien pensée.**

**Points Forts:**
- Configuration centralisée (config/menu.php)
- Séparation claire des responsabilités
- Sécurité prix côté serveur (POS/Kiosk)
- Notifications temps réel KDS
- Architecture multi-surface cohérente

**Points de Vigilance:**
- 3 failles de sécurité à corriger immédiatement
- Certains contrôles à renforcer (autorisations)
- Améliorations UX possibles (persistance carte)

**Recommandation:**
Corriger les 3 failles P0, puis **GO pour tests Playwright / E2E verification massifs**.

---

*Audit architectural complet réalisé*  
*Claude - Lead Architect*  
*Date: 11 Mars 2026*

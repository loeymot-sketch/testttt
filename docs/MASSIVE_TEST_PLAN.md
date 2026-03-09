# 🧪 MASTER PLAN : Test Massif Intégral du Système FoodKing

> **Objectif :** Tester exhaustivement chaque surface du système (Dashboard Admin, POS/Caisse, Kiosk, KDS, OSS, Commandes Table, Synchronisation inter-écrans, Sécurité) via des tests PHPUnit automatisés exécutables par un agent IA.
>
> **Philosophie :** On ne cherche pas l'innovation. On assure que tout fonctionne comme chez les meilleurs concurrents (Square, SumUp, Zelty). Chaque test validé = un checkpoint garanti.

---

## 🔑 MODULE 1 : Authentification & Accès (8 tests)

> Surface : `/api/auth/*`, `/api/profile/*`

| # | Test | Route | Méthode | Résultat Attendu |
|---|------|-------|---------|-----------------|
| 1.1 | Login Admin valide | `POST /api/auth/login` | email+password | Token Sanctum + 200 |
| 1.2 | Login Admin invalide | `POST /api/auth/login` | mauvais password | 422 ou 401 |
| 1.3 | Login Kiosk valide | `POST /api/auth/kiosk-login` | username+password | Token ability `kiosk:order` |
| 1.4 | Login Kiosk invalide | `POST /api/auth/kiosk-login` | mauvais credentials | Erreur, pas de token |
| 1.5 | Kiosk déjà loggué | `POST /api/auth/kiosk-login` | re-login même borne | Erreur `already logged in` |
| 1.6 | Logout Admin | `POST /api/auth/logout` | Bearer token | Token révoqué, 200 |
| 1.7 | Logout Kiosk | `POST /api/auth/kiosk-logout` | Bearer token | Token révoqué |
| 1.8 | Accès sans token | `GET /api/admin/dashboard/*` | Aucun header | 401 Unauthenticated |

**Fichier :** `tests/Feature/AuthComprehensiveTest.php`

---

## 🏪 MODULE 2 : CRUD Admin Complet (20 tests)

> Surface : `/api/admin/setting/*`, `/api/admin/item/*`, `/api/admin/branch/*`, etc.

### 2A. Gestion des Produits (Items)

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 2.1 | Lister les items | `GET /api/admin/setting/item` | 200 + JSON array |
| 2.2 | Créer un item | `POST /api/admin/setting/item` | 201 + item créé en DB |
| 2.3 | Voir un item | `GET /api/admin/setting/item/show/{id}` | 200 + détails item |
| 2.4 | Modifier un item (prix) | `PUT /api/admin/setting/item/{id}` | 200 + prix mis à jour |
| 2.5 | Supprimer un item | `DELETE /api/admin/setting/item/{id}` | 200 + item absent de la DB |

### 2B. Gestion des Catégories

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 2.6 | Lister catégories | `GET /api/admin/setting/item-category` | 200 |
| 2.7 | Créer une catégorie | `POST /api/admin/setting/item-category` | 201 |
| 2.8 | Supprimer une catégorie | `DELETE /api/admin/setting/item-category/{id}` | 200 |

### 2C. Gestion des Succursales (Branches)

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 2.9 | Lister les branches | `GET /api/admin/setting/branch` | 200 |
| 2.10 | Créer une branche | `POST /api/admin/setting/branch` | 201 |
| 2.11 | Modifier une branche | `PUT /api/admin/setting/branch/{id}` | 200 |
| 2.12 | Supprimer une branche | `DELETE /api/admin/setting/branch/{id}` | 200 |

### 2D. Gestion des Coupons

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 2.13 | Créer un coupon | `POST /api/admin/coupon` | 201 |
| 2.14 | Lister les coupons | `GET /api/admin/coupon` | 200 |
| 2.15 | Supprimer un coupon | `DELETE /api/admin/coupon/{id}` | 200 |

### 2E. Gestion des Tables (Dine-In)

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 2.16 | Créer une table | `POST /api/admin/dining-table` | 201 |
| 2.17 | Lister les tables | `GET /api/admin/dining-table` | 200 |
| 2.18 | Supprimer une table | `DELETE /api/admin/dining-table/{id}` | 200 |

### 2F. Gestion des Bornes Kiosk

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 2.19 | Créer une borne | `POST /api/admin/setting/kiosk-machine` | 201 |
| 2.20 | Désactiver une borne | `POST /api/admin/setting/kiosk-machine/change-status/{id}` | 200 + status changé |

**Fichier :** `tests/Feature/AdminCrudComprehensiveTest.php`

---

## 💰 MODULE 3 : POS / Caisse (8 tests)

> Surface : `/api/admin/pos`, `/api/admin/pos-order/*`

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 3.1 | Créer commande POS | `POST /api/admin/pos` | 201 + Order créée |
| 3.2 | Lister commandes POS | `GET /api/admin/pos-order` | 200 + paginated |
| 3.3 | Voir détail commande | `GET /api/admin/pos-order/show/{id}` | 200 |
| 3.4 | Changer statut (Accept) | `POST /api/admin/pos-order/change-status/{id}` | 200 + status=10 |
| 3.5 | Changer statut paiement | `POST /api/admin/pos-order/change-payment-status/{id}` | 200 |
| 3.6 | Supprimer commande | `DELETE /api/admin/pos-order/{id}` | 200 |
| 3.7 | Export commandes | `GET /api/admin/pos-order/export` | 200 + fichier |
| 3.8 | Re-order (Sprint 5) | `GET /api/admin/pos-order/reorder-items/{id}` | 200 + cart payload |

**Fichier :** `tests/Feature/POSComprehensiveTest.php`

---

## 📱 MODULE 4 : Kiosk / Frontend Client (10 tests)

> Surface : `/api/frontend/*`

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 4.1 | Lister le catalogue | `GET /api/frontend/item` | 200 + items filtrés par branche |
| 4.2 | Items populaires | `GET /api/frontend/item/popular-items` | 200 |
| 4.3 | Items vedettes | `GET /api/frontend/item/featured-items` | 200 |
| 4.4 | Détails d'un item | `GET /api/frontend/item/details/{id}` | 200 + variations/extras |
| 4.5 | Lister catégories | `GET /api/frontend/item-category` | 200 |
| 4.6 | Créer commande Kiosk | `POST /api/frontend/order` | 201 + prix recalculé serveur |
| 4.7 | **Falsification prix Kiosk** | `POST /api/frontend/order` (prix=0.01) | Prix ignoré, total DB correct |
| 4.8 | Vérifier coupon valide | `POST /api/frontend/coupon/coupon-checking` | 200 + discount |
| 4.9 | Vérifier coupon invalide | `POST /api/frontend/coupon/coupon-checking` | Erreur |
| 4.10 | Lister créneaux horaires | `GET /api/frontend/time-slot/today` | 200 |

**Fichier :** `tests/Feature/KioskFrontendComprehensiveTest.php`

---

## 🍳 MODULE 5 : KDS – Kitchen Display System (6 tests)

> Surface : `/api/admin/kds-order/*`

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 5.1 | Lister commandes KDS | `GET /api/admin/kds-order` | 200 + filtrées par branch |
| 5.2 | Items KDS | `GET /api/admin/kds-order/items` | 200 |
| 5.3 | Accepter → Preparing | `POST /api/admin/kds-order/change-status/{id}` | 200 + status=14 |
| 5.4 | Transition invalide | `POST /api/admin/kds-order/change-status/{id}` | Rejeté (422/400) |
| 5.5 | **Isolation branche KDS** | `GET /api/admin/kds-order` (autre branch) | 0 résultats |
| 5.6 | **Chef ne voit pas autre branche** | Requête cross-branch | Filtré automatiquement |

**Fichier :** `tests/Feature/KDSComprehensiveTest.php`

---

## 📺 MODULE 6 : OSS – Order Status Screen (3 tests)

> Surface : `/api/admin/oss-order/*`

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 6.1 | Lister commandes OSS | `GET /api/admin/oss-order` | 200 |
| 6.2 | Items populaires OSS | `GET /api/admin/oss-order/popular-items` | 200 |
| 6.3 | **POST interdit sur OSS** | `POST /api/admin/oss-order` | 405 Method Not Allowed |

**Fichier :** `tests/Feature/OSSComprehensiveTest.php`

---

## 🪑 MODULE 7 : Commandes Table / Dine-In (4 tests)

> Surface : `/api/table/*`

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 7.1 | Lister catégories table | `GET /api/table/item-category` | 200 |
| 7.2 | Voir tables disponibles | `GET /api/table/dining-table` | 200 |
| 7.3 | Passer commande table | `POST /api/table/dining-order` | 201 + prix recalculé |
| 7.4 | **Falsification prix table** | `POST /api/table/dining-order` (prix=0) | Prix ignoré |

**Fichier :** `tests/Feature/TableOrderComprehensiveTest.php`

---

## 🔐 MODULE 8 : Sécurité & Isolation (10 tests)

> Tests transversaux de sécurité critique

| # | Test | Scénario | Résultat Attendu |
|---|------|----------|-----------------|
| 8.1 | Kiosk → Admin Dashboard | Token kiosk sur `/api/admin/dashboard/*` | 401/403 |
| 8.2 | Kiosk → Supprimer branche | Token kiosk sur `DELETE /api/admin/setting/branch/{id}` | 401/403 |
| 8.3 | Kiosk → Créer un item | Token kiosk sur `POST /api/admin/setting/item` | 401/403 |
| 8.4 | Kiosk → Modifier un prix | Token kiosk sur `PUT /api/admin/setting/item/{id}` | 401/403 |
| 8.5 | Client → Admin | Token client sur routes admin | 401/403 |
| 8.6 | **Branche A ≠ Branche B** | Chef A → commandes branche B | 0 résultats |
| 8.7 | **POS recalcul prix** | POST POS avec prix falsifié | Prix DB utilisé |
| 8.8 | **Transition statut invalide** | PENDING → DELIVERED directement | Rejeté |
| 8.9 | Sans API Key | Requête sans header `x-api-key` | 401/403 |
| 8.10 | Token expiré/révoqué | Requête avec token supprimé | 401 |

**Fichier :** `tests/Feature/SecurityComprehensiveTest.php`

---

## 🔄 MODULE 9 : Synchronisation Inter-Écrans (6 tests)

> Vérifie que quand une commande est créée/modifiée sur un écran, les autres la voient immédiatement.

| # | Test | Scénario | Résultat Attendu |
|---|------|----------|-----------------|
| ✅ 9.1 | Kiosk → KDS | Commande créée via Kiosk | Visible dans `GET /api/admin/kds-order` |
| ✅ 9.2 | POS → KDS | Commande créée via POS | Visible dans KDS |
| ✅ 9.3 | KDS → OSS | Statut changé en KDS | Reflété dans OSS |
| ✅ 9.4 | Table → KDS | Commande table créée | Visible dans KDS |
| ✅ 9.5 | POS → Admin Dashboard | Commande POS créée | Compteurs dashboard mis à jour |
| ✅ 9.6 | **Cohérence totale Kiosk→KDS→OSS** | Commande complète bout-en-bout | Même order_id partout |

**Fichier :** `tests/Feature/SyncComprehensiveTest.php`

---

## 📊 MODULE 10 : Dashboard & Rapports (5 tests)

> Surface : `/api/admin/dashboard/*`, `/api/admin/sales-report/*`

| # | Test | Route | Résultat Attendu |
|---|------|-------|-----------------|
| 10.1 | Total ventes | `GET /api/admin/dashboard/total-sales` | 200 + montant |
| 10.2 | Total commandes | `GET /api/admin/dashboard/total-orders` | 200 + count |
| 10.3 | Statistiques commandes | `GET /api/admin/dashboard/order-statistics` | 200 |
| 10.4 | Rapport ventes | `GET /api/admin/sales-report` | 200 |
| 10.5 | Export rapport | `GET /api/admin/sales-report/export` | 200 + fichier |

**Fichier :** `tests/Feature/DashboardReportTest.php`

---

## 📋 Résumé Exécutif

| Module | Nb Tests | Priorité | Fichier |
|--------|---------|----------|---------|
| 1. Auth & Accès | 8 | 🔴 Critique | `AuthComprehensiveTest.php` |
| 2. CRUD Admin | 20 | 🟡 Haute | `AdminCrudComprehensiveTest.php` |
| 3. POS/Caisse | 8 | 🔴 Critique | `POSComprehensiveTest.php` |
| 4. Kiosk/Frontend | 10 | 🔴 Critique | `KioskFrontendComprehensiveTest.php` |
| 5. KDS | 6 | 🔴 Critique | `KDSComprehensiveTest.php` |
| 6. OSS | 3 | 🟡 Haute | `OSSComprehensiveTest.php` |
| 7. Table/Dine-In | 4 | 🟡 Haute | `TableOrderComprehensiveTest.php` |
| 8. Sécurité | 10 | 🔴 Critique | `SecurityComprehensiveTest.php` |
| 9. Synchronisation | 6 | 🔴 Critique | `SyncComprehensiveTest.php` |
| 10. Dashboard | 5 | 🟢 Moyenne | `DashboardReportTest.php` |
| **TOTAL** | **80** | | |

## 🏁 Ordre d'Exécution

1. **Module 1 (Auth)** → Sans auth fonctionnelle, rien d'autre ne marche
2. **Module 8 (Sécurité)** → Valider les frontières avant d'écrire
3. **Module 2 (CRUD)** → Créer les données nécessaires aux tests suivants
4. **Module 3 (POS)** → Tester la caisse principale
5. **Module 4 (Kiosk)** → Tester la borne client
6. **Module 7 (Table)** → Tester les commandes sur table
7. **Module 5 (KDS)** → Vérifier que la cuisine voit tout
8. **Module 6 (OSS)** → Vérifier l'écran client
9. **Module 9 (Sync)** → Valider la cohérence bout-en-bout
10. **Module 10 (Dashboard)** → Vérifier les compteurs

## ✅ Critère de Réussite

```
php artisan test → 80/80 tests verts, 0 failures, 0 errors
```

Chaque test qui échoue = un **checkpoint d'amélioration** documenté dans un rapport séparé.

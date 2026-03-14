# PLAN MAÎTRE — Sprints KIMI 1A → 3
**Architecte :** Claude | **Date :** 12 Mars 2026  
**Source :** Audit KIMI (67 problèmes), Vérification Claude (100% confirmés)

---

## 🏗️ Architecture des Sprints

```
┌─────────────────────────────────────────────────────┐
│  SPRINT 3 (SEEDER)  ←  LANCER EN PREMIER             │
│  Menu POS vide — Bloquant opérationnel (P0)           │
│  Fichier : KIMI_SPRINT_3_SEEDER_MENU.md              │
│  Durée : 2h                                          │
└─────────────────────────────────────────────────────┘
              ↓ Après validation menu
┌─────────────────────────────────────────────────────┐
│  SPRINT 1-A (SÉCURITÉ)                               │
│  rand→UUID, Rate Limiting, PIN fort, IDOR           │
│  Fichier : KIMI_SPRINT_1A_SECURITE.md               │
│  Durée : 4h                                          │
└─────────────────────────────────────────────────────┘
              ↓ En parallèle ou après 1-A
┌─────────────────────────────────────────────────────┐
│  SPRINT 1-B (PERFORMANCE)                            │
│  N+1 queries, DB indexes, DB transactions            │
│  Fichier : KIMI_SPRINT_1B_PERFORMANCE.md            │
│  Durée : 4h                                          │
└─────────────────────────────────────────────────────┘
              ↓ Après 1A et 1B validés
┌─────────────────────────────────────────────────────┐
│  SPRINT 2 (FRONTEND)                                 │
│  Memory leaks Vue, setInterval, KDS pagination       │
│  Fichier : KIMI_SPRINT_2_FRONTEND.md                │
│  Durée : 4h                                          │
└─────────────────────────────────────────────────────┘
```

---

## 📋 Tous les problèmes — Décision Claude

| # | Problème | Fichier | Sévérité | Sprint | Décision |
|---|----------|---------|----------|--------|----------|
| 1 | `rand()` → UUID — Transaction IDs | `OrderService.php:918,942` | 🔴 P0 | 1-A | ✅ FIXER |
| 2 | `rand()` → Str::random() — Passwords | `GuestSignupController.php:101` | 🔴 P0 | 1-A | ✅ FIXER |
| 3 | `rand()` → Str::random() — Loyalty | `LoyaltyController.php:109` | 🔴 P0 | 1-A | ✅ FIXER |
| 4 | Aucun Rate Limiting auth | `routes/api.php` | 🔴 P0 | 1-A | ✅ FIXER |
| 5 | PIN trop faible (4 → 6 chiffres) | `ForgotPasswordController.php:42` | 🟡 P1 | 1-A | ✅ FIXER |
| 6 | IDOR : `return []` → `abort(403)` | `OrderService.php:791,811,827` | 🟡 P1 | 1-A | ✅ FIXER |
| 7 | `Item::get()` — Full table scan | `OrderService.php:266,443,647` | 🔴 P0 | 1-B | ✅ FIXER |
| 8 | Indexes DB manquants | Tables orders/items/users | 🟡 P1 | 1-B | ✅ FIXER |
| 9 | DB transactions incohérentes | `ItemCategoryService.php:152` | 🔴 P0 | 1-B | ✅ FIXER |
| 10 | Race condition queue number | `OrderService.php:357-368` | 🟡 P1 | 1-B | ✅ FIXER |
| 11 | Memory leak KDS | `KitchenDisplaySystemComponent.vue` | 🟡 P1 | 2 | ✅ FIXER |
| 12 | Memory leak setInterval global | Tous les composants Vue | 🟡 P1 | 2 | ✅ FIXER |
| 13 | KDS sans pagination | `KitchenDisplaySystemOrderService` | 🟡 P1 | 2 | ✅ FIXER |
| 14 | `status => 1` dans MenuSeeder | `MenuSeeder.php:338-637` | 🔴 P0 | 3 | ✅ FIXER |
| - | **God class OrderService (1149L)** | `OrderService.php` | 🔴 P0 | ⏳ SPRINT 4 | ⏳ DIFFÉRER |
| - | **Caching Layer (Redis)** | Tous les services | 🟡 P1 | ⏳ SPRINT 4 | ⏳ DIFFÉRER |
| - | SQL Injection pattern | `ItemService.php:99` | 🟡 P1 | ⏳ SPRINT 4 | ⏳ DIFFÉRER |
| - | DDD / Microservices | Architecture globale | 🟢 P2 | ⏳ SPRINT 5 | ⏳ DIFFÉRER |

---

## 📊 Score de Santé du Projet (avant/après sprints)

| Catégorie | Score Actuel | Score Cible | Sprints |
|-----------|-------------|-------------|---------|
| Sécurité | 3/10 | 8/10 | 1-A |
| Performance | 4/10 | 7/10 | 1-B |
| Stabilité Frontend | 5/10 | 9/10 | 2 |
| Menu POS | 0/10 (vide) | 10/10 | 3 |
| **Score Global** | **3/10** | **8.5/10** | **Tous** |

---

## 🔄 Protocole KIMI ↔ Claude

Pour chaque sprint :

1. KIMI implémente les fixes du fichier `KIMI_SPRINT_Xn_*.md`
2. KIMI exécute les tests obligatoires de son fichier plan
3. KIMI exécute le **"Auto-Audit KIMI"** en fin de fichier
4. KIMI écrit les résultats dans `reports/execution/latest.md`
5. **Claude vérifie** `reports/execution/latest.md`
6. **Claude approuve** (✅) ou retourne (`🔄 REQUEST_FIX` avec détails)
7. Si ✅ → KIMI commence le sprint suivant

---

## ⚠️ Zones Gelées (NE PAS TOUCHER)

Ces zones sont marquées GELÉES dans la codebase — KIMI ne modifie PAS ces fichiers :
- `app/Services/PaymentService.php` — Transactions financières réelles
- Tous les payment gateways controllers (Stripe, PayPal, etc.)
- `app/Services/PushNotificationService.php`

---

## 📄 Fichiers Plan KIMI

| Sprint | Fichier | Taille estimée |
|--------|---------|----------------|
| Seeder (URGENT) | `reports/planning/KIMI_SPRINT_3_SEEDER_MENU.md` | 2h travail |
| Sécurité P0 | `reports/planning/KIMI_SPRINT_1A_SECURITE.md` | 4h travail |
| Performance P0 | `reports/planning/KIMI_SPRINT_1B_PERFORMANCE.md` | 4h travail |
| Frontend P1 | `reports/planning/KIMI_SPRINT_2_FRONTEND.md` | 4h travail |

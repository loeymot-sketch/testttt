# AUDIT PROFOND — FoodKing SaaS
## Analyse multi-dimensionnelle du système

**Date :** 12 Mars 2026  
**Type :** Audit technique complet (sécurité, architecture, qualité, performance, tests)  
**Méthodologie :** Analyse statique du code, revue documentaire, comparaison avec les standards

---

## 1. RÉSUMÉ EXÉCUTIF

| Dimension | Score | Verdict |
|-----------|-------|---------|
| **Architecture** | 8/10 | ✅ Solide, MVC bien structuré, docs alignées |
| **Sécurité** | 7/10 | ⚠️ Bonne base, quelques failles résiduelles |
| **Qualité de code** | 7/10 | ✅ Cohérent, dette technique localisée |
| **Performance** | 8/10 | ✅ Optimisations récentes (N+1, index) |
| **Tests** | 8/10 | ✅ 29 suites, couverture core métier |
| **Documentation** | 9/10 | ✅ Excellente (docs/, workflows, reports) |

**Verdict global :** Système mature et bien documenté. Les corrections des Sprints 1–4 ont renforcé la sécurité et l’UX. Quelques points de vigilance restent à traiter.

---

## 2. ARCHITECTURE & DOCUMENTATION

### 2.1 Structure

```
app/
├── Http/Controllers/   # Admin, Frontend, Auth, Table
├── Services/          # OrderService, FrontendOrderService, CouponService...
├── Models/            # Order, Item, KioskMachine...
├── Enums/             # Status, OrderStatus, PaymentStatus...
└── Libraries/         # AppLibrary, QueryExceptionLibrary
```

**Points forts :**
- Séparation claire Controllers → Services → Models
- Single Source of Truth pour les prix (DB + recalcul serveur)
- Sanctum pour l’auth, Spatie pour les rôles
- Zones gelées documentées (ARCHITECTURE.md)

**Points d’attention :**
- `OrderService` reste une classe volumineuse (~900 lignes)
- Certains services (CustomerService, ChefService) utilisent `mt_rand()` pour des emails temporaires

### 2.2 Documentation

| Document | État | Alignement code |
|----------|------|------------------|
| ARCHITECTURE.md | ✅ Complet | Oui |
| ORDER_FLOW.md | ✅ Complet | Oui |
| DEVICE_FLOW.md | ✅ Complet | Oui |
| AUTHZ_MATRIX.md | ✅ Complet | Oui |
| SECURITY_NOTES.md | ✅ Complet | Oui |
| API_MAP.md | ✅ Complet | Oui |
| BUSINESS_RULES.md | ✅ Complet | Oui |
| DATABASE_SCHEMA_CORE.md | ✅ Complet | Oui |
| ERROR_HANDLING.md | ✅ Complet | Oui |

---

## 3. AUDIT SÉCURITÉ

### 3.1 ✅ Points validés

| Élément | Statut |
|---------|--------|
| Recalcul des prix côté serveur | ✅ OrderService, FrontendOrderService ignorent les totaux client |
| Validation JSON (ValidJsonOrder) | ✅ Rejet des items sans item_id |
| Throttling API | ✅ 200 req/min (Kernel.php) |
| IDOR OrderService | ✅ `abort(403)` sur accès non autorisé |
| TXN IDs | ✅ `Str::random(12)` (Sprint 1-A) |
| Guest password | ✅ `Str::random(10)` (Sprint 1-A) |
| ForgotPassword PIN | ✅ `random_int()` (Sprint 1-A) |
| OrderStatusRequest | ✅ Vérification rôles Admin/Manager/Chef/Cashier |
| Isolation KDS | ✅ branch_id vérifié |
| CouponService | ✅ Validation serveur des réductions |

### 3.2 ⚠️ Failles résiduelles

#### P1 — `rand()` / `mt_rand()` restants

| Fichier | Ligne | Usage | Risque |
|---------|-------|-------|--------|
| `Credit.php` | 33 | `rand(111111111, 999999999)` pour token paiement | Token prédictible, collision possible |
| `OtpManagerService.php` | 38, 43 | `rand()` pour code OTP | OTP faiblement aléatoire |
| `CustomerService.php` | 159 | `mt_rand()` pour email temporaire | Faible (usage interne) |
| `ChefService.php` | 160 | idem | Faible |
| `EmployeeService.php` | 181 | idem | Faible |
| `WaiterService.php` | 161 | idem | Faible |
| `DeliveryBoyService.php` | 161 | idem | Faible |

**Recommandation :**
- `Credit.php` : remplacer par `Str::random(16)` ou `Str::uuid()`
- `OtpManagerService.php` : remplacer par `random_int()`
- Services (email temporaire) : priorité basse, usage interne uniquement

#### P2 — XSS potentiel (v-html)

| Fichier | Usage |
|---------|-------|
| `PageComponent.vue` (frontend, table) | `v-html="page.description"` |
| `PageShowComponent.vue` | `v-html="page.description"` |

**Risque :** Si `page.description` est saisi par un admin non fiable ou provient d’une source externe, risque XSS.

**Recommandation :** Vérifier que le contenu est sanitisé (Quill/éditeur riche) ou utiliser une lib de sanitization (DOMPurify).

#### P3 — `env()` direct dans le middleware

```php
// ApiKeyMiddleware.php:19
$validApiKey = env('MIX_API_KEY', config('app.api_key'));
```

**Risque :** `env()` ne doit pas être utilisé en dehors de la config (cache, performances).

**Recommandation :** Utiliser uniquement `config('app.api_key')` et définir la valeur dans `config/app.php`.

### 3.3 Requêtes SQL

- `ItemImport.php` : `whereRaw('LOWER(name) LIKE ?', [...])` — paramètres liés ✅
- Aucune concaténation directe de données utilisateur dans des requêtes brutes détectée.

---

## 4. QUALITÉ DE CODE

### 4.1 Patterns respectés

- FormRequests pour la validation
- Services pour la logique métier
- Enums pour les statuts
- Transactions DB pour les opérations critiques

### 4.2 Dette technique

| Élément | Localisation | Sévérité |
|---------|--------------|----------|
| God class | OrderService (~900 lignes) | Moyenne |
| `rand()` / `mt_rand()` | Voir section 3.2 | Haute (Credit, OTP) |
| `env()` direct | ApiKeyMiddleware | Faible |
| Seeders | UserTableSeeder utilise `rand()` pour appartements | Négligeable (données de test) |

### 4.3 Cohérence

- Conventions Laravel respectées
- Nommage cohérent (snake_case, camelCase selon le contexte)
- Commentaires présents sur les correctifs de sécurité (SECURITY FIX, PERF-01, etc.)

---

## 5. PERFORMANCE

### 5.1 Optimisations récentes (Sprint 1-B)

| Optimisation | Fichier | Impact |
|--------------|---------|--------|
| N+1 query | OrderService, FrontendOrderService | `Item::select()->whereIn()` au lieu de `Item::get()` |
| Index DB | 9 migrations | Requêtes plus rapides |

### 5.2 Points à surveiller

- `Tax::get()` utilisé dans des boucles (OrderService) — potentiel N+1 si beaucoup de taxes
- `AppLibrary::pluck(Tax::get(), ...)` — chargement complet de la table taxes

---

## 6. TESTS

### 6.1 Couverture

| Suite | Fichier | Focus |
|-------|---------|-------|
| ValidJsonOrderTest | Unit/Rules | Validation JSON commande |
| OrderServiceSecurityTest | Unit/Services | Prix, IDOR |
| TableOrderSecurityTest | Feature | Sécurité commande table |
| AntiGravityTest | Feature | Auth, isolation, prix |
| POSComprehensiveTest | Feature | Flux POS |
| KDSFlowTest | Feature | Flux cuisine |
| OSSReadOnlyTest | Feature | OSS lecture seule |
| CouponSecurityTest | Feature | Coupons |
| BranchIsolationTest | Feature | Isolation branches |
| KioskAuthTest | Feature | Auth Kiosk |
| ... | ... | ... |

**Total :** 29 fichiers de tests.

### 6.2 Lacunes

- Pas de tests E2E automatisés (Playwright/Cypress) pour le frontend
- Pas de tests de charge documentés pour le KDS
- Tests manuels requis pour le wizard POS (pos-wizard.js)

---

## 7. DÉPENDANCES & DETTE TECHNIQUE

### 7.1 Stack

- Laravel 9, PHP 8.1+
- Vue 3, Vuex, Vue Router
- MySQL 8+
- Sanctum, Spatie Permission
- Laravel Mix (webpack)

### 7.2 Zones gelées (ARCHITECTURE.md)

- Gateways de paiement (Stripe, Paypal, Credit)
- Push Notifications (Firebase)
- Module Analytics Admin
- Delivery Boy Logic

---

## 8. RECOMMANDATIONS PRIORISÉES

### P0 — Critique (à traiter en priorité)

| # | Action | Fichier | Effort |
|---|--------|---------|--------|
| 1 | Remplacer `rand()` par `Str::random(16)` | Credit.php:33 | 5 min |
| 2 | Remplacer `rand()` par `random_int()` | OtpManagerService.php:38,43 | 10 min |

### P1 — Important

| # | Action | Fichier | Effort |
|---|--------|---------|--------|
| 3 | Remplacer `env()` par `config()` | ApiKeyMiddleware.php | 5 min |
| 4 | Audit sanitization `v-html` | PageComponent.vue, PageShowComponent.vue | 30 min |
| 5 | Remplacer `mt_rand()` par `Str::random()` | CustomerService, ChefService, etc. | 15 min |

### P2 — Amélioration

| # | Action | Effort |
|---|--------|--------|
| 6 | Refactoriser OrderService (extraction de sous-services) | 2–4 h |
| 7 | Ajouter tests E2E frontend (Playwright) | 1–2 j |
| 8 | Documenter et automatiser tests de charge KDS | 4 h |

---

## 9. SYNTHÈSE PAR MODULE

| Module | État | Risques |
|--------|------|---------|
| **Auth** | ✅ Bon | — |
| **POS** | ✅ Bon | — |
| **KDS** | ✅ Bon | — |
| **Kiosk API** | ✅ Bon | — |
| **OrderService** | ✅ Bon | God class |
| **Paiement Credit** | ⚠️ À corriger | rand() token |
| **OTP** | ⚠️ À corriger | rand() code |
| **Pages (v-html)** | ⚠️ À auditer | XSS potentiel |

---

## 10. CONCLUSION

Le système FoodKing est **solide et bien documenté**. Les Sprints 1–4 ont renforcé la sécurité (rand→Str::random, IDOR, throttle), la performance (N+1, index) et l’UX (panier, wizard, flow sandwich).

**Actions immédiates recommandées :**
1. Corriger `rand()` dans Credit.php et OtpManagerService.php
2. Remplacer `env()` par `config()` dans ApiKeyMiddleware
3. Vérifier la sanitization du contenu des pages (v-html)

**Prochaines étapes :**
- Exécuter les tests E2E après corrections
- Planifier le refactoring d’OrderService
- Envisager des tests E2E automatisés pour le frontend

---

**Fin de l’audit — 12 Mars 2026**

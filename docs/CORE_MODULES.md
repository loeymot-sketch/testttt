# 🛑 FoodKing SaaS : Périmètre & Modules Core

Ce document sert de **manifeste de développement** pour toute équipe intervenante (Devs, Bots QA, Routage IA). Le projet comporte un historique large, mais pour la phase actuelle de fiabilisation et de déploiement "Local/Kiosk -> SaaS", le périmètre est strictement délimité.

---

## 🟢 1. Modules ACTIFS (Core Business - À maintenir et faire évoluer)

Ces modules constituent le cœur de la valeur actuelle et sont couverts par les tests d'intégrité métier. Ils peuvent être modifiés, étendus ou refactorés sous couvert du maintien de la suite de tests (`php artisan test`).

### A. Flux Kiosk (Flutter ↔ API)
- `App\Services\FrontendOrderService` : Création de la commande Kiosk.
- `App\Http\Controllers\Frontend\OrderController` : Endpoint de commande sans auth client complet.
- Middleware Sanctum : Token d'accès `kiosk:order`.

### B. Moteur de Pricing & Cash
- `App\Services\OrderService` : Logique interne du POS Web.
- `App\Services\CouponService` : Injection et validation des discounts.
- *Règle stricte* : Toute route POST créant une commande DOIT recalculer son prix via `Item::find()` en ignorant le prix poussé par la requête cliente.

### C. KDS (Kitchen Display System)
- Gestion des affichages par `branch_id`.
- Transitions de statut des commandes (`App\Enums\OrderStatus`).

### D. Vue 3 POS & Admin UI
- Tout le dossier `resources/js/` (Vue, Vuex, Components).
- Buildé actuellement par Laravel Mix.

---

## ❄️ 2. Modules GELÉS (Frozen - Ne pas toucher)

Ces modules existent dans le codebase (héritage) mais ne sont pas utilisés dans le scope actuel ou sont considérés comme instables/à remplacer plus tard. **Ne pas corriger de bugs mineurs dedans, ne pas refactorer.**

### A. Gateways de Paiement Externes
- *Symptôme* : Code Stripe, Paypal, Paystack, Razorpay etc.
- *Statut* : Standby. Le flux Kiosk actuel gère le paiement sur TPE physique externe et valide le statut localement. Les gateways en ligne seront réactivées dans une phase SaaS ultérieure.

### B. Delivery Boy (Livreurs)
- *Symptôme* : Application Flutter Delivery, assignation de course, tracking GPS.
- *Statut* : Gelé. La branche actuelle de test se concentre sur le Takeaway (Kiosk) et le Dine-In.

### C. Push Notifications (Subsystem Firebase Natif)
- *Symptôme* : `App\Services\PushNotificationService`, appels FCM complexes.
- *Statut* : Gelé structurellement. Si les notifications marchent, ne pas y toucher. C'est lié à un flux Guzzle hérité.

### D. Admin Analytics Avancé
- *Symptôme* : Rapports très lourds dans `Admin/DashboardController`.
- *Statut* : Ne nécessite pas de modification d'architecture pour le fonctionnement Core.

---

## 🛠️ 3. Directives pour l'IA (Cursor, Bots)

1. **Ne proposez pas de refactor global** (ex: Remplacer Laravel Mix par Vite sur tout le front) sans accord explicite du Lead.
2. **Ne proposez pas de supprimer les modules gelés**, ils seront réactivés/purgés dans la Phase SaaS V2.
3. Si un bug touche un Module Gelé, remontez l'alerte au lieu de tenter de le patcher en profondeur.
4. **Tests** : Toute modification d'un Module Actif doit passer impérativement `php artisan test` avec validation des contraintes métiers (Auth Kiosk, Isolation Branch, Recalcul Prix).

# Sécurité & Falsification (Security Notes)

Le projet **FoodKing SaaS** possède de nombreux contrôles côté serveur gérant des transactions financières et logistiques vitales. Ce document liste les mesures critiques en place.

## 1. Intégrité des Données (Pricing & Cash)
- ✅ **En place et Testé : Recalcul Forcé (Single Source of Truth)** : Toutes les routes `POST` créant des commandes (que ce soit pour le Kiosk `FrontendOrderService` ou la table `OrderService::tableOrderStore`) **ignorent** les prix, options et `total` présents dans le payload JSON du client. Les données sont validées et recalculées mathématiquement après une requête en Base de Données (`Item::find()`). Impossible de payer 0.01€ en modifiant la requête réseau. *(Testé via TableOrderSecurityTest et OrderFlowTest)*
- ✅ **En place et Testé : Discount & Coupons** : Pareil pour la réduction. Elle est évaluée par le backend selon les conditions du coupon (pourcentage + limite max, ou valeur fixe) par le `CouponService`. *(Testé via CouponSecurityTest)*

## 2. Transition de Statut
- ⚠️ **En place mais Test partiel** : Seul certains flux directionnels sont autorisés lors de la modification des états (e.g., Un client ne peut pas se livrer `DELIVERED` seul depuis le web).
- ✅ **En place et Testé** : Protection du KDS : Un chef de la branche A ne peut pas passer à `PREPARED` une commande de la branche B. *(Testé via KDSFlowTest)*

## 3. Protection Base de Données (Concurrency)
- ⚠️ **En place mais Non testé en stress** : **Race Condition** : En pleine heure de pointe, la génération séquentielle alphanumérique des tickets `queue_number` (`A015`, `A016`) risque le chevauchement (deadlock). Ce phénomène est géré par la requête InnoDB `lockForUpdate()` forçant les transactions d'écriture à passer l'une après l'autre.

## 4. Audit Trail
- Les actions sensibles menées par les caissiers (e.g. Forcer une annulation) sont historisées avec l'`User ID` et l'`Order ID` dans la table `action_logs` pour les audits Manager / Dashboard Boss.

## 5. Rate Limiting
- Les APIs Frontend Kiosk / Mobile sont protégées par le middleware natif `ThrottleRequests` configuré à e.g `200 appels / minute`.

## XSS Prevention

### Policy
`v-html` is prohibited by default in Vue components. All user-facing HTML rendering must go through `safeHtml()` from `resources/js/utils/safeHtml.js`.

### Audit (2026-04-15)
| # | File | Pattern | Resolution |
|---|---|---|---|
| 1 | table/page/PageComponent.vue | v-html | Sanitized via safeHtml() |
| 2 | admin/settings/Page/PageShowComponent.vue | v-html | Sanitized via safeHtml() |
| 3 | frontend/page/PageComponent.vue | v-html | Sanitized via safeHtml() |
| 4 | frontend/account/chat/ChatComponent.vue | innerHTML | Replaced with textContent |
| 5 | admin/messages/MessageListComponent.vue | innerHTML | Replaced with textContent |

### Exceptions
- `v-html` with `safeHtml()` is allowed for Quill editor rich-text output only.
- Blade `{!! !!}` in master.blade.php for analytics scripts (admin-configured) is an accepted risk.

### Adding new exceptions
Requires explicit PR review and documentation update.

# Axe 1–2 — Architecture POS & state management

**Axe 1 — Backend & frontières**

| Couche | Rôle | Notes audit |
|--------|------|-------------|
| `OrderService` | Création POS, list, statuts, destroy, fiscal hooks | Volumineux ; `branch_id` dans `orderFilter` = **filtre explicite** ; gardes **403** sur mutations si branche ≠ user (`~1490`, `~1604`, `~1721`) |
| `PricingService` | SSOT prix | Hors lecture exhaustive ce run — aligné `BUSINESS_RULES` principe |
| `PaymentService` | Cashback / gateway | Utilisé annulations / retours |
| `FiscalSequenceService` | `orders.fiscal_sequence_no` monotonic + `lockForUpdate` | Bien aligné NF525 technique |
| `AuditLogService` | Chaîne HMAC, lock cache | Cœur conformité |
| `ZReportService` / `XReportService` | Z signé, X lecture | Voir axe fiscal |
| **Vue admin** | `PosComponent.vue`, `PaymentComponent.vue`, stores | Idempotence + timeout 30s |

**Frontières API (extraits)**

- `POST /api/admin/pos` — throttle `pos-order-create`, permission `pos` côté contrôleur.
- `GET/... /api/admin/pos-order/*` — permissions dans `PosOrderController` (pas dans `routes/api.php` seul).
- `routes/api.php` **624–641** — groupe `pos` + `pos-order`.

**Axe 2 — Vuex & courses**

- **`posOrder.js` `save`** : `X-Idempotency-Key` + `AbortController` 30s — **bon**.
- **`posCart.js`** : état panier séparé ; pas d’idempotence (normal).
- **Risque** : regénération clé idempotence sur **nouvelle** tentative `orderSubmit` → **deux commandes** si utilisateur relance avant abandon — voir `F-STATE-002`.
- **localStorage** token caisse : non atomique → minuscules collisions inter-onglets.

**Liens tracker :** F-ARCH-001, F-ARCH-002, F-STATE-001, F-STATE-002.

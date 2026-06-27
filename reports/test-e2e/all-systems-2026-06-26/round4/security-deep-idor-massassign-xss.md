# Lane — Sécurité profonde : IDOR + mass-assignment + XSS + rate-limit
Round 4 · 2026-06-26 · READ-ONLY · DB foodking_e2e

## Verdict : findings:[] (aucun vecteur NOUVEAU prouvé exploitable)

Les 4 sous-lanes sont vérifiées SOLIDES. Le codebase a déjà absorbé 22+ heals
sécurité (refund-bypass, my-order IDOR P0, kiosk-token→/profile, coupon-scope,
delivery-fee-forge). Mes sondes confirment que les classes de bug de ma mission
sont fermées. Preuves ci-dessous (verify-before-report).

---

## (1) IDOR sweep — VERIFIED CLEAN

### Customer order self-IDOR — PROTÉGÉ
- `routes/api.php:1315` `GET /order/show/{frontendOrder}` (auth:sanctum, pas de
  permission) → `Frontend/OrderController::show` (`app/Http/Controllers/Frontend/OrderController.php:71`)
  → `FrontendOrderService::show` (`app/Services/FrontendOrderService.php:692-702`).
  **Ownership check ligne 695** : `if ((int)$frontendOrder->user_id === (int)Auth::id())`
  sinon `abort(403)`. Client B ne lit PAS la commande du client A. ✓
- `changeStatus` (`:733`) et `paymentConfirm` (`OrderController.php:116-130`) ont
  le même garde user_id + garde cross-branch kiosk_machine. ✓
- Idempotency-recovery scopé user_id (`FrontendOrderService:720`, durci 2026-06-27). ✓

### Cross-branch route-model-binding — PROTÉGÉ par BranchScope
Tous les modèles sensibles bound en route portent BranchScope (vérifié grep
`addGlobalScope(BranchScope)` sur app/Models) :
Order, FrontendOrder, OrderItem, OrderPayment, OrderQuote, PosParkedOrder,
CashDrawerSession/Movement, DeliveryBoyCash*, StockLevel/Movement,
PaymentTerminal, PendingPaymentConfirmation, KioskMachine, DiningTable,
PushNotification, Printer, ItemBranchAvailability, **User**.
→ Un staff branche B ne peut PAS binder une commande/paiement/cash branche A
(404 via global scope). Les routes admin `{customer}/{order}/{address}` sont en
plus gated `permission:*_show`. Le my-order P0 IDOR a déjà été healé
(`routes/api.php:613` OR-gate 6 SPA). ✓
- Note V1-LOCAL : l'isolation cross-branch est de toute façon mono-branche
  (branch_id=1) — non-exploitable en prod V1. Modèles non-scopés restants
  (Message, OrderDiscountLog, ZReport, AuditLog…) = non-PII / V1.0.2 backlog
  documenté = P3 cloud-prep, pas un finding V1.

## (2) Mass-assignment — VERIFIED CLEAN
- Signup client : `Auth/SignupController.php:86` + `GuestSignupController.php:116`
  → `User::create([...])` avec `branch_id => 0` **codé en dur** + `assignRole(CUSTOMER)`.
  Le client ne peut PAS injecter branch_id/role/status. ✓
- Profil client : `ProfileService::update` (`app/Services/ProfileService.php:21-38`)
  assigne UNIQUEMENT name/phone/email/country_code attribut par attribut — pas de
  `$request->all()`, pas de role/status/branch_id. ✓
- Création staff : `WaiterService:69` / `ChefService:68` → `assignRole(WAITER/CHEF)`
  codé en dur + `branch_id => effectiveBranchId(auth()->user(), $request->branch_id)`
  (épingle la branche du créateur ; un branch-manager ne crée pas hors de sa branche
  ni un Admin). Pas d'escalade de privilège. ✓
- Les `$requests = $request->all()` repérés (SimpleUserService:22, Waiter/Chef list,
  ItemService…) sont des **filtres de liste** : `$request->all()` itéré contre une
  whitelist `in_array($key, $this->xFilter)` — jamais passé à `Model::create()`.
  Vérifié SimpleUserService:26-48. ✓

## (3) XSS user-content — VERIFIED CLEAN
- Tous les `v-html` du repo (4 occurrences : PageShowComponent.vue:21,
  frontend/page/PageComponent.vue:12, table/page/PageComponent.vue:12,
  ds/KsThemeToggle.vue:22) passent par `safeHtml(...)`.
- `resources/js/utils/safeHtml.js:1-5` = `DOMPurify.sanitize(String(raw), {...})`.
  Sanitisation réelle (pas no-op). Contenu Page = CMS admin de toute façon. ✓
- Aucun backend renvoyant du HTML user non-échappé repéré. ✓

## (4) Rate-limit routes mutantes — VERIFIED CLEAN
- `POST /auth/login` → `throttle:login-lockout` (`routes/api.php:161`).
- `POST /auth/kiosk-login` → `throttle:kiosk-login`.
- `forgot-password` 3/60 ; verify-code 5/1 ; reset 5/1.
- signup/guest-signup OTP send 5/1 ; verify 3/5 (anti brute-force 4-digit, calcul
  documenté ligne 198). register 10/1.
- loyalty register 5/1 ; quote/order kiosk `throttle:kiosk-orders` ; table-order 20/1 ;
  payment reconcile 5/1. Mutations POS/order portent `idempotency`. ✓

---

## Méthode anti-hallucination
Chaque ligne ci-dessus = Read/grep confirmé + raisonnement de repro (qui/quoi).
Aucun finding spéculatif surfacé. Sévérité V1-LOCAL appliquée (cross-branch
mono-branche = non-P0/P1).

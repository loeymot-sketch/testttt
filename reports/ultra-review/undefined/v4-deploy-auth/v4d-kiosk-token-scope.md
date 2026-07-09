# V4-DEPLOY Axe 2 — Kiosk-token scope re-attack (`v4d-kiosk-token-scope`)

HEAD 61e9ea7b7 + working-tree. Server LIVE 127.0.0.1:8766 (foodking_e2e).
Posture: refute-by-default. Guard model: default guard = `sanctum`
(config/auth.php:17). GuestSignupController mints a 30-day token with
ability `['kiosk:order']`, role CUSTOMER, `branch_id=0`, **no KioskMachine**
(GuestSignupController.php:146). So any `auth:sanctum` frontend route that
does NOT additionally require a real KioskMachine row OR an ownership check
is reachable by a plain guest token.

## FINDING (P2) — IDOR read+delete on `/api/frontend/message/*`

- Route group `frontend.message.*` (routes/api.php:1378-1384) = middleware
  `['installed','apiKey','localization']` (parent) + `['auth:sanctum']`.
  **No `tokenCan`, no KioskMachine, no ownership gate.**
- `GET /api/frontend/message/show/{message}` → MessageController@show
  (MessageController.php:32) → MessageService::show() (MessageService.php:98)
  = `return $message;` — returns ANY message resolved by route-model-binding,
  no `user_id` scoping.
- `DELETE /api/frontend/message/{message}` → MessageController@destroy
  (MessageController.php:50) → MessageService::destroy() (MessageService.php:81)
  = deletes the message + all MessageHistory rows, **no ownership check**.
- `Message` is BranchScope-EXEMPT (CLAUDE.md §9 V1.0.2 backlog list), so the
  `{message}` binding is unscoped globally → any id resolves.

Live repro (tinker READ-ONLY): `Message::count()=2`, first message
`user_id=28`. Guest user id=45 (`is_guest=1`, `branch_id=0`) holds a
`kiosk:order` token. That token satisfies `auth:sanctum` on this group, so
guest #45 can `GET /show/{id}` (read another customer's message thread / PII)
and `DELETE /{id}` (destroy user #28's messages + history). Cross-user
read + destructive delete by a low-priv guest token = IDOR.

Note: the `PUT|PATCH /{message}` route points to `update`, which does NOT
exist on MessageController → that verb is dead (dispatch error), not a vuln.

Severity rationale = **P2, not P1**: V1 LOCAL single-restaurant, messaging is
a minor/near-unused feature (2 rows), zero fiscal/payment/money impact, no
privilege escalation. It is a genuine IDOR but low blast radius for V1. Fix =
scope `show`/`destroy` (and `index`) to `where('user_id', auth()->id())` +
abort 403 on mismatch (mirror of the AddressController pattern already in
place). Not kiosk-specific — reachable by ANY authenticated sanctum user.

## REFUTED — `PUT /api/profile` "kiosk-token profile IDOR" (previously escalated)

- Route `profile.update` (routes/api.php:258), middleware
  `['installed','apiKey','auth:sanctum','localization']`.
- ProfileController@update (ProfileController.php:33) →
  ProfileService::update() (ProfileService.php:21) operates EXCLUSIVELY on
  `User::find(auth()->user()->id)` — no target id from the request body.
- ProfileRequest rules `Rule::unique('users','email'/'phone')->ignore(auth id)`
  prevent colliding onto another account (ProfileRequest.php:35,38).
- Therefore a kiosk/guest token can only mutate ITS OWN user row
  (name/email/phone/country_code). **Not a cross-user IDOR.** By design for
  the customer/guest self-profile flow. REFUTED as P0/P1/IDOR.

## INSPECTED & SAFE (return/guard present)

- `POST /api/frontend/order/` (store) — OrderRequest::authorize checks
  `tokenCan('kiosk:order')` (OrderRequest.php:83); web-wireup path
  (guest-no-machine → WEB order) is an accepted owner override, not a bypass.
- `POST /order/change-status/{frontendOrder}` — OrderStatusRequest::authorize
  (OrderStatusRequest.php:31) limits kiosk:order tokens to status=CANCELED;
  service enforces ownership: `if ($order->user_id === Auth::id()) {...} else
  abort(403)` (FrontendOrderService.php:750,831).
- `GET /order/show/{frontendOrder}` — FrontendOrderService::show aborts 403 on
  non-owner (FrontendOrderService.php:710-713).
- `GET /order/show/{frontendOrder}/escpos` — requires real KioskMachine +
  order ownership + branch match (OrderController.php:93-100).
- `POST /order/{frontendOrder}/payment-confirm` — requires real KioskMachine +
  ownership + branch (OrderController.php:147-163).
- `POST /payment/reconcile-pending` — tokenCan + real KioskMachine required
  (PaymentReconcileController.php:86-99).
- `POST /loyalty/add-points` — staff-role-gated, guest → 403 (LoyaltyController.php:230).
- `POST /loyalty/redeem` — `$isKiosk` requires a REAL KioskMachine row
  (LoyaltyController.php:324-328); non-kiosk non-staff falls to owner-only
  check (line 350). Healed LOYALTY-IDOR holds.
- `/loyalty/balance|history|qr` — self-scoped to caller.
- `/api/frontend/address/*` — explicit `user_id === auth()->id()` ownership
  checks on show/update/destroy (AddressController.php:36,60,73).
- `/api/frontend/order` (index) — myOrder scopes `where('user_id', auth id)`
  (FrontendOrderService.php:100).
- `POST /device-token/kiosk` — self-scoped (`KioskMachine where user_id =
  auth id`) (TokenStoreService.php:56).
- `GET /menu`, `POST /pricing/preview`, `POST /promo/validate`,
  `GET /upsell` — FormRequests / controller check `tokenCan('kiosk:order')`
  (MenuController.php:37, PricingPreviewRequest.php:30, PromoValidateRequest.php:19,
  UpsellController.php:32). Reads only, low sensitivity.
- `POST /kiosk-event` — route-level `abilities:kiosk:order` (routes/api.php:1462).
- `/api/admin/*` mutations — `block_kiosk_token_admin` middleware strips
  kiosk-only tokens (routes/api.php:281,302).

## Observation (not a finding)
Guest users are created with `branch_id=0` (GuestSignupController.php:120),
i.e. the BranchScope "admin bypass" bucket. No concrete exploit found under
read-only: frontend mutations that touch branch-scoped models enforce
explicit `user_id` ownership, and `/api/admin/*` is blocked by
`block_kiosk_token_admin`. Worth a V2/cloud hardening note (guest should be
scoped to its ordering branch, not 0), NOT a V1 P0/P1.

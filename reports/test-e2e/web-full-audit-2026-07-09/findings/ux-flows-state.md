# Web standalone — UX FLOWS & STATE audit (2026-07-09)

Target: /Users/1millnonstop/Downloads/web (React+Babel-in-browser static site)
Live: http://127.0.0.1:8096 → API http://127.0.0.1:8766 (health ok, feature-online-card=0)

Every finding below has file:line + reproduced code/curl evidence.

---

## P1 — ux-otp-order-silent-failure
**PaymentPage: order failure on the OTP-verify path is SILENT (money path).**

funnel.jsx:433-446 `verifyOtp`:
```
try {
  await api.guestVerify(phone, authOtp.trim());
  setAuthStep('none'); setAuthBusy(false);   // <-- hides the OTP gate
  setSubmitting(true);
  await placeRealOrder();                     // <-- can throw (resolve/422/network)
} catch (e) {
  setAuthBusy(false); setSubmitting(false);
  setAuthErr((e && e.message) || 'Code invalide.');  // authErr set…
}
```
The `authErr` message is rendered ONLY inside `{authStep !== 'none' && (...)}` (funnel.jsx:561, message at 591-595). Because `setAuthStep('none')` already ran (line 439) before `placeRealOrder()` is awaited, when `placeRealOrder` throws the OTP gate is unmounted → **authErr is never displayed**. `apiError` (the other error surface, funnel.jsx:554) is NOT set on this path either. Result: a fresh guest who authenticates then hits an order-placement error (article resolve failure funnel path api.js:274, backend 422, or network drop) sees the button return to "Confirmer la commande" with **no error, no order, no explanation**.
- Contrast: a wrong OTP fails at `guestVerify` BEFORE `setAuthStep('none')`, so that error DOES show — only the post-auth order failure is swallowed.
- Reproduced reality: order endpoint is real and can fail — `POST /api/frontend/order` (no token) → **HTTP 401**; resolveLine throws on unmatched item (api.js:274).

## P1 — ux-idempotency-key-regenerated-duplicate-order
**Idempotency key is regenerated on every placeOrder call → retries create DUPLICATE orders (money path).**

api.js:446:
```
var r = await req('POST','/api/frontend/order',{ auth:true, body:body,
  idempotencyKey: o.idempotencyKey || ('web'+uuid().replace(/-/g,'').slice(0,24)) });
```
Callers never pass a stable `o.idempotencyKey`: funnel.jsx:390 `await api.placeOrder(orderOpts)` where `orderOpts` (funnel.jsx:378) has no idempotencyKey. So **each invocation mints a fresh UUID key.** The backend `IdempotencyKeyMiddleware` (CLAUDE.md §9 — dual-layer cache + DB UNIQUE, exists precisely to dedupe retried POSTs) is thereby defeated for the exact scenario it protects: an ambiguous failure where the server committed the order but the client lost the response. UI guard `if (submitting || authBusy || !ctx.method) return;` (funnel.jsx:405) only blocks an in-flight double-click; after `placeRealOrder` throws, `submitting` is reset (funnel.jsx:419) and the user is invited to "Réessaie" (funnel.jsx:420) → a retry sends a NEW key → **second order placed** for the same cart. No client-side order-uuid or stable key exists to dedupe.
- Evidence: `grep idempotency` shows only per-call `uuid()` generation (api.js:446 order, api.js:501 redeem); no stored/stable key anywhere.

---

## P2 — ux-orders-error-shows-wrong-empty-state
**Order history: an API failure renders the "you have no orders yet" empty state (misleading).**

orders.jsx:41-44:
```
api.history(50)
  .then(rows => { if (alive) setOrders((rows||[]).map(mapOrderRow)); })
  .catch(e => { if (alive) { setOrders([]); setHistErr((e&&e.message)||'Chargement impossible.'); } });
```
On error it sets `orders=[]` AND `histErr`. In render, `loading` is false and `filtered.length===0` → the component shows the empty state "Aucune commande pour l'instant — passe ta première commande !" (orders.jsx:96) directly under the error banner (orders.jsx:84-88). A returning customer whose history simply failed to load is told they have no orders and prompted to place their first — the true cause (fetch failure) is contradicted by the state. Error banner is visible (not fully silent), but the coexisting empty state is a wrong-state bug. Fix: track an error flag distinct from "loaded empty" so the empty CTA is suppressed when `histErr` is set.

## P2 — ux-no-cart-route-order-persistence-reload-deadend
**No persistence of cart, route, or order context → any reload during/after the funnel drops the user to home with no recovery.**

index.html:91,100,101 — `route`, `ctx`, `cart` are all plain `useState` (initial `'home'` / defaults / `[]`). `grep -rn cart *.jsx | grep -i localstorage` → **none** (cart is never persisted; only auth token/phone (api.js:100-105), notif prefs (loyalty-v2.jsx:65), and isDev use storage). Consequences:
- Reload while building a cart → cart emptied, back to `home`, no recovery.
- Reload on `confirm`/`track` (funnel.jsx ConfirmationPage/TrackingPage) → `ctx.orderId`, `orderTotal`, pickup QR and `orderDbId` are lost → the just-placed order's ticket/tracking is **irretrievable from the UI** (the tracking poll needs `ctx.orderDbId`, funnel.jsx:706, which is gone). An authed user can still find the order under history, but a guest ticket/QR reference is gone.
No URL routing (SPA in-memory), so browser back/refresh cannot restore any funnel state.

---

## P3 — ux-empty-cart-drawer-no-cta
**Empty-cart drawer has copy but no actionable CTA.**
flows.jsx:64-69 — empty state = emoji 🛒 + "Ton panier est vide" + non-interactive text "Faim ? Va voir le menu." There is no button to navigate to the menu; the user must manually close the drawer and find the menu. Other empty states (orders.jsx:97 "Voir le menu", loyalty screens.jsx:665 "Créer mon compte") do include a CTA — the cart drawer is the outlier.

## P3 — ux-otp-no-empty-validation
**OTP verify submits with no client-side check that a code was entered.**
funnel.jsx:433-438 `verifyOtp` calls `api.guestVerify(phone, authOtp.trim())` with no guard for empty `authOtp` (contrast `sendOtp` funnel.jsx:426-427 which validates `phone.length < 6`). Empty submit → wasted backend round-trip → error. Non-blocking (backend rejects, error shows on this path since authStep stays 'otp'), but a required-field check is missing.

## P3 — ux-wizard-composed-item-no-qty
**Composed (wizard) items can only be added one at a time — no qty stepper.**
wizard-v2.jsx recap "Ajouter au panier" (line 591) calls `onAdd({ item, state, total, ... })` with no qty; App defaults qty=1 (index.html:180). Only `DirectAddView` (simple items) has a qty stepper (wizard-v2.jsx:668-674). To order 2 identical custom sandwiches the user must run the wizard twice or use the cart-row stepper (flows.jsx:47). Minor UX gap, not a defect.

---

## Verified OK (no finding)
- Empty states: menu 0-results (screens.jsx:460-465, icon+title+desc+Réinitialiser), no-orders (orders.jsx:93-98 +CTA), loyalty not-logged-in (screens.jsx:656-668 +CTA), orders not-logged-in (orders.jsx:46-56 +CTA), loyalty history empty (screens.jsx:846-854).
- Error surfaces with role=alert/aria-live: promo (funnel.jsx:295), delivery (funnel.jsx:245), payment apiError (funnel.jsx:554), redeem (screens.jsx:803), history (screens.jsx:837), orders (orders.jsx:84). api.js `req` (api.js:132-141) maps network/HTTP errors to FR messages.
- Loading states: orders "Chargement…" (orders.jsx:89-92), loyalty history ⏳ (screens.jsx:829), redeem "En cours…" (screens.jsx:801), delivery '…' (funnel.jsx:243), promo '…' (funnel.jsx:291), payment submitting (funnel.jsx:603-606).
- Wizard mandatory enforcement: linear progression gated by `canAdvance` (wizard-v2.jsx:359-372) — required radios need a value, required multi need `min`; recap only reachable after passing all prior steps. api.js `resolveLine` also tops-up required attrs to min_select (api.js:373-382) as backend-422 defense. qty stepper in DirectAddView clamps min 1 (wizard-v2.jsx:671).
- Double-click (same in-flight): guarded by `submitting`/`authBusy` flags + disabled button (funnel.jsx:405,603). Only the *retry-after-ambiguous-failure* case is unprotected (see P1 idempotency).

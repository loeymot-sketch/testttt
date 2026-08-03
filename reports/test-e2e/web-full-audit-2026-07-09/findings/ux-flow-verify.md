# ux-flow verification — Le Cayenne web standalone (/Users/1millnonstop/Downloads/web)
Date 2026-07-09. Verifier reproduced each finding via Read/Grep/curl. No browser MCP used.

## 1. ux-otp-order-silent-failure — CONFIRMED P1
funnel.jsx verifyOtp (433-446):
- L438 `await api.guestVerify(...)`
- L439 `setAuthStep('none'); setAuthBusy(false);`  ← gate unmounts here
- L440 `setSubmitting(true);`
- L441 `await placeRealOrder();`  ← if this throws →
- L444 `setAuthErr(...)` — but authErr renders ONLY inside `{authStep !== 'none' && (...)}` (L561) at L591-595.
Since authStep is already 'none', the authErr block is unmounted; `apiError` (L554, always-mounted) is NOT set on this path.
Net: guest OTP path, order fails post-auth → button returns to "Confirmer la commande" (submitting reset L443) with NO error, NO order.
placeRealOrder can throw: resolveLine `throw {kind:'resolve'}` at api.js:274; backend 422; network.
curl POST :8766/api/frontend/order (no token) → HTTP 401 (endpoint real, placement can fail).

## 2. ux-idempotency-key-regenerated-duplicate-order — CONFIRMED, downgraded P1→P2
api.js:446 `idempotencyKey: o.idempotencyKey || ('web'+uuid()...)`. Callers never pass a stable key:
funnel.jsx:378 orderOpts `{ cart, paymentMethod, couponId, loyaltyCode }` (no idempotencyKey); funnel.jsx:390 `api.placeOrder(orderOpts)`.
grep idempotency → only per-call uuid() (api.js:446 order, api.js:501 redeem 'web-lr'+uuid). No stored/stable key anywhere.
submit catch (funnel.jsx:418-420) resets submitting + sets apiError "…Réessaie…" → retry mints fresh UUID → backend IdempotencyKeyMiddleware (CLAUDE.md §9) can't dedupe → duplicate order on retry-after-ambiguous-failure.
Downgrade rationale: real defect but bounded V1 impact — narrow failure window, counter payment (no online double-charge), staff catch duplicate tickets, single local box.

## 3. ux-orders-error-shows-wrong-empty-state — CONFIRMED P2
orders.jsx:43 `.catch(e => { setOrders([]); setHistErr(...) })` sets BOTH.
Render: histErr banner L84-88 AND (loading false + filtered.length===0) empty CTA L93-98 "Aucune commande pour l'instant — passe ta première commande !" (L96) render together. Returning customer w/ failed fetch told they have no orders.

## 4. ux-no-cart-route-order-persistence — CONFIRMED P2
index.html:91 route useState('home'); :100 ctx useState(defaults); :101 cart useState([]).
grep localStorage in *.jsx → only loyalty-v2.jsx notif prefs (63-76); api.js token/phone (100-105). NO cart/route/order persistence.
Tracking poll needs ctx.orderDbId (funnel.jsx:706) → lost on reload → post-order QR/ticket/tracking irretrievable; reload mid-funnel → home + empty cart.

## 5. ux-empty-cart-drawer-no-cta — CONFIRMED P3
flows.jsx:64-69 empty state = 🛒 + 'Ton panier est vide' + non-interactive 'Faim ? Va voir le menu.' No button (contrast orders.jsx:97 CTA).

## 6. ux-otp-no-empty-validation — CONFIRMED P3
funnel.jsx:433-438 verifyOtp calls api.guestVerify(phone, authOtp.trim()) with no empty-code guard; sendOtp validates phone.length<6 (L427). Empty code → wasted backend call → generic authErr.

## 7. ux-wizard-composed-item-no-qty — CONFIRMED P3
wizard-v2.jsx:591 recap onAdd({item,state,total,summary,optionCount}) — no qty; composed `state` has no qty field (L300 `qty:1` is a priceFor() param, not cart qty). index.html:180 `qty = state && state.qty ? state.qty : 1` → composed always 1. Only DirectAddView passes state:{qty} (wizard-v2.jsx:679) with stepper (668-674). grep qty wizard-v2.jsx → stepper only in DirectAddView.

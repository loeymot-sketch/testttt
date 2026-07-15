# SECURITY audit — Le Cayenne web standalone (2026-07-09)

Target: /Users/1millnonstop/Downloads/web  · LIVE :8096 → API :8766 · Stripe OFF (meta feature-online-card=0) · Loyalty REAL.
Method: Bash/Read/Grep/curl only. Every finding reproduced.

## POSITIVE CONFIRMATIONS (no finding)
- AUTH GATING SOLID — all authed endpoints 401 WITHOUT token (curl, X-API-Key present):
  - GET  /api/profile                     → 401 {"message":"Unauthenticated."}
  - GET  /api/frontend/loyalty/history    → 401
  - GET  /api/frontend/order              → 401
  - POST /api/frontend/loyalty/redeem     → 401
  - POST /api/frontend/loyalty/qr         → 401
- NO QR signing secret in client. loyaltyQr() (api.js:520) POSTs to backend which mints the 'lqr.' token; vendor/qrcode.js only draws SVG from a string. No HMAC key client-side.
- NO PII/token/password to console — 0 `console.*` statements in app code (grep count = 0).
- NO sk_live/sk_test/pk_ anywhere. Card PAN/exp/cvc captured in React state (funnel.jsx:351) but NEVER transmitted (placeRealOrder body = paymentMethod 1|4 only, api.js:428-446). No PCI transmission.
- All 6 `target="_blank"` (all in legal/*.html) carry rel="noopener noreferrer" — no reverse tabnabbing.
- React escapes item names + image URLs; only innerHTML sink is the benign QR SVG (below).
- OTP not leaked: POST /api/auth/guest-signup/otp → 200 {"message":"Veuillez vérifier votre téléphone…"} (no code in body). Dev flag (index.html:64-73) only toggles demo hints; no OTP bypass.
- CDN React/ReactDOM/Babel carry SRI `integrity=` + `crossorigin` (index.html:49-51).
- vercel.json sets X-Content-Type-Options=nosniff, X-Frame-Options=SAMEORIGIN, Referrer-Policy.

## FINDINGS

### P2 — Hardcoded app-wide API key committed to git (client fallback)
api.js:21  `apiKey: metaContent('api-key', 'b6d68vy2-m7g5-20r0-5275-h103w73453q120')`
- Committed: `git log` → commit 68c03e4 touches api.js; `git grep b6d68vy2` → api.js:21.
- Server REQUIRES it: `curl /api/frontend/loyalty/config` WITHOUT X-API-Key → HTTP 400 "Clé API invalide". WITH key → 200.
- By nature public (ships in every browser). Does NOT unlock PII — all sensitive endpoints still enforce Bearer (401 above). Risk = unrotatable shared credential, enables trivial scripted access to the public API surface, zero defense value. Rotate = redeploy all clients.

### P2 — Online-card OFF is a client meta flag; server lock not in this codebase
api.js:26 `onlineCardEnabled: metaContent('feature-online-card','0')==='1'`
funnel.jsx:370 `const onlineCard = !!(api.config.onlineCardEnabled)`
funnel.jsx:377 `const pm = (onlineCard && ctx.method==='card') ? 4 : 1`
- OFF by default (index.html:15). When OFF, card method absent from DOM, payment_method always 1 (counter). GOOD.
- The only enforcement visible from the web is the client flag. The claimed final lock (config/payment.php) lives in the separate backend codebase — not verifiable here. Mitigating fact: the web has NO real online-charge path (no Stripe.js, no PaymentIntent); flipping the flag ON only labels orders payment_method=4 and shows a MOCK card form whose PAN/CVC go nowhere (never transmitted). So: no PCI leak, but ON in prod = "card"-labeled orders with no actual payment collected (business/integrity risk). Not a data leak / not P1.

### P2 — Mixed-content: prod deploy risk (http://127.0.0.1 for API + images)
index.html:11 `<meta name="api-base-url" content="http://127.0.0.1:8766">`
index.html:17 `<meta name="menu-image-base" content="http://127.0.0.1:8766/images/menu/">`
- On an https production host, browsers BLOCK these http:// fetch/img → app breaks (all API calls + menu photos fail). Deploy-readiness blocker. Must be swapped to https backend URL at cutover.

### P3 — Auth token in localStorage, long TTL, XSS-exposed
api.js:100-103 `TOKEN_KEY='lecayenne.authToken'` via `localStorage.getItem/setItem`
- Readable by any JS on the page. Token TTL ~30 days (api.js:6 "token Sanctum kiosk:order (30 j)"). Standard SPA tradeoff; no active XSS-with-live-data found, so residual. Note only innerHTML sink is the benign QR SVG.

### P3 — dangerouslySetInnerHTML (only sink) fed by qrcode-lib SVG from server token
components.jsx:83 `dangerouslySetInnerHTML={{ __html: svgHtml }}`
- svgHtml = `window.qrcode(0,'M').addData(qr.token).createSvgTag(...)` (components.jsx:64-72). qr.token is the server-signed 'lqr.' string; the lib renders it as QR modules (rects/paths), NOT reflected as HTML text → not an injection vector. Benign; documented as the sole sink to keep tracked.

### P3 — No Content-Security-Policy / HSTS
index.html + vercel.json — grep CSP = 0.
- vercel.json has nosniff/X-Frame-Options/Referrer-Policy but no CSP and no Strict-Transport-Security. Given a dangerouslySetInnerHTML + external CDN scripts + Babel-in-browser, CSP is defense-in-depth worth adding at deploy.

### P3 — Third-party PII: delivery address geocoded via OpenStreetMap
api.js:66-69 `fetch('https://nominatim.openstreetmap.org/search?...q='+address)`
- User's typed delivery address (PII) sent to OSM third party (https). Privacy-policy disclosure recommended. Low impact.

# Security dimension — VERIFY pass (2026-07-09)
Target: /Users/1millnonstop/Downloads/web (React+Babel static, LIVE :8096 → API :8766)

## 1. hardcoded-api-key-in-git — DOWNGRADED P2→P3
- api.js:21 `apiKey: metaContent('api-key','b6d68vy2-m7g5-20r0-5275-h103w73453q120')`
- `git grep b6d68vy2` -> api.js:21 (tracked); git log api.js -> commit 68c03e4
- Server requires it: `curl :8766/api/frontend/loyalty/config` no key -> 400 "Clé API invalide."; with key -> 200 (reproduced).
- DOWNGRADE: client-shipped app-wide key is public-by-design (every browser holds it) — not a secret. It gates only public config read; all sensitive endpoints are Bearer(kiosk:order)-gated. Real but low-impact posture item, not a P2 secret leak.

## 2. online-card-client-flag-only — DOWNGRADED P2→P3
- api.js:26 onlineCardEnabled = meta('feature-online-card','0')==='1'; index.html:15 = "0" (OFF).
- funnel.jsx:370 onlineCard gate; :377 pm=(onlineCard&&method==='card')?4:1; :455 card method pushed to DOM ONLY if onlineCard.
- placeOrder body (api.js:428-438) sends payment_method ONLY — card.num/exp/cvc (funnel.jsx:498-514) NEVER transmitted (grep of api.js: no card.num/cvc).
- DOWNGRADE: OFF by default, no Stripe.js/PaymentIntent (no charge path), requires deploy-time meta flip. PAN form when ON collects data that goes nowhere. Config/defense note, not active vuln.

## 3. mixed-content-http-endpoints — DOWNGRADED P2→P3
- index.html:11 api-base-url=http://127.0.0.1:8766; :17 menu-image-base=http://127.0.0.1:8766/images/menu/
- api.js:20 reads api-base-url for every fetch; data/menu.js:51-54 reads menu-image-base -> ASSET_BASE for <img> (menu.js:125+ image fields).
- DOWNGRADE: current LOCAL envelope is http↔http on localhost (no mixed content now). Breaks only at https cutover; one-line meta fix. Deploy-readiness note per V1 LOCAL mandate, not live defect.

## 4. token-localstorage-long-ttl — CONFIRMED P3
- api.js:100 TOKEN_KEY='lecayenne.authToken'; :102-103 getToken/setToken via localStorage; api.js:6 comment "token Sanctum kiosk:order (30 j)". XSS-readable. Already BRAIN V1.0.1 roadmap (8h->1h).

## 5. innerhtml-qr-svg-sink — CONFIRMED P3 (benign)
- components.jsx:83 dangerouslySetInnerHTML={{__html:svgHtml}} (sole hit across .jsx).
- svgHtml built components.jsx:64-72 from window.qrcode(0,'M').addData(qr.token).createSvgTag(). Input = server-signed 'lqr.' token encoded as QR modules (rect/path), not reflected as HTML text -> not an injection vector. Keep tracked.

## 6. no-csp-hsts — CONFIRMED P3
- vercel.json:8-15 sets nosniff, X-Frame SAMEORIGIN, Referrer-Policy — no CSP, no HSTS. grep -ic content-security-policy/strict-transport-security in index.html+vercel.json = 0. Defense-in-depth gap.

## 7. osm-geocode-pii-thirdparty — CONFIRMED P3
- api.js:66-69 geocode() fetch('https://nominatim.openstreetmap.org/search?...q='+address). Delivery address (PII) sent to OSM from browser. Privacy-disclosure item.

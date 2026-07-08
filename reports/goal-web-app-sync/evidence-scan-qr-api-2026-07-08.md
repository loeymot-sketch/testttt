# EVIDENCE — Fidélité QR signé : mint → scan borne → anti-replay (API réelle :8766, 2026-07-08)
Exécuté par l'orchestrateur en direct (curl), zéro modification caisse/borne.

| # | Étape | Requête | Résultat |
|---|---|---|---|
| 1 | Enregistrement client par téléphone `0698009438` | POST /api/auth/guest-signup/{otp,verify} (code quelconque — V1 local) | token Sanctum `6576\|…` (kiosk:order, 30 j) |
| 2 | Mint QR signé (client) | POST /api/frontend/loyalty/qr (Bearer client) | status true · ttl 300 s · loyalty_code `D83528CE` (minté à la volée) · token `lqr.eyJ2IjoxLCJjdXN0IjoxNjUsImNvZGUiOiJE…` |
| 3 | Scan borne (token machine kiosk réelle) | POST /api/frontend/loyalty/scan {method:'qr', raw_data:lqr.…} | **ok:true** · display_name 'Guest' |
| 4 | REPLAY du même token | idem | **ok:false · error_code `qr_replay`** ✓ (nonce consommé) |
| 5 | Token falsifié (dernier char muté) | idem | **ok:false · error_code `qr_invalid_signature`** ✓ (HMAC constant-time) |

Conclusion : le pipeline téléphone→enregistrement DB→QR signé→scan→identification est **fonctionnel et durci** côté backend. Reste (W6) : earn e2e (commande + PREPARED → +points), soldes synchrones web/app, et UI web/mobile (WF-2).
Câblage physique scanner→UI borne = G4 owner-gate (borne frozen) — endpoint prêt.

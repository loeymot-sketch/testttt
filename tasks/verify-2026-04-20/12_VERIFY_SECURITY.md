# VERIFY-12 — Sécurité (XSS, CSRF, CORS, rate-limit, Sanctum, secrets)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_SECURITY_2026-04-19.md`  **Priorité :** P0  **Mode :** AUDIT-ONLY

## 1. Contexte
Audit sécurité produit en revue — vérifier exhaustivité des protections, absence de fuite secrets dans repo, cohérence Sanctum (kiosk, POS, frontend), throttle approprié sur routes critiques (notamment `kiosk-menu` ajouté récemment).

## 2. Sources OBLIGATOIRES
- `app/Http/Middleware/*`
- `config/cors.php`, `config/sanctum.php`, `app/Providers/AuthServiceProvider.php`
- `routes/api.php`, `routes/web.php`
- Tests : `REPORT_SEC_*`, `tests/Feature/Security*`
- `.env.example`, `.env` (vérif **présence** uniquement, pas lecture secrets)
- Audit : `AUDIT_POS_110_SECURITY_2026-04-19.md`, `AUDIT_SECURITY_LOGIC_2026-03-31.md`
- Vue : sanitisation v-html (recherche `v-html`)

## 3. Hypothèses à challenger
- H1 : Une route admin POS sans throttle.
- H2 : v-html sur input non sanitisé.
- H3 : CORS allow-all origins en prod.
- H4 : Sanctum cookie non SameSite/Secure en prod.
- H5 : Secrets en clair dans config public ou JS bundle.
- H6 : Throttle `kiosk-menu` (nouvelle route) trop laxiste/serré.
- H7 : Pas de signature des webhooks (Pusher, Stripe si présent).

## 4. Plan multi-agent
1. **Explore A** : middlewares + config + routes (back).
2. **Explore B** : front (Vue templates v-html, axios CSRF, localStorage tokens).
3. **GeneralPurpose** : produit checklist OWASP-like + matrice route × throttle × auth × permission.

## 5. Vérifications obligatoires
- [ ] V1 : CORS limité aux origines de prod.
- [ ] V2 : Sanctum config SameSite=Lax/Strict + Secure.
- [ ] V3 : Throttles : `pos`, `kiosk-menu`, `pricing`, `coupon` configurés et raisonnables.
- [ ] V4 : Aucun `v-html` non sanitisé.
- [ ] V5 : Aucun token / secret commit dans repo (grep large).
- [ ] V6 : Headers de sécurité (X-Frame-Options, CSP minimal) configurés.
- [ ] V7 : Routes auth: validation OTP / rate limit login.
- [ ] V8 : Webhooks signés (HMAC).

## 6. Critères d'acceptation
- ALL_GREEN si V1–V8 OK (avec preuves).
- WARN si V6 partiel.
- FAIL si V1, V2, V4, V5 cassables.

## 7. Livrables
- `reports/review/VERIFY_12_SECURITY_2026-04-20.md`

## 8. Suite
- FAIL CORS → `P11_CORS_TIGHTEN`.
- FAIL v-html → `P11_XSS_SANITIZE`.
- WARN headers → `P12_SECURITY_HEADERS`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/12_VERIFY_SECURITY.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles (A back+config, B front) + 1 generalPurpose checklist OWASP + matrice route×throttle×auth×permission.
0 code modifié, pas de lecture .env (juste vérifier présence).
Livrable: reports/review/VERIFY_12_SECURITY_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```

# VERIFY-11 — KDS / Order Status Screen / Cash drawer (axes 8-9)

**Date :** 2026-04-20  **Origine :** `AUDIT_POS_110_KDS_OSS_DRAWER_2026-04-19.md`  **Priorité :** P1  **Mode :** AUDIT-ONLY

## 1. Contexte
Surface chef (KDS) et OSS (Order Status Screen) doivent rester cohérentes en temps réel. Le tiroir caisse (drawer) doit s'ouvrir/fermer dans des conditions précises (encaissement cash, refund, RETURNED).

## 2. Sources OBLIGATOIRES
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `resources/js/store/modules/kitchenDisplaySystemOrder.js`
- `resources/js/components/admin/orderStatusScreen/**`
- Drawer : recherche `cashDrawer`, `drawer`, `escpos`, `printerService`
- Tests : `tests/Playwright/04-kds-status-*` (les tests Playwright avec failure récents)
- Test failure : `test-results/04-kds-status-KDS-—-interf-65642-direction-vers-surface-chef-chromium*`
- Audit : `AUDIT_POS_110_KDS_OSS_DRAWER_2026-04-19.md`

## 3. Hypothèses à challenger
- H1 : Le test Playwright KDS échoue à cause d'un changement de URL / sélecteur (pas un bug code).
- H2 : Drawer s'ouvre sur des transitions non payées.
- H3 : OSS affiche un état stale > 5s sur grosse charge.
- H4 : Pas de fallback en mode dégradé (Pusher down) → écran figé.

## 4. Plan multi-agent
1. **Explore A** : back KDS + OSS + drawer service.
2. **Explore B** : front + tests Playwright + résultats failure.
3. **GeneralPurpose** : reconstruit la cause probable du fail Playwright + checklist de robustesse.

## 5. Vérifications obligatoires
- [ ] V1 : Trace Playwright (`trace.zip`) analysée.
- [ ] V2 : Drawer ouverture conditionnée à `payment_method=cash` ET `payment_status=paid`.
- [ ] V3 : OSS auto-refresh ≤ 5s (Pusher + polling fallback).
- [ ] V4 : KDS gère le 409 propagé de P4 sans casser la liste.
- [ ] V5 : i18n KDS + OSS complet (FR/EN).
- [ ] V6 : Test Playwright KDS up-to-date avec routes actuelles.

## 6. Critères d'acceptation
- ALL_GREEN si V1–V6 OK.
- WARN si V3 polling fallback absent.
- FAIL si drawer ouvre sur paiement non confirmé ou test KDS non corrigé.

## 7. Livrables
- `reports/review/VERIFY_11_KDS_OSS_DRAWER_2026-04-20.md`

## 8. Suite
- FAIL drawer → `P11_DRAWER_CONDITIONS_TIGHTEN`.
- FAIL test Playwright → `P12_PLAYWRIGHT_KDS_FIX`.

---

### PROMPT À COLLER
```
Tu es orchestrateur AUDIT-ONLY.
Lis tasks/verify-2026-04-20/11_VERIFY_KDS_OSS_DRAWER.md, applique §4-§7.

OBLIGATIONS: 2 explore parallèles + 1 generalPurpose synthèse, analyse explicite du trace.zip Playwright. 0 code modifié.
Livrable: reports/review/VERIFY_11_KDS_OSS_DRAWER_2026-04-20.md
Plan 5 lignes. Conclusion "GLOBAL: ..." + cycles P.
```

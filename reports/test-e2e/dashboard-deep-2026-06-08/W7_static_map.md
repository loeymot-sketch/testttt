# Wave 7 — Static Code-Map (Réglages/Settings — 31 sub-pages) — LIVE branch
Mount `/admin/settings`→SettingsComponent→redirect company. All children `permissionUrl:"settings"`; API `admin/setting/*` (auth:sanctum + block_kiosk_token_admin + throttle:admin-mutation + apiKey).

## Sub-pages (31, route⇄component confirmed)
company, site, branches(list/show), mail, order-setup, kiosk-setup, loyalty-setup, otp, notification, social-media, cookies, analytics(list/show), theme, time-slots, sliders(list/show), currencies(list), item-categories(list→studio), item-attributes(list), taxes(list), roles(list/show), languages(list/show), sms-gateway, payment-gateway, payment-terminals(own prefix admin/payment-terminals:954), license, notification-alert, kiosk-machines(list).

## 🔴 EXTERNAL never-fire IN settings = 0
Every save = DB-only persist (mail/sms/otp/payment-gateway `update()` = pure `->save()`, NO Mail::/Http::/send/curl). No `/test`,`/test-send`,`/verify`,`/rotate`,`/send-otp` endpoint under setting/*. SMS/payment provider "tabs" = selectors not send-test (safe).
- Only ADJACENT never-fire: `POST admin/printer/{printer}/test-print` (PrinterController@testPrint, api.php:950) = physical ESC/POS. NEVER click.

## FISCAL-SENSITIVE saves (clone-only; not a defect to edit, but don't blind-save vs operating)
- **tax** store/update — `tax_rate` read live by `PricingService.php:241-258` (NF525-relevant).
- **currency** — pricing display/context.
- **loyalty-setup** — points earn/redeem conversion.
- **payment-gateway** — provider secrets (no outbound on save).
- (Role/permission save = RBAC mutation, high blast radius.)

## FINDINGS
- [P2] Orphan Pages settings route — `Page/PageListComponent.vue`+`PageShowComponent.vue` exist + full `setting/page` CRUD API (api.php:415-421), but NO route registered in `settingRoutes.js` → unreachable from SPA. (lazy-import present, no path/name child.)
- [P3] Orphan `MenuComponent.vue` in settings dir + setting/menu-section API unused by settingRoutes (catalog-owned). Harmless.
- [P3] Multi-provider SMS/payment tabs (Twilio/Stripe/SenangPay…) visible — V1 FR single-provider; vision-OK, not a defect.

Counts: P0=0 P1=0 P2=1 P3=2. EXTERNAL in-cluster=0. Fiscal-sensitive saves=4. RBAC: 31/31 gated.
QA verdict: settings cluster SAFE to render + save (DB-only reversible) on :8766 clone; never click test-print.

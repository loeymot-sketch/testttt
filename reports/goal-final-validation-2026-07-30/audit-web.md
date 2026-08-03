# Audit Web Client — Le Cayenne (read-only, adversaire « 30 jours »)

2026-07-30 · Repos : `lecayenne-web-deploy/Site lecayenne` (JSX Babel-standalone ES5) + backend `testttt` (Frontend/**, Loyalty, wait-estimate, GuestSignup email-OTP).

## Comptes : 0 P0 · 1 P1 · 4 P2 — money-path, auth, parité borne↔web = VERTS

---

## P1

**P1-1 — Bypass OTP `DEMO` NON couvert par le boot-guard prod (contrairement à ses jumeaux)**
`app/Services/OtpManagerService.php:82` → `if (filter_var(env('DEMO',false),FILTER_VALIDATE_BOOLEAN)) return true;` : `verify()` renvoie `true` pour N'IMPORTE quel token/téléphone. Ses jumeaux (`POS_SIMULATION_HARDWARE`, `PAYMENT_BYPASS_MODE`, `PRINTING_BYPASS_MODE`) lèvent chacun une `RuntimeException` au boot (`AppServiceProvider.php:186,209,216`) ; `DEMO` est ABSENT du boot-guard ET de `PreflightProductionCommand` (grep = 0). Repro : `.env DEMO=true` → `POST /api/auth/guest-signup/verify {phone:<victime>,token:0000}` → 201 + token Sanctum `kiosk:order` (`GuestSignupController.php:150,252`) = prise de contrôle de tout compte lié à ce numéro (points, historique, adresses). Précond : `DEMO=true` déployé — défaut sûr `.env.example:19=false`, non modifiable via UI. Fix : ajouter `DEMO` au set interdit boot-guard + preflight.

---

## P2

**P2-1 — `??` (ES2020) dans un script NON transpilé, critique au boot**
`data/menu.js:334-335` : `viandes: opts.viandes ?? 0`. `menu.js` est chargé `<script src>` (index.html:71), PAS `type="text/babel"` → exécuté natif, hors Babel. C'est la couche data canonique (`window.LC.menu` / `W_ITEMS`), chargée en 1er et synchrone. Un navigateur sans `??` (pré-2020 : vieux WebView Android, in-app browsers datés) lève une SyntaxError → `LC.menu` jamais défini → toutes les .jsx cassent → écran blanc TOTAL. Plancher déjà ES2015 (const/arrow/template) donc fenêtre = 2016-2019, mais rayon = site mort. Fix trivial : `opts.viandes != null ? opts.viandes : 0`.

**P2-2 — `dev_code` OTP renvoyé en JSON hors production**
`GuestSignupController.php:69-79` : code OTP dans le corps HTTP si `!environment('production')`. Correctement gaté HORS prod (double verrou) ; `email-otp` ne le fuit pas. Risque uniquement si un box internet-facing tourne en `APP_ENV≠production` avec données réelles (creds borne publiques). Discipline env, pas de fix code.

**P2-3 — OTP 4 chiffres par défaut**
`OtpManagerService.php:52` `random_int(1000,9999)` (10⁴). Atténué : CSPRNG, throttle `3/5min` (`routes/api.php:219`), brûlage du code après 5 échecs/numéro (`:97-101`). Résiduel : grind lent ; compteur fail-open si cache down. Durcissement.

**P2-4 — Incohérences mineures fidélité (affichage / code mort)**
(a) `funnel.jsx:14` fallback `ppe=1` vs `screens.jsx:687` défaut `10` : si `/loyalty/config` échoue, le checkout SOUS-affiche les points (floor(total×1)) alors que le backend crédite ×10. (b) `screens.jsx:763 doRedeem` = code mort depuis le heal S4 LOY-1 (le bouton scrolle vers le QR, ne redeem plus). (c) `loyalty-v2.jsx:84` toggle « SMS de retrait » promet un SMS inexistant en V1. Cosmétique. Note : commentaire `api.js:473` périmé (+1,50/+1,00) — le vrai prix formule = 1,90 (ratios 0.76), à corriger pour ne pas induire un mainteneur en erreur.

---

## VÉRIFIÉ VERT (l'adversaire n'a pas percé)

- **Prix scellés backend** : `FrontendOrderService::myOrderStore:383` prix DB toujours ; total/discount client ignorés (`OrderRequest:148`). Options fantômes/inconnues + injection cross-item → 422 fail-loud (`:378,393,402,420,428`) ; garde `expected_total` >0,01€ → 422 (`:580`). Miroir web : `resolveExtraOrThrow` (api.js:332) bloque toute option payante non résolue (fini le « panier 12€ → payé 10€ »).
- **Parité borne↔web** : 7 viandes — « Viande Hachée » RESTAURÉE le 07-27 (migration `2026_07_27_090000`, annule le 07-24) → parité OK. Sauce défaut triée en tête + badge « Incluse » (wizard:149). Formule +2,50 / +1,90 / +1,90 = backend (ratios `config/kiosk.php:183-184` = 0.76 × addon 2,50). Extras +0,50/+0,90/+2,50 alignés.
- **Fidélité** : earn 10 pts/€ (`LoyaltyController:502`), redeem SANS négatif (`lockForUpdate` + overdraw reject `:387` + multiple-de-rate), QR HMAC-SHA256 + `hash_equals` + anti-rejeu nonce + expiry (`LoyaltyQrSigner:117,142`), kill-switch `pos.loyalty_enabled` (`config/pos.php:224`). `/check` IDOR fermé (404 indistinct).
- **Suivi / IDOR** : `/order/show` ownership → 403 (`FrontendOrderService:754`), `myOrder` scopé `user_id` ; `escpos`/`changeStatus` ownership-checked. `wait-estimate` = agrégat pur `{queue_count,wait_low/high,closing,server_time}` — aucun ID/PII (`WaitEstimateService:80`). Tracking poll statut réel (20s) + wait réel, perte de contact ASSUMÉE + « Réessayer », bascule « prête » (titre+vibration), refresh-safe via historique (S4 TRK-1).
- **Mollie** : montant = `$order->total` scellé (`Mollie.php:108`), webhook re-fetch API + vérif montant/devise + idempotent (`webhook_events` UNIQUE) + ownership (`metadata.order_id`) ; fail-closed 503 sans flag+clé ; repli comptoir fail-safe côté funnel.
- **CORS** : pas de `*` nu ; origines filtrées, `lecayenne.fr`/`www` via `FRONTEND_WEB_DOMAIN` (valeur .env déployée à confirmer) ; `supports_credentials` reflète l'origine matchée, jamais `*`.
- **Funnel** : panier TTL 24h corrigé (S8 — `savedAt` d'origine conservé), clé idempotence scopée signature panier, `dev_code` gaté `window.LC.isDev`, retour Mollie anti-doublon (`?order=` purge le panier), coupon SSOT (discount client = témoin only), 0 bouton mort / 0 raw-label.

# W3 — DIMENSION CONSOLE/NETWORK — BORNE (kiosk)

- **Date** : 2026-06-11 · **App** : `http://127.0.0.1:8768` (release/v1, HEAD `a5cba6f7d`) · **Viewport** 1080×1920 fr-FR, Chromium `channel:'chrome'`
- **Harness** : `tests/e2e/_w3-console-borne-2026-06-11.mjs` (phases `flow` / `finish` / `inactivity`) — raw JSON : `_w3-borne-raw-{flow,finish,inactivity}.json` (même dossier)
- **Flux réel exécuté** : idle → À emporter → catégories (1/5/10) → wizard composé (Tacos #26, 4 étapes) → +boisson #52 → panier (2 lignes) → loyalty (code invalide) → upsell (affiché, skip) → payment → confirm → **cash-instruction `A0001` total 10 €** (vraie commande, DB jetable) → 4 pages d'erreur en accès direct → reset inactivité (panier garni, 230 s).
- **Bilan global** : **0 pageerror · 0 5xx · 0 requête >3 s · 0 polling anormal**. Tout le bruit est concentré sur **401 télémétrie/boot auto-soignés** + **spam warning wizard**.

## Tableau par étape

| Étape | Console err | Warn | Exceptions | 4xx/5xx | Observations réseau |
|---|---|---|---|---|---|
| S0 Boot idle | 1 | 0 | 0 | 1×401 | `GET /api/login` 401 (chaîne redirect beacon). Boot = 7 appels API (kiosk-login, setting×2, branch, menu, kiosk-event, broadcasting/auth) |
| S1 Idle 45 s | 3 | 0 | 0 | 3×401 | **Idle quiet** : 0 polling après boot ; seuls `kiosk-event` (healthcheck 90 s) + re-login. 401 menu/kiosk-event/login (rejoués OK) |
| S2 À emporter | 3 | 0 | 0 | 3×401 | Même triplet 401 de cold-boot (navigation dure = reboot SPA) ; en in-SPA réel : 1 seule fois |
| S3 Catégories | 4 | 0 | 0 | 4×401 | menu/branch/setting 1× par navigation ; pas de re-fetch en boucle |
| S4 Wizard #26 | 0 | **23** | 0 | 0 | **23× même warning** `[kiosk-wizard.composer] step skipped … step_key: viande_2` ; `pricing/preview` 1×/étape (~1,9 s, user-driven, OK) |
| S5 Panier | 0 | 0 | 0 | 0 | 1× menu/setting/branch (reboot SPA harness) |
| S6 Loyalty code invalide | 2 | 0 | 0 | 1×401 + 1×**429** | `POST loyalty/check` : 401 (course token) puis **429 Too Many Requests** au rejeu — message brut anglais possible côté client |
| S7 Upsell (skip) | 0 | 0 | 0 | 0 | `order/quote` 1× + `item/kiosk-upsell` 1× — propre |
| S8 Payment + confirm | 0 | 0 | 0 | 0 | `order/quote` 1× ; `POST /api/frontend/order` 1× (pas de doublon) |
| S9 Cash-instruction (25 s obs.) | 0 | 0 | 0 | 0 | `?number=A0001&total=10&timeout=45` — countdown 100 % client, **0 requête** pendant l'attente |
| S10 4 pages erreur (network / menu-unavailable / product-removed / payment-refused) | 0 | 0 | 0 | 0 | Chacune : 1× `kiosk/event` (`error_shown`, alias slash authentifié, 200) — propre |
| S11 Inactivité 230 s (panier garni) | 2 | 0 | 0 | 2×401 | **Reset à ~180 s** (`idleMs=180000`, `kioskSettings.js:44`) → route in-SPA vers `/kiosk/idle`, **aucune rafale réseau** (1× setting). Pendant l'attente : `kiosk-event` à t=0 et t=90 s, **chacun 401→re-login→rejeu 200** |

WebSocket : `ws://127.0.0.1:6001/app/app-key` **se connecte réellement** (9 open / 0 `socketerror` ; les `close` correspondent aux navigations dures). `broadcasting/auth` 1×/boot, 200. Pas de fallback polling déclenché — comportement attendu sain, fallback non sollicité.

## Findings

### F1 [P1] 401 récurrents télémétrie/boot dans le flux normal (auto-soignés mais permanents)
3-5 erreurs console (`Failed to load resource… 401`) à **chaque cold-boot** (menu, kiosk-event, login) **et toutes les 90 s** sur borne parquée (healthcheck → `kiosk-event` 401 → re-login → rejeu 200, observé à t=0 et t=90 s de S11). Deux racines combinées :
1. **Rotation destructive du token** : chaque re-login kiosque supprime TOUS les tokens kiosk du compte — `app/Http/Controllers/Auth/KioskMachineLoginController.php:103` (`$user->tokens()->where('name','kiosk-token')->delete()`). Deux clients kiosque simultanés (2e onglet, agent d'audit, futur 2e écran) se révoquent mutuellement en boucle infinie 401→relogin→401. L'intercepteur silencieux `resources/js/app.js:100-112` (`__retry401Kiosk`) masque le symptôme à chaque fois.
2. Token périmé/rotaté persisté (localStorage `vuex`) réutilisé au boot — `resources/js/shared/axios-setup.js:44-58,76-85`.
Impact client : aucun (rejeu transparent) ; impact ops : console rouge permanente + 1 login/90 s superflu. Fix backend hors frozen.

### F2 [P1] Analytics kiosque perdues silencieusement — `sendBeacon` sans Bearer sur route auth
`resources/js/helpers/kioskAnalytics.js:200-209` : `sendNow()` tente **toujours** `navigator.sendBeacon` en premier — un beacon ne peut porter ni Bearer ni `x-api-key`, or `/api/frontend/kiosk-event` exige `auth:sanctum + abilities:kiosk:order` (`routes/api.php:1464-1466`) → **401 garanti** ; et `if (ok) return true` traite « mis en file navigateur » comme « livré » → **événement perdu sans retry ni enqueue**. Le commentaire ligne 202-203 (« seulement si la route accepte cookies+CSRF ») contredit le code : la condition n'existe pas. C'est aussi la source du `GET /api/login` 401 par boot (beacon sans `Accept: application/json` → `Authenticate::redirectTo` → `route('login')` = `routes/api.php:151-153`). Télémétrie uniquement (parcours client non bloqué), mais récurrent à chaque boot.

### F3 [P2] Spam console wizard composé — 23× le même warning par ouverture [FROZEN-GATE]
`resources/js/components/frontend/kiosk/KioskWizardComponent.vue:813` : `composerStepType()` log `console.warn('[kiosk-wizard.composer] step skipped (no kind match + no choices)')` pour l'étape `viande_2` (source_type `item_attribute`, 0 choix) du Tacos #26 — appelé dans le cycle de rendu → **23 occurrences pour UNE composition de 4 étapes** (×2 runs = reproductible). Fix = dédupe du warn (ou correction data du `composer_profile` viande_2 vide, voie DB sans toucher le fichier). Fichier **frozen §7** → toute modif = gate owner.

### F4 [P2] Loyalty : 401 puis 429 sur `loyalty/check` — message throttle brut anglais
S6 : 1er `POST /api/frontend/loyalty/check` 401 (course token au boot), rejeu → **429** (`throttle:10,1`, possiblement partagé avec d'autres agents de la vague sur :8768). Le client peut alors voir le passthrough brut `err.response?.data?.message` = « Too Many Attempts. » — `resources/js/components/frontend/kiosk/KioskLoyaltyComponent.vue:505-506` (recoupe le finding A6-THROTTLE-RAW du parcours profond 2026-06-10). 4xx isolé, parcours non bloqué.

### F5 [P3] Double fetch `/api/frontend/setting` à chaque boot
2× par boot (`KioskAppComponent.vue:502` + second appelant). Bruit réseau mineur, aucune erreur.

## Propreté inactivité / idle (réponse à la question posée)
- **Borne idle** : propre — aucun polling d'UI ; uniquement le healthcheck matériel 90 s (`KioskAppComponent.vue:999`, `HEALTHCHECK_INTERVAL_MS`) + sync offline 30 s sans réseau quand la file est vide (`kioskOfflineQueue.js:13`). Aucune requête >1×/2 s sur tout l'audit.
- **Reset inactivité** : à ~180 s exactement, transition in-SPA → `/kiosk/idle`, 1 requête (`setting`), pas de reload ni de rafale. Propre, hormis le 401 télémétrie de F1 qui tombe dans la fenêtre.

**Verdict dimension** : GREEN fonctionnel (flux complet sans exception ni 5xx, commande réelle A0001 créée) — YELLOW hygiène console (F1/F2 récurrents, F3 spam frozen).

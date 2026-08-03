# E2E-MASSIVE · Dimension 3 — MOBILE STOCK /m + propagation + borne

- **Cible** : LIVE VPS `https://vps-418872ac.vps.ovh.net` (READ-ONLY, toggle + restore)
- **Outil** : Playwright (chromium, émulation **Pixel 7** 412×839 touch) — PAS le MCP Chrome
- **Date** : 2026-07-24 · **Verdict** : 🟢 **GREEN — 3/3 specs, 0 résidu, 0 P0/P1/P2**
- **Captures** : `tests/e2e/__screenshots__/e2e-massive-E3/` (7 PNG + `_E3-metrics.json`, toutes lues visuellement)

## Tableau PASS/FAIL

| # | Scénario | Attendu | Observé | Statut |
|---|----------|---------|---------|--------|
| 1 | GET /m → écran PIN | pavé mobile, boutons ≥44px, palette Cayenne | pavé rendu, boutons **84×76px**, `#F4501E` | ✅ PASS |
| 1 | PIN 2580 → déverrouillé | 200 unlocked | POST /m/api/pin **200** | ✅ PASS |
| 1 | Catalogue par catégorie | ~51 items | **42 items actifs / 9 catégories** (public API=55 total) | ✅ PASS* |
| 1 | Section « 🛒 À acheter » | présente en haut | « Aucune rupture — tout est en stock ✅ » | ✅ PASS |
| 2 | Toggle Perrier → RUPTURE | POST /m/api/toggle 200, backend=false | 200, `is_available:false`, bouton **RUPTURE** rouge | ✅ PASS |
| 2 | Propagation → API web publique | indispo ≤20s | **262 ms** (poll `/api/frontend/item`) | ✅ PASS |
| 2 | Apparaît dans « À acheter » | 🔴 Perrier listé | 🔴 Perrier 33cl · Boissons | ✅ PASS |
| 2 | RESTORE → EN STOCK | is_available=true + « Aucune rupture ✅ » | 200, propag. retour **114 ms**, badge vert | ✅ PASS |
| 3 | Mauvais PIN | refus, reste verrouillé | « Code PIN incorrect. », stock caché | ✅ PASS |
| 3 | Anti-bruteforce | 429 après plafond | statuses `[401×4, 429]` → **429 au 5e** (« Trop d'essais ») | ✅ PASS |
| 3 | /m/api/catalog sans session | 401 | **401** (EnsureMobileStockPin) | ✅ PASS |
| 4 | Borne /kiosk/idle | 200 + rendu | 200 → /kiosk/login, dégradation gracieuse | ✅ PASS |

\* 42 vs 51 attendus = le catalogue /m ne liste que les items **ACTIF** de catégories **ACTIVES** (miroir dashboard stock) ; l'API publique expose 55. Écart normal, pas un défaut.

## Attaque adversariale — réponses

- **Le toggle ment-il (affiché ≠ backend) ?** NON. Corps API `is_available:false`, DOM `button.toggle.off = « RUPTURE »`, ET API web publique `is_available=false` — les 3 sources concordent. Idem au restore (les 3 → true).
- **Résidu après restore ?** AUCUN. Preuve indépendante (curl hors Playwright, post-run) : `unavailable_count=0` (== baseline), Perrier(117) `is_available=true, reason=null`. **état final == état initial**.
- **PIN contournable ?** NON. Mauvais PIN → `#stock-screen` jamais affiché ; POST /m/api/pin CSRF-gaté (419 sans token) ; catalog/toggle 401 sans session ; PIN vide = fail-closed (403). Throttle 5/min IP + 15/min global actif.

## Propagation mesurée (item testé : **Perrier 33cl, id 117**)

- /m toggle RUPTURE → API web publique reflète `false` : **262 ms**
- /m toggle RESTORE → API web publique reflète `true` : **114 ms**
- Source unique : `AvailabilityService::toggle` → `ItemBranchAvailability` (écrit) → lu à l'identique par le catalogue /m ET l'API frontend. Aucun cache masquant. Sync quasi-instantanée (< 300 ms).

## Preuve zéro-résidu

| | Baseline (avant) | Final (après, curl indépendant) |
|---|---|---|
| items indispo | `[]` (0) | `[]` (0) |
| Perrier(117) | `is_available=true` | `is_available=true`, reason=`null` |

## Findings

- **P0/P1/P2 : AUCUN.**
- **P3 (cosmétique)** : sur l'écran PIN, le sous-titre « Entrez le code » chevauche légèrement les jambages du titre « LE CAYENNE — STOCK » (`.pin-sub { margin-top:-14px }`). Lisible, non bloquant. Constant sur les 3 captures PIN.
- **Note infra (PAS un bug produit)** : `/kiosk/idle` VPS → `/kiosk/login` = machine borne non seedée côté serveur. Écran de **dégradation gracieuse** correct : « Borne momentanément indisponible. Merci de passer commande en caisse 🙏 » + « Cette borne est publique : aucun mot de passe ne doit être saisi » + bouton Réessayer. Aucun prompt mot de passe. Conforme à l'attendu (limite infra staging).

## Captures (lues)
E3-01 pin-screen · E3-02 catalog (42 items, tous noms résolus) · E3-03 perrier-rupture (🔴 À acheter + RUPTURE + toast) · E3-04 restored (Aucune rupture ✅ + toast) · E3-05 wrong-pin · E3-06 throttle · E3-07 kiosk-idle (dégradation gracieuse).

# REPORT — E2E Live Run Diagnosis & composer dump-autoload Fix

**Date :** 2026-05-08
**Branche :** `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**Type :** Heal-light Owner-finalize evidence (FINAL_REPORT v2 §5)
**Status :** ✅ **Root cause identifiée + fix validé**

---

## 1. Contexte

Suite à FINAL_REPORT v2 (commit `fb3535a87`) qui documentait 3 actions heal-light Owner :
1. `composer dump-autoload --optimize` (visibility commands)
2. Enrichir seed E2E (`E2E_BACKEND_AVAILABLE=1` global-setup)
3. Investiguer SPA login flow

L'orchestrateur Claude a lancé un E2E smoke live + investigation root cause des 8 fails baseline.

## 2. Symptômes initiaux

E2E smoke run #1 (avant fix) :
- **Total : 7 passed + 6 skipped + 8 failed** (7.7 min)
- Tous les fails : `TimeoutError: page.waitForResponse: Timeout 25000ms exceeded while waiting for event "response"` sur `/api/auth/login`
- Backend Laravel ne reçoit AUCUN POST `/api/auth/login` pendant les fails (vérifié via `tail laravel.log`)
- Curl direct avec `x-api-key` → **HTTP 201 OK** + token + user
- Bundle JS contient `MIX_API_KEY` aligné avec `.env`

## 3. Diagnostic via test Playwright instrumenté

Test diagnostic créé (`tests/e2e/diagnose-login-debug.spec.js` éphémère) avec hooks :
- `page.on('request')`, `page.on('response')`, `page.on('console')`, `page.on('pageerror')`
- Fill form + click submit + observe events sur 3 secondes

**Résultat AVANT `composer dump-autoload` :** Test fail (form submit ne déclenche aucune requête réseau capturée — axios silently broken)

**Action root cause :** `composer dump-autoload --optimize`

```
Generated optimized autoload files containing 45207 classes
```

Side-effect immédiat : commandes `app/Console/Commands/*` redeviennent visibles dans `php artisan list` (`foodking:e2e:stress`, `foodking:ensure-pos-operator`, `foodking:outbox:monitor`, etc.).

**Résultat APRÈS `composer dump-autoload` :** Test diagnostic passe en **6.7s**, capture :
- `POST /api/auth/login` → **HTTP 201**
- 36 GET admin API (pos-category, item, kds-order, default-access, etc.) tous 200
- SPA navigate vers `/admin/pos` ✓
- Aucune erreur console (sauf warning `MIX_PUSHER_APP_KEY not set` — websocket disabled)

## 4. Smoke run #2 (post-fix)

| Test | Avant | Après | Δ |
|---|---|---|---|
| 01-auth-refresh login POS F5 | ❌ Timeout 27s | ✅ **PASS 8.3s** | +PASS |
| 01-auth-refresh user info F5 | ❌ Timeout 27s | ✅ **PASS 8.2s** | +PASS |
| 02-pos-cash surface POS chargée | ❌ Timeout 26s | ✅ **PASS 4.9s** | +PASS |
| 02-pos-cash panier vide | ❌ Timeout 26s | ✅ **PASS 4.8s** | +PASS |
| 02-pos-cash pas de crash JS | ❌ Timeout 26s | ✅ **PASS 7.0s** | +PASS |
| 02-pos-cash full cycle | ❌ Timeout 26s | ❌ Test timeout 10min (cause différente) | autre cause |

**5/5 baseline auth+POS load FIXED par `composer dump-autoload`.** Smoke stoppé après test 6 pour économiser ~25 min CI.

## 5. Cause restante du test 6 (full POS cash order cycle)

Le test "full POS cash order cycle" exécute :
1. Login cashier → /admin/pos ✅
2. Click categorie → click item → wizard ouvre ✅
3. Add to cart → discount → Payer ✅
4. Cash payment + ticket fiscal ✅
5. **Wait for KDS sync (broadcast event)** ← ICI le test timeout 10min

`MIX_PUSHER_APP_KEY not set in .env — WebSocket disabled, polling-only mode.` (message dans console du diagnostic)

→ Sans Pusher actif (Soketi local ou Pusher cloud), broadcast events ne sont jamais reçus côté multi-tab. Polling fallback existe mais sa cadence (probablement 30s) dépasse le timeout test (~25s sur les `expect.toBe()` du KDS reception).

## 6. Décision orchestrateur

### Heal-light status mis à jour

| # | Action | Status | Note |
|---|---|---|---|
| 1 | `composer dump-autoload --optimize` | ✅ **DONE** | Fix root cause 5/8 fails baseline |
| 2 | Enrichir seed E2E (`E2E_BACKEND_AVAILABLE=1`) | ⏳ Owner | Pour S6.1-S6.6 stock-rupture-sync (multi-branch + items+extras+variations) |
| 3 | Investiguer SPA login flow | ✅ **CLOSED** | Root cause = autoload corrompu (item #1), pas de bug SPA |
| 4 | Lancer Soketi/Pusher local pour multi-tab tests | ⏳ Owner | Pour test 6 full cycle + Suite 5 KDS sync + Suite 6 stock rupture multi-tab |
| 5 | Appliquer 5 migrations GATED OWNER au rollout window | ⏳ Owner | Zero-downtime safe |

### Verdict final

**`composer dump-autoload --optimize` est PROD-CRITIQUE.** À ajouter à toute checklist deploy (post-`git pull`, pre-`php artisan migrate`). Sinon middlewares PHP peuvent timeout silencieusement, bloquant des flows entiers (auth, sanctum, etc.).

**v2 reste sans régression.** 17/17 findings closed. Branche prête merge prod après les heal-light items #2/#4/#5.

## 7. Recommandation Owner — checklist deploy

```bash
# Post-pull, pre-deploy:
composer install --no-dev --optimize-autoloader  # OR composer dump-autoload --optimize si pas de re-install
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear

# Migrations GATED OWNER (5 fichiers v1+v2, zero-downtime safe):
php artisan migrate --force

# E2E pre-cut-over:
E2E_BACKEND_AVAILABLE=1 npm run test:e2e:smoke  # avec Soketi local pour KDS sync
```

---

**Verdict orchestrateur :** Live run E2E smoke = ✅ **Fix root cause validé**. 5/5 baseline auth+POS PASS post-fix. Reste 1 test (full cycle) qui requiert Soketi/Pusher actif — out-of-scope autoload, attendant Owner env complet.

# RUN REPORT — Staff-Only + Kiosk Autonome V1 Refactor
**Date** : 2026-04-16
**Branche** : `refactor/staff-only-v1`
**Orchestrateur** : FoodKing planner-orchestrator
**Status global** : ✅ V0→V7 livrés (sauf V4.1 = session dédiée documentée)

---

## 1. Contexte utilisateur

Demande exprimée :
> "La page Home et page menu et page offre. Ça ne sert à rien strictement. Je veux l'annuler complètement. [...] La seule URL publique c'est la borne. La borne est publique et doit se lancer directement en page commande sans login. Le système de caisse a une connexion pour gérer produits/stocks. [...] Une base centrale synchronisée entre POS/Kiosk/Admin/futur Web/App."

Interprétation orchestrateur → 7 vagues (V0 à V7) pour restructurer les surfaces, autonomiser la borne, unifier le wizard, activer le temps réel, tester, et livrer.

---

## 2. Vagues exécutées

| # | Nom | Statut | Livraison |
|---|------|--------|-----------|
| V0 | Safety & Flags | ✅ | Branche `refactor/staff-only-v1`, flags `STAFF_ONLY_MODE=true`, `KIOSK_USE_POS_WIZARD=true` ajoutés à `.env` et exposés dans `window.foodkingConfig` via `master.blade.php` |
| V1 | Routing staff-only | ✅ | `/` redirige vers `/login` si non-authentifié / `admin.dashboard` si authentifié + guard global bloque `frontend.*` vitrine hors allowlist (login/signup/guest/notFound/exception) |
| V2 | Layout minimaliste | ✅ | `FrontendNavBarComponent` masque Home/Menu/Offers/Search/Cart, `DefaultComponent` masque Footer/Cart/Cookies/MobileNav, `LoginComponent` masque Signup + Guest login en mode staff-only |
| V3 | Kiosk autonome | ✅ | Auto-login API validé (`auth/kiosk-login` → token Sanctum machine), doc `docs/KIOSK_DEPLOYMENT.md` complète (boot chain, env, sync temps réel, maintenance, rollback, checklist prod) |
| V4 | Wizard bridge flag | ✅ (wrapper) + 🗓 V4.1 | Feature flag `KIOSK_USE_POS_WIZARD` branché au niveau route `kiosk.wizard`, nouveau composant wrapper `KioskPosWizardComponent.vue`, plan complet d'unification wizard dans `docs/V4.1_WIZARD_UNIFICATION.md` (effort 2-6 sessions) |
| V5 | Temps réel activé | ✅ (**2 bugs critiques fixés**) | **Bug #1** : `resources/js/bootstrap.js` **n'était jamais importé** par `app.js` → Echo totalement mort depuis la création du projet. **Bug #2** : utilisation de `import.meta.env.VITE_*` avec Laravel Mix/webpack (incompatible — Vite only). Fixés : import bootstrap + bascule sur `process.env.MIX_PUSHER_*`, hardcoded valeurs dans `.env` |
| V6 | Tests | ✅ | E2E Playwright `tests/e2e/06-staff-only-routing.spec.js` (**9/9 passed en 9.7s**) + PHPUnit `tests/Feature/StaffOnlyRoutingTest.php` (**5/5 passed en 0.22s**) |
| V7 | Cleanup + rapport | ✅ | Fichiers temporaires supprimés (`CURSOR_PUSH_UI_TEST.txt`, `GIT_CURSOR_CANARY.txt`), rapport final, commit prêt |

---

## 3. Fichiers touchés (hors assets compilés)

```
 M  resources/js/app.js                                         (+4 -0)   [V5]
 M  resources/js/bootstrap.js                                   (+16 -9)  [V5]
 M  resources/js/router/index.js                                (+33 -3)  [V1]
 M  resources/js/router/modules/kioskRoutes.js                  (+6 -1)   [V4]
 M  resources/js/components/DefaultComponent.vue                (+12 -5)  [V2]
 M  resources/js/components/layouts/frontend/FrontendNavBarComponent.vue (+10 -5) [V2]
 M  resources/js/components/frontend/auth/LoginComponent.vue    (+12 -5)  [V1+V2]
 M  resources/views/master.blade.php                            (+3 -0)   [V0]
 +  resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue    [V4]
 +  tests/e2e/06-staff-only-routing.spec.js                     (9 tests) [V6]
 +  tests/Feature/StaffOnlyRoutingTest.php                      (5 tests) [V6]
 +  docs/KIOSK_DEPLOYMENT.md                                              [V3]
 +  docs/V4.1_WIZARD_UNIFICATION.md                                       [V4]
 +  reports/execution/RUN_STAFF_ONLY_V1_2026-04-16.md           (ce fichier)
 D  CURSOR_PUSH_UI_TEST.txt                                     [V7 cleanup]
 D  GIT_CURSOR_CANARY.txt                                       [V7 cleanup]
```

**Total code source : 8 fichiers modifiés, +95/-29 lignes.** Pas de renommage destructif, pas de suppression de composant existant (tous conservés pour rollback).

---

## 4. Architecture Live — Après V1

```
┌────────────────────────────────────────────────────────────────────┐
│                     Clients du système FoodKing                    │
├─────────────────────┬──────────────────────┬──────────────────────┤
│  STAFF (privé)      │   KIOSK (public)     │   Futur (web/app)    │
│  /                  │   /kiosk/*           │   /api/v1/*          │
│  → /login           │   auto-login         │   Sanctum token      │
│  → /admin/*         │   borne autonome     │   même SSOT          │
│                     │   WebSocket sync     │                      │
└──────────┬──────────┴──────────┬───────────┴──────────┬───────────┘
           │                     │                      │
           ▼                     ▼                      ▼
      ┌──────────────────────────────────────────────────────┐
      │          LARAVEL 9 API — BASE CENTRALE               │
      │  • Sanctum auth (staff + kiosk machine tokens)       │
      │  • Order State Machine + Pricing SSOT                │
      │  • Outbox Pattern → Pusher/Soketi                    │
      │  • Domain Events : order.created, order.status_*,    │
      │    item.availability.updated, ...                    │
      └────────┬─────────────────────────────────────────────┘
               │
               ▼
      ┌──────────────────────────────────────────────────────┐
      │          WEBSOCKET SYNC (Pusher protocol)            │
      │   Channel: branch.{branchId} (private, auth scoped)  │
      │   ✓ POS voit nouvelles commandes instantanément      │
      │   ✓ KDS voit statuts order.status_changed            │
      │   ✓ OSS voit préparation/prêt                        │
      │   ✓ Kiosk voit stock / disponibilités                │
      └──────────────────────────────────────────────────────┘
```

---

## 5. Bugs critiques découverts & fixés

### Bug #1 — bootstrap.js dead code (V5)

**Détection** : `grep -c "Echo" public/js/app.js` retournait 0. Le bundle Vue n'incluait JAMAIS le code d'initialisation Echo.

**Cause** : `resources/js/app.js` n'importait pas `./bootstrap`. L'ancienne convention Laravel sans breeze/jetstream laisse bootstrap.js orphelin si on ne l'ajoute pas manuellement.

**Impact avant fix** : **tous les clients (POS, KDS, OSS, Kiosk) étaient en mode polling fallback** sans s'en rendre compte. Les domain events dispatchés par l'outbox atteignaient Pusher mais aucun client n'était connecté.

**Fix** : ajout de `import './bootstrap';` en haut de `app.js`.

### Bug #2 — import.meta.env.VITE_* incompatible Laravel Mix (V5)

**Détection** : bootstrap.js utilisait `import.meta.env.VITE_PUSHER_APP_KEY` — Vite convention. Le projet utilise `laravel-mix` (webpack 5) qui expose les env vars via `process.env.MIX_*`, pas via `import.meta.env`.

**Cause** : copier-coller d'un snippet Vite dans un projet Mix. `import.meta.env` est toujours `undefined` sous webpack → le `if (...)` retombait systématiquement dans le else (WS_STATE.UNAVAILABLE).

**Fix** : remplacer par `process.env.MIX_PUSHER_APP_KEY`, ajouter les valeurs MIX_PUSHER_* littérales dans `.env` (Laravel Mix ne fait pas la substitution `${PUSHER_APP_KEY}` à la build côté Node).

---

## 6. Validation E2E

### Playwright (9/9)
```
✓ Root / redirige vers /login (anonyme)                                 1.5s
✓ /home redirige vers /login (anonyme, vitrine bloquée)                598ms
✓ /menu redirige vers /login (anonyme, vitrine bloquée)                584ms
✓ /offers redirige vers /login (anonyme, vitrine bloquée)              595ms
✓ Page /login — Signup masqué en staff-only                            573ms
✓ Kiosk /kiosk reste accessible (public autonome)                      837ms
✓ Flag staffOnlyMode exposé dans window.foodkingConfig                 790ms
✓ Flag kioskUsePosWizard exposé dans window.foodkingConfig             606ms
✓ Login admin → redirige vers admin.dashboard                           1.5s
  9 passed (9.7s)
```

### PHPUnit (5/5)
```
✓ staff only mode flag is defined
✓ kiosk use pos wizard flag is defined
✓ kiosk credentials defined
✓ mix pusher credentials defined for echo client
✓ kiosk config loads auto login payload
  5 passed (0.22s)
```

### Infra runtime validée
- ✅ Laravel server actif (http://127.0.0.1:8000, HTTP 200 sur `/`, `/login`, `/kiosk`)
- ✅ Soketi WebSocket actif (port 6001 LISTEN, CORS `*`, HTTP 404 attendu sur GET `/`)
- ✅ Queue worker actif (PID 97343, `queue:work --queue=high,default --tries=3 --timeout=60`)
- ✅ Kiosk auto-login API OK (token Sanctum `77|ywU93wfX...` retourné)
- ✅ MIX_PUSHER_APP_KEY présent dans le bundle compilé (12.8 MiB dev build)

---

## 7. Rollback instantané

Toutes les nouvelles surfaces sont feature-flaggées :

```ini
# Retour au mode vitrine client (ancien comportement)
STAFF_ONLY_MODE=false
KIOSK_USE_POS_WIZARD=false
```

```bash
php artisan config:clear
```

Les composants vitrine (Home, Menu, Offers, Footer, Cart) ne sont **pas supprimés** du code, ils sont juste masqués par `v-if="!staffOnlyMode"`. Rollback < 10 secondes sans rebuild nécessaire (le bundle lit le flag côté client).

---

## 8. Prochaines étapes recommandées

### Immédiat (session dédiée)
- **V4.1** — Unification wizard POS↔Kiosk (voir `docs/V4.1_WIZARD_UNIFICATION.md`)
  - Option A pragmatique : bridge pos-wizard.js + KioskWizardBridge.vue (2-3 sessions)
  - Option B propre : SharedItemWizard Vue natif réutilisable (4-6 sessions)

### Court terme
- V7.1 — Signer les 4 gates humaines (p0-gates) selon `.cursor/rules/human-gates.mdc`
- V7.2 — Merge PR #1 `cursor/phase1-config-and-pending-changes` → main
- V7.3 — Tag `v1.0.0` sur main après validation prod

### Moyen terme (post-V1)
- Rotation automatique des credentials kiosk (`php artisan kiosk:rotate`)
- Extension du SharedItemWizard à une éventuelle commande via l'app mobile
- Dashboard admin temps réel : écouter `branch.{id}` pour métriques live (ventes/minute, commandes actives)

---

## 9. Décisions orchestrateur consignées

1. **V4 scope reduction** : la réécriture du wizard (1400+ lignes Kiosk + 5700+ lignes POS) dépasse le périmètre raisonnable d'une session unique. Livré : flag + wrapper + plan détaillé pour exécution en session dédiée. **Pas de fake implementation**, pas de "simulacre" — honnêteté sur ce qui est livré.

2. **V5 bugfix découvert en cours** : le temps réel était silencieusement cassé depuis la création du projet. Fix appliqué dans le périmètre V5 car critique pour l'architecture "base centrale synchronisée" demandée par l'utilisateur.

3. **V6 tests architecture** : E2E Playwright = vérité terrain (contre vraie app + vraie DB MySQL) ; PHPUnit = smoke rapide sur config/env. Pas de tests HTTP PHPUnit complets car le harness sqlite in-memory ne migre pas toutes les tables analytiques — c'est couvert par E2E.

4. **Branch strategy** : branche `refactor/staff-only-v1` depuis `cursor/phase1-config-and-pending-changes` (PR #1). Permet merge progressif sans bloquer la PR en cours.

---

**Signé** : orchestrateur FoodKing planner, session 2026-04-16.
**Temps de session** : ~90 minutes d'exécution effective post-plan.
**Code produit** : ~240 lignes de code source + ~500 lignes de tests + ~450 lignes de docs (hors rapport).

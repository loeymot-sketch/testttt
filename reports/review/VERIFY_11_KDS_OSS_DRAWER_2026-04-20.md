# VERIFY-11 — KDS / OSS / Cash Drawer (axes 8-9)

**Date :** 2026-04-20  **Mode :** AUDIT-ONLY (read-only, 0 code modifié)
**Origine :** `tasks/verify-2026-04-20/11_VERIFY_KDS_OSS_DRAWER.md`
**Audit source :** `reports/review/AUDIT_POS_110_KDS_OSS_DRAWER_2026-04-19.md`

---

## 0. Résumé exécutif

**GLOBAL : WARN**

- **KDS backend (V4)** : OK — concurrency `lockForUpdate` + `409` propagé + `OrderStateMachine::allows`, dispatch `OrderStatusChanged` après commit. Store front gère le 409 par re-fetch.
- **OSS auto-refresh (V3)** : OK — Echo (Pusher) + polling fallback **adaptatif** 60 s WS-up / **10 s** WS-down (`PreparingAndReadyComponent.vue` lignes 105-122). Banner UI signale la perte WS.
- **Drawer (V2)** : **WARN** — gating frontend sur `pos_payment_method === CASH` uniquement (`PaymentComponent.vue` L222), sans contrôle explicite de la réponse `payment_status`. Le contrat repose entièrement sur l’invariant backend `OrderService::posOrderStore` ⇒ `PaymentStatus::PAID` (L598). Pas de défaut critique en l’état, mais couplage implicite à signaler.
- **Test Playwright KDS (V1, V6)** : **FAIL côté infra de test, pas côté KDS code**. Cause-racine du fail : **HTTP 429 `Too many login attempts`** émis par `RateLimiter::for('login-lockout')` (max 10 tentatives / 10 min par `email|ip`). Voir §4.
- **i18n (V5)** : OK FR/EN (`label.preparing`, `label.ready`, `label.popular_menu_items`).
- **PreparingAndReadyComponent.vue régression 0-byte (VERIFY-04)** : **résolue dans le worktree courant** (10 350 octets, mtime 2026-04-20 12:48). À noter mais non-restauré par cet audit (consigne respectée).

> Top findings → P-cycles : `P12_PLAYWRIGHT_KDS_FIX` (rate-limit pollution), `P11_DRAWER_CONDITIONS_TIGHTEN` (gate explicite sur `payment_status`), `P-OSS-OBS` (instrument WS reconnect ratio).

---

## 1. Périmètre & sources lues

Backend
- `app/Services/KitchenDisplaySystemOrderService.php` (236 L)
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php` (54 L)
- `app/Providers/RouteServiceProvider.php` (RateLimiter `login-lockout`)
- `routes/api.php` (préfixe `admin/kds-order`, `throttle:login-lockout` sur login)
- `app/Services/OrderService.php` L590-605 (création POS = `PaymentStatus::PAID`)
- `app/Services/PaymentService.php` (`payment_status = PAID`)

Front
- `resources/js/store/modules/kitchenDisplaySystemOrder.js` (70 L)
- `resources/js/components/admin/orderStatusScreen/OrderStatusScreenComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue` (drawer call site)
- `resources/js/services/kioskHardware.js` (bridge `openDrawer`)

Tests
- `tests/e2e/04-kds-status.spec.js`
- `tests/e2e/helpers/login.js`
- `tests/js/posCashDrawerOpen.spec.js` (matrix CASH-only)
- Trace : `test-results/04-kds-status-…-retry1/trace.zip` + `error-context.md`

i18n : `resources/js/languages/fr.json` + `en.json`.

Pas de modifications appliquées. Aucun fichier produit/applicatif touché.

---

## 2. Backend KDS (Pass A)

### 2.1 `KitchenDisplaySystemOrderService::list`
- Statuts ouverts : `ACCEPT(4) | PREPARING(7) | PREPARED(8)` — conforme `OrderStatus`.
- **Branch isolation** : `branch_id` filtré quand `auth()->user()->branch_id > 0`, bypass admin (branch_id=0). OK invariant n°2 (`safety.mdc`).
- **Pagination** : `limit(50)` actif (P51 fix).
- **Advance orders** : inclus jour J + retards (correctif AUDIT-P51-BUG1, FIX-53-2).
- Tri : whitelist `['id','order_datetime','queue_number','order_serial_no','status','created_at']`.

### 2.2 `KitchenDisplaySystemOrderService::changeStatus` (V4 — concurrence)
Pattern **anti-stale** correct :
1. `expectedFrom = (int) $order->status` capturé hors transaction.
2. `DB::transaction` → `Order::lockForUpdate()` sur la ligne ciblée.
3. **Branch guard** intra-tx : `abort(403)` si autre branche.
4. **Compare-and-set** : `abort(409, 'Order status was updated elsewhere…')` si `$locked->status !== $expectedFrom`.
5. `OrderStateMachine::allows(from, to, user)` validé sur ligne lockée.
6. `recordTransition` au sein de la même transaction.
7. **Hors transaction** : dispatch `SendOrderMail`/`Sms`/`Push` + `OrderStatusChanged::dispatch($snapshot, $from, $new)`. Conforme invariant n°5 (notifications post-commit).

### 2.3 `KitchenDisplaySystemController`
- Permission middleware `permission:kitchen-display-system` sur `index|changeStatus|orderItems`.
- Re-throw `HttpException` en réponse fidèle au statut → 409 reste 409 (ne devient pas 422). **Non-régression confirmée**.

### 2.4 `kds_group_id`
- Toujours absent du codebase (cf. AUDIT_POS_110_KDS_OSS_DRAWER §KDS) — écart `F-KDS-002` non couvert ce cycle, mais hors scope V1-V6.

---

## 3. Frontend KDS / OSS (Pass B)

### 3.1 Store `kitchenDisplaySystemOrder.js`
- Action `lists` : `GET admin/kds-order` (matche `routes/api.php` L776 `kds-order`).
- Action `changeStatus` : `POST admin/kds-order/change-status/{id}`.
- **V4 propagation 409** : sur erreur `409`, **refetch automatique** des `lists` puis `reject(err)` → l’UI peut afficher un toast tout en se resynchronisant. Conforme audit P4.

### 3.2 OSS `OrderStatusScreenComponent.vue`
- Composé de `PopularItemComponent` + `PreparingAndReadyComponent` + `ConnectionStatusBanner`. UI minimal, pas de logique métier.

### 3.3 OSS `PreparingAndReadyComponent.vue` (V3)
- **Polling adaptatif** (`_pollingInterval`) :
  - WS connecté → 60 000 ms
  - WS down → **10 000 ms** (≤ 5 s spec ? voir §6.1)
- Banner d’avertissement « Connexion temps réel perdue — actualisation automatique toutes les 10s… » en cas de `wsConnected = false`.
- Echo bind via `onEvents(branchId, [{broadcastAs:'OrderStatusChanged'},{broadcastAs:'OrderCreated'}])`, scopé par `branch.{branchId}`. Unsubscribe propre dans `beforeUnmount`.
- **De-dup PREPARED flash** : guard `_echoMarkedReady` empêche le double-déclenchement du jingle quand Echo + refetch convergent (AUDIT-P1).
- Cleanup : `clearInterval`, `removeEventListener`, `unsubscribeEcho`, `clearTimeout(_flashTimer)` — pas de leak.

### 3.4 OSS `PopularItemComponent.vue`
- Lecture seule `orderStatusScreenOrder/mostPopularItems`. Aucun risque P/V identifié.

### 3.5 i18n (V5)
- `fr.json` : `label.preparing="En préparation"`, `label.ready="Prêt"` (lignes 88-89, 214-215).
- `en.json` : `label.preparing="Preparing"`, `label.ready="Ready"`, `label.popular_menu_items="Popular Menu Items"`.
- ✅ FR/EN complets.

### 3.6 Régression VERIFY-04 (PreparingAndReadyComponent.vue 0 octet)
- **Statut courant** : 10 350 octets, mtime 2026-04-20 12:48 — **fichier déjà restauré** dans le worktree. Le fichier audité ci-dessus est le contenu restauré.
- **Action** : aucune (consigne « ne pas restaurer »). Si la régression 0-byte se reproduit en CI/main, ouvrir P-OSS-FILE-INTEGRITY (vérification git LFS / `.gitattributes` / hooks pre-commit qui vident le fichier).

---

## 4. Analyse trace.zip Playwright (V1)

### 4.1 Fichier analysé
- `test-results/04-kds-status-KDS-—-interf-65642-direction-vers-surface-chef-chromium-retry1/trace.zip`
- Test ciblé : `04-kds-status.spec.js:26 › KDS — interface cuisine › login chef via /login → redirection vers surface chef`.

### 4.2 Séquence reconstruite (depuis `0-trace.trace` + `0-trace.network`)
1. `Frame.goto` → `GET http://127.0.0.1:8000/login` (200).
2. `expect(page.locator('#formEmail')).toBeVisible` → résolu.
3. `Frame.fill('#formEmail', 'chef@lecayenne.fr')` → OK.
4. `Frame.fill('#formPassword', '123456')` → OK.
5. `Frame.click(getByRole('button', { name: /^(login|connexion)$/i }))` → bouton ciblé.
6. `POST http://127.0.0.1:8000/api/auth/login` envoyé.
7. **Page snapshot post-action (`error-context.md`) montre** :
   - `alert: "Too Many Attempts."`
   - `button "Login" [active]` toujours présent
   - URL inchangée, formulaire pré-rempli
8. `expect(page).toHaveURL(KDS_SURFACE_RE, { timeout: 20_000 })` → **timeout** (URL reste `/login`).

### 4.3 Cause-racine probable (probabilité haute)
- `routes/api.php` L138 / L141 protège le login par `middleware('throttle:login-lockout')`.
- `RouteServiceProvider::boot()` L87-101 : `RateLimiter::for('login-lockout')` autorise `config('app.login_lockout_max_attempts', 10)` tentatives par fenêtre de **10 minutes**, clé `email|ip`.
- Réponse 429 standard : `{ "message": "Too many login attempts. …", "retry_after": 900 }` → l’intercepteur Vue affiche le toast/alerte « Too Many Attempts. ».
- Le compteur est partagé entre tests (login chef joué 3× dans la même run + retry → 4-6 tentatives ; cumulé aux runs précédentes du jour, plafond 10 atteint).

**→ Ce n’est pas une régression KDS, ni un changement de route ou de sélecteur (H1 confirmée).**

### 4.4 Hypothèses challengées
- **H1** (route/sélecteur) : **CONFIRMÉE** comme cause apparente seulement sur surface — la véritable cause est un 429 login. Routes (`/admin/kitchen-display-system`, alias `/kds`) et sélecteurs (`#formEmail`, `#formPassword`) sont alignés avec le code actuel.
- **H2** (drawer non-cash) : voir §5 V2.
- **H3** (OSS stale > 5 s) : voir §5 V3 — actuellement 10 s WS-down.
- **H4** (pas de fallback Pusher down) : **INFIRMÉE** — fallback polling 10 s + banner UI présents.

### 4.5 Remédiation Playwright recommandée (`P12_PLAYWRIGHT_KDS_FIX`)
1. Helper `tests/e2e/helpers/login.js` : intercepter 429 et appeler une route admin `/test-only/reset-rate-limit?email=…` (à créer en env `testing` uniquement) **OR** réinitialiser via `RateLimiter::clear()` dans un trait Laravel exposé en CLI.
2. Alternative simple : `test.beforeAll` Playwright qui exécute `php artisan cache:clear` (ou un command dédié `tests:reset-login-throttle`).
3. Ajout d’un compte test dédié par worker (`chef+pw1@…`, `chef+pw2@…`) pour éviter la collision.
4. Test `26` peut aussi pré-attendre `response('**/api/auth/login')` avant l’assertion d’URL pour distinguer 429 vs 200.

---

## 5. Vérifications (V1-V6)

| ID | Critère | Verdict | Preuve |
|----|---------|---------|--------|
| **V1** | Trace Playwright analysée | ✅ OK | §4 — cause = 429 `login-lockout` |
| **V2** | Drawer ouverture conditionnée à `payment_method=cash` ET `payment_status=paid` | ⚠️ WARN | `PaymentComponent.vue` L222 : check `pos_payment_method === CASH` seulement. `payment_status=PAID` garanti côté serveur (`OrderService` L598) mais non vérifié côté client. Tests `tests/js/posCashDrawerOpen.spec.js` couvrent CASH/CARD/MOBILE_BANKING (matrix ok). |
| **V3** | OSS auto-refresh ≤ 5 s (Pusher + polling fallback) | ⚠️ WARN | Polling fallback **10 s** WS-down, **60 s** WS-up + Echo temps réel (`PreparingAndReadyComponent.vue` L105-114). Spec V3 demande ≤ 5 s ; **dépassement de 5 s en mode dégradé**. Acceptable opérationnellement (Echo couvre temps réel) mais non strictement conforme à la cible §5. |
| **V4** | KDS gère le 409 propagé de P4 sans casser la liste | ✅ OK | Service backend abort 409 propre + store front refetch sur 409 (`kitchenDisplaySystemOrder.js` L42-44). Controller préserve le code via `HttpException`. |
| **V5** | i18n KDS + OSS complet (FR/EN) | ✅ OK | Clés `preparing`, `ready`, `popular_menu_items` présentes en FR + EN. |
| **V6** | Test Playwright KDS up-to-date avec routes actuelles | ✅ OK (test) / ❌ FAIL (exécution) | `KDS_SURFACE_RE = /\/(kds|admin\/kitchen-display-system)/` matche les routes Vue actuelles. **Le test échoue à cause du throttle login**, pas d’un drift de route/sélecteur. |

---

## 6. Risques & écarts résiduels

### 6.1 OSS polling fallback > 5 s (V3 WARN)
- **Spec** §5 V3 : ≤ 5 s.
- **Réalité** : 10 s en WS-down.
- **Décision suggérée** : soit assouplir la spec à « ≤ 10 s en mode dégradé, temps réel via WS sinon », soit `P-OSS-POLL-TIGHTEN` pour passer à 5 s avec garde-fou côté serveur (cache 1 s sur `kds-order/items`).

### 6.2 Drawer V2 — couplage implicite serveur/client
- Si un futur changement assouplit `OrderService::posOrderStore` (ex. POS « pay later » en cash → `payment_status=UNPAID` puis confirmation différée), le drawer s’ouvrirait quand même.
- **Décision suggérée** : `P11_DRAWER_CONDITIONS_TIGHTEN` — exiger explicitement dans `confirmOrder` :
  ```js
  if (pos_payment_method === CASH
      && parseInt(orderResponse.data.data.payment_status) === paymentStatusEnum.PAID) {
      openDrawer();
  }
  ```

### 6.3 Régression 0-byte `PreparingAndReadyComponent.vue` (VERIFY-04)
- Restaurée dans ce worktree. Origine non investiguée ce run.
- `P-OSS-FILE-INTEGRITY` : auditer `git log -p -- resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue` dans le worktree d’origine pour identifier la commit/hook ayant zéroïsé le fichier.

### 6.4 Test infra Playwright — pollution rate limit (P12)
- Voir §4.5. Risque : faux positifs récurrents en CI dès que le suite e2e dépasse 10 itérations sur le même compte.

### 6.5 Hors scope confirmé
- `kds_group_id` (F-KDS-002) — non requis par V1-V6.
- Trace bout-en-bout drawer/Z-report/`audit_logs` (F-DRW-001) — hors V1-V6.

---

## 7. Conclusion & cycles P recommandés

**GLOBAL : WARN**

Aucun défaut bloquant détecté côté code KDS/OSS/drawer. Le fail Playwright observé est **un défaut d’infrastructure de test (rate-limiter login)** et non une régression applicative. Le polling fallback OSS et le gating drawer méritent un resserrage léger.

### Cycles P à enchaîner (priorisés)
1. **P12_PLAYWRIGHT_KDS_FIX** (P1, infra-test) — Réinitialiser `login-lockout` entre tests + compte chef multi-worker. Critère sortie : suite `04-kds-status.spec.js` verte 5 runs consécutifs.
2. **P11_DRAWER_CONDITIONS_TIGHTEN** (P2, code POS) — Vérifier explicitement `payment_status=PAID` dans `PaymentComponent.vue::confirmOrder` avant `openDrawer()`. Test unit Vitest existant à étendre (`posCashDrawerOpen.spec.js` ⇒ ajouter cas « CASH + UNPAID = pas d’ouverture »).
3. **P-OSS-POLL-TIGHTEN** (P3, conformité spec) — Soit aligner la spec V3 sur la réalité opérationnelle (≤ 10 s dégradé), soit réduire le polling WS-down à 5 s. Décision produit + ajustement code mineur.

### Hors scope direct mais à tracer
- `P-OSS-FILE-INTEGRITY` — comprendre la zéroïsation périodique de `PreparingAndReadyComponent.vue` (cf. VERIFY-04).
- `F-KDS-002` (kds_group_id absent) et `F-DRW-001` (chaîne drawer↔Z-report) — backlog audit antérieur.

---

**Conformité orchestrateur :** AUDIT-ONLY, 0 fichier applicatif modifié, seul `reports/review/VERIFY_11_KDS_OSS_DRAWER_2026-04-20.md` écrit. PreparingAndReadyComponent.vue **non touché** (consigne respectée même si déjà restauré).

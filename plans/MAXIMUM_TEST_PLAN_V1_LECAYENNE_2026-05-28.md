# MAXIMUM TEST PLAN — V1 LOCAL Le Cayenne FUNCTIONAL VALIDATION
## Plan d'exécution manuel — owner-driven trial-test avec visual + technique
2026-05-28 — HEAD `e7ae1c8ea`

---

## §0 — Mission + Mode

**Objectif** : valider TOUS les chemins fonctionnels V1 LOCAL Le Cayenne par tests réels (browser + DB + audit chain) avec **capture visuelle systématique + vérification technique** à chaque étape.

**Exécuteur** : toi (manuel). Je peux dispatcher Playwright MCP en parallèle pour bulk-capture si tu veux, mais ton mode = trial-test direct.

**Convergence** : ✅ uniquement si toutes les 6 tiers passent → 0 P0 / 0 P1 visible OU technique + NF525 chain CHAIN OK avant ET après + frozen-zone diff = 0.

**Budget estimé** : 8-12h cumulé selon profondeur (peut être étalé sur plusieurs sessions).

---

## §1 — VUE FONCTIONNELLE DU SYSTÈME (mind-map)

```
                            ┌────────────────────────────────┐
                            │  CLIENT (kiosk borne ou web)   │
                            └─────────────┬──────────────────┘
                                          │
                                  KIOSK Borne (Vue 3)
                                          │
                                          ▼
┌─────────────┐                  ┌──────────────────┐                  ┌──────────────┐
│  POS Caisse │ ◄──────CART──────│  COMMANDE +      │──────CART──────► │ Admin Dash   │
│  (Vanilla)  │                  │  PAIEMENT        │                  │  (Vue admin) │
└──────┬──────┘                  └──────┬───────────┘                  └──────┬───────┘
       │                                │                                     │
       │                                ▼                                     │
       │                       ┌────────────────────┐                         │
       │                       │  BACKEND CORE       │                        │
       │                       │  Laravel + MySQL    │                        │
       │                       │  ┌────────────────┐ │                        │
       │                       │  │ PricingService │ │ ◄── 100% backend SSOT  │
       │                       │  │   (FROZEN)     │ │                        │
       │                       │  └────────────────┘ │                        │
       │                       │  ┌────────────────┐ │                        │
       │                       │  │ FiscalSequence │ │ ◄── NF525 chain HMAC   │
       │                       │  │   (FROZEN)     │ │                        │
       │                       │  └────────────────┘ │                        │
       │                       │  ┌────────────────┐ │                        │
       │                       │  │ AuditLogService│ │ ◄── 6y retention       │
       │                       │  │   (FROZEN)     │ │                        │
       │                       │  └────────────────┘ │                        │
       │                       │  ┌────────────────┐ │                        │
       │                       │  │ BranchScope    │ │ ◄── multi-tenant       │
       │                       │  │   (FROZEN)     │ │                        │
       │                       │  └────────────────┘ │                        │
       │                       │  ┌────────────────┐ │                        │
       │                       │  │ Idempotency    │ │ ◄── X-Idempotency-Key  │
       │                       │  │   (FROZEN)     │ │                        │
       │                       │  └────────────────┘ │                        │
       │                       └─────┬────────┬──────┘                        │
       │                             │        │                               │
       │              SYNC OUTBOX + PUSHER + POLLING 5s                       │
       │                             │        │                               │
       │                             ▼        ▼                               │
       │                  ┌──────────────────┐    ┌───────────────────┐       │
       │                  │  KDS Cuisine     │    │ OSS Écran client  │       │
       │                  │  (Vue admin)     │    │ (Vue admin)       │       │
       │                  └────────┬─────────┘    └─────────┬─────────┘       │
       │                           │                        │                 │
       │                           ▼                        ▼                 │
       │                  bump → PREPARING                  ▲                 │
       │                           │                        │                 │
       │                           ▼                        │                 │
       │                  bump → PREPARED ──────READY──────┘                  │
       │                           │                                          │
       │                           ▼                                          │
       │                  ┌──────────────────┐                                │
       │                  │  LIVREUR (si     │                                │
       │                  │  delivery)       │                                │
       │                  │  cash session    │                                │
       │                  └──────────────────┘                                │
       │                                                                      │
       └──────────────── STOCK CASCADE ────────────────────────────────────┘
                       Admin toggle 86 → Kiosk badge + POS désactivé <1s
```

**Couche 0 — Foundation invariants (NEVER break)** :
- `PricingService` : prix calculés backend, frontend envoie item_id + quantity + option_ids uniquement
- `FiscalSequence` : monotonic per branch, gap-free, triple-defense (Cache::lock + lockForUpdate + UNIQUE)
- `AuditLog` chain HMAC SHA-256 : `current_hash = HMAC(prev_hash || payload)` — DELETE forbidden (DB trigger SIGNAL SQLSTATE 45000)
- `BranchScope` global appliqué sur 20 modèles
- `Idempotency` : (branch_id, user_id, sha1(key)) — dual-layer middleware + DB UNIQUE

**Couche 1 — Surfaces métier** :
- POS Caisse (`/admin/pos`) — Vanilla JS wizard FROZEN
- Kiosk Borne (`/kiosk/idle`) — Vue 3 wizard FROZEN
- KDS Cuisine (`/kds`) — Vue admin (NOT frozen)
- OSS Écran client (`/admin/order-status-screen` + alias `/order-status-screen`)
- Admin (`/admin/*`) — dashboard + items + stock + Z reports + settings
- Livreur (`/admin/delivery-boy-cash-sessions` + DeliveryBoy models)

**Intersections critiques** :
1. Kiosk → KDS (paiement → broadcast → bump → ready)
2. POS → KDS (cash → broadcast → bump → ready)
3. KDS → OSS (ready → display column flip)
4. Stock cascade (admin toggle → POS+Kiosk badge live)
5. Settings cascade (currency change → all surfaces flip)
6. Branch deactivate → tokens revoke + UI refresh
7. Refund (POS → mirror order → audit_log + chain extension)

---

## §2 — STRATÉGIE 6-TIER (ordre obligatoire)

| Tier | Nom | But | Budget | Outil principal |
|---|---|---|---|---|
| **T1** | Foundation invariants | NF525 + DB + Auth healthy avant tout | 30 min | tinker + curl + chain verify |
| **T2** | Surface page-by-page | Visuel + technique chaque page chaque état | 3-4h | Browser + screenshot |
| **T3** | Cross-surface chain | Sync intégrité numerique end-to-end | 1-2h | Multi-tab browser |
| **T4** | Adversarial abuse | Briser chaque invariant délibérément | 2-3h | curl + sql + browser hostile |
| **T5** | Personas | Workflows réels (rush/late-night/etc.) | 1-2h | Browser scenario |
| **T6** | Final convergence | Smoke + chain check + frozen-zone diff | 30 min | scripts |

**Tu peux interleave T2 et T3.** Mais T1 DOIT être PASS avant tout autre tier. T4 DOIT être après T2.

---

## §3 — TIER 1 — FOUNDATION (30 min)

### T1.1 — DB state baseline
```bash
php artisan tinker --execute="
echo 'Items: '.\App\Models\Item::count().PHP_EOL;
echo 'Categories: '.\App\Models\Category::count().PHP_EOL;
echo 'Branches: '.\App\Models\Branch::count().PHP_EOL;
echo 'Users: '.\App\Models\User::count().PHP_EOL;
echo 'WizardProfiles: '.\App\Models\ItemWizardProfile::count().PHP_EOL;
echo 'PaymentTerminals: '.\App\Models\PaymentTerminal::count().PHP_EOL;
echo 'AuditLog count: '.\App\Models\AuditLog::count().PHP_EOL;
echo 'Last hash: '.substr(\App\Models\AuditLog::latest('id')->first()?->current_hash,0,16).PHP_EOL;
"
```
**Attendu** : Items=45 / Branches=1 / WizardProfiles=10 / PaymentTerminals≥1 / AuditLog count monotonic / hash 16-char hex.
**ROUGE** : si Items≠45 ou Branches=0 → re-run `menu:reset --force` + `WizardCayenneAndBolsCorrectionsSeeder`.

### T1.2 — NF525 chain integrity
```bash
php artisan fiscal:verify-chain --all
```
**Attendu** : `+ branch=1 CHAIN OK` + `SWEEP COMPLETE — CHAIN OK on every active branch (1 total)`.
**Capture** : sauve la sortie dans `/tmp/foodking-t1-chain-baseline.txt`.
**ROUGE** : si CHAIN BROKEN → STOP IMMÉDIAT, n'exécute pas T2+. Escalade.

### T1.3 — Frozen-zone baseline
```bash
git diff --stat -- public/js/pos-wizard.js public/css/pos-wizard.css \
  resources/views/admin-pos-v4.blade.php \
  resources/js/components/frontend/kiosk/KioskWizardComponent.vue \
  resources/js/components/frontend/kiosk/KioskAppComponent.vue \
  resources/js/components/frontend/kiosk/KioskUpsellComponent.vue \
  app/Services/Fiscal/FiscalSequenceService.php \
  app/Services/Fiscal/ZReportService.php \
  app/Services/Fiscal/AuditLogService.php \
  app/Models/Scopes/BranchScope.php \
  app/Http/Middleware/IdempotencyKeyMiddleware.php \
  app/Services/Pricing/PricingService.php \
  app/Domain/Order/OrderStateMachine.php
```
**Attendu** : sortie vide (0 lignes modifiées).
**ROUGE** : toute ligne = STOP, revert immédiat.

### T1.4 — Auth healthy + dev server up
```bash
curl -s -o /dev/null -w "Root: %{http_code}\n" http://127.0.0.1:8000/
curl -s -o /dev/null -w "Login: %{http_code}\n" http://127.0.0.1:8000/login
curl -s -o /dev/null -w "Kiosk: %{http_code}\n" http://127.0.0.1:8000/kiosk/idle
curl -s -o /dev/null -w "Admin login API: %{http_code}\n" -X POST http://127.0.0.1:8000/api/auth/login \
  -H "x-api-key: $(grep MIX_API_KEY .env | cut -d= -f2)" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@lecayenne.fr","password":"123456"}'
```
**Attendu** : tous 200 (sauf Admin login API qui peut être 200 avec token JSON).
**ROUGE** : si Root != 200 → dev server down, restart `php artisan serve --host=127.0.0.1 --port=8000`.

### T1.5 — Bundle freshness
```bash
ls -lt public/js/admin-shell.js public/js/app.js public/js/pos-shell.js public/js/pos-app.js public/js/admin-kds.js | head -10
```
**Attendu** : timestamps récents (< 24h) couvrant heals 2026-05-28 20:21.
**ROUGE** : timestamps anciens → `npm run development`.

### T1.6 — Pricing SSOT smoke (sans tap UI)
```bash
php artisan test --filter='PricingServiceTest|PricingSnapshotTest' 2>&1 | tail -10
```
**Attendu** : PHPUnit GREEN sur les tests de Pricing.

**Critère PASS T1** : T1.1+T1.2+T1.3+T1.4+T1.5+T1.6 → tous PASS. Si UN seul ROUGE → STOP tout, fix avant T2.

---

## §4 — TIER 2 — SURFACE-BY-SURFACE (3-4h)

### Format de chaque test surface

```
🧪 Test S.X — <nom>
URL          : http://127.0.0.1:8000/<path>
Acteur       : <admin@lecayenne.fr / kiosk borne / client>
Viewport     : <1080×1920 kiosk | 1920×1080 desktop+TV | 1440×900 admin | 390×844 mobile>
Setup        : <pré-conditions>
Action       : <clicks séquentiels>
✅ Visuel    : <ce qui DOIT apparaître>
✅ Technique : <ce qui DOIT être vrai backend>
📸 Capture   : /tmp/foodking-test/T2-<id>.png
🔴 Échec     : <symptôme observé>
```

---

### 4.1 — POS CAISSE (`/admin/pos`)

#### S-POS-01 — Login admin → dashboard
- **URL** : `/login`
- **Action** : email `admin@lecayenne.fr` + password `123456` + submit
- **✅ Visuel** : redirige vers `/admin` ou `/admin/dashboard`. Sidebar visible avec : Tableau de Bord, POS, Produits & Stock, Catalogue, Attribut d'Articles, etc. KPI affichés : Total ventes, commandes, articles menu, Suivi en direct (CA, ticket moyen), Alertes SLA, Répartition canal.
- **✅ Technique** : Sanctum token stocké en localStorage `vuex.auth.authToken`. API `/api/admin/dashboard/total-sales` HTTP 200.
- **📸** : `T2-POS-01-dashboard.png`
- **🔴 Échec** : si 422 → vérifier que admin user existe + status=1 + branch_id=0.

#### S-POS-02 — Cash drawer ouverture
- **URL** : `/admin/pos`
- **Action** : clic bouton "Ouvrir caisse" → saisir fond de caisse 100 € → confirmer
- **✅ Visuel** : modal ouverture → bouton "Ouvert" actif. Pas de raw label (`pos.X`). Fond de caisse affiché 100,00 €.
- **✅ Technique** : `CashDrawerSession` créé en DB. AuditLog `cash_drawer.opened` row added. Chain hash continues.
- **📸** : `T2-POS-02-drawer-opened.png`
- **🔴 Échec** : si pas de modal → drawer déjà ouvert (CDS#4 ou #7 documentés Round 2). Reconcile via tinker : `DeliveryBoyCashSession::where('status','open')->each(fn($s)=>$s->update(['status'=>'closed']))`.

#### S-POS-03 — Wizard Sandwich Cayenne complet
- **Action** : clic catégorie "Sandwich" → tile Sandwich Cayenne → wizard ouvre.
- **✅ Visuel** :
  - Étape 1 — Choix viande : 4 options (Poulet crispy / curry / mariné / tandoori), boutons ≥44px hit area
  - Étape 2 — Sauce : 10 sauces visibles SANS "Cayenne fromagère maison" (déjà incluse), Algérienne/Andalouse/Blanche/Curry/Hannibal/Harissa/Ketchup/Mayonnaise/Samouraï/Spicy
  - Étape 3 — Crudités (selon profile)
  - Étape 4 — Suppléments (selon profile)
  - Total calculé en bas du wizard avec € symbol (jamais NaN, jamais 0undefined)
  - Bouton "Ajouter au panier" en bas
- **✅ Technique** : pas de POST jusqu'à l'ajout panier. Quand ajouté : DOM panier affiche ligne avec composition. Pas d'erreur console.
- **📸** : `T2-POS-03-wizard-cayenne-{etape1,2,3,4}.png` + `T2-POS-03-cart.png`
- **🔴 Échec** : si "Cayenne fromagère" apparaît dans sauce list → `WizardCayenneAndBolsCorrectionsSeeder` pas appliqué.

#### S-POS-04 — Wizard Tacos
- **Action** : tile Tacos → wizard
- **✅ Visuel** : 10 sauces (Algérienne/Andalouse/Blanche/Curry/Hannibal/Harissa/Ketchup/Mayonnaise/Samouraï/Spicy) + viande + suppléments
- **📸** : `T2-POS-04-wizard-tacos.png`

#### S-POS-05 — Wizard Bowl Frites Poulet mariné
- **Action** : catégorie "Bols" → Bowl Frites Poulet mariné
- **✅ Visuel** :
  - Sauce step : **EXACTEMENT 2 sauces** (Sauce fromagère maison + Spicy)
  - Suppléments : "Boule gratinée 2.00 €" + "Option Gratiné 2.00 €" visibles dans même step (PAS de step séparé "gratine")
  - Si "Option Gratiné" coché → total cart se met à jour de +2€
- **✅ Technique** : DOM montre `supplement_bol` group_label uniquement (pas de `gratine` step).
- **📸** : `T2-POS-05-wizard-bowl-{sauce,supplements,gratine-impact}.png`
- **🔴 Échec critique** : si 11 sauces ou step séparé gratine → seeder pas appliqué.

#### S-POS-06 — Paiement cash 1-tranche
- **Setup** : panier 1 item (Sandwich Cayenne 7.50€) drawer ouvert
- **Action** : "Encaisser" → mode CASH → saisir montant exact 7.50 → valider
- **✅ Visuel** : modal paiement → écran "Paiement validé ✓" → reçu PDF généré OU affiché → drawer rest ouvert
- **✅ Technique** :
  ```sql
  SELECT id, total, payment_status, fiscal_sequence_no, composition_snapshot 
  FROM orders WHERE id = (SELECT MAX(id) FROM orders);
  ```
  → total=7.50 / payment_status=PAID / fiscal_sequence_no incrémenté / composition_snapshot non-vide JSON
- **📸** : `T2-POS-06-paiement-cash.png` + `T2-POS-06-receipt.png`

#### S-POS-07 — Paiement card (simulation TPE)
- **Setup** : `POS_SIMULATION_HARDWARE=true` dans `.env` (dev only) + PaymentTerminal seedé branch=1 status=1
- **Action** : nouvel panier → "Encaisser" → mode CARD → simuler approval TPE
- **✅ Visuel** : modal "En attente TPE" → "Paiement validé ✓"
- **✅ Technique** : audit_logs row `payment.card.approved` ajouté. Chain hash extends.
- **📸** : `T2-POS-07-paiement-card.png`

#### S-POS-08 — Paiement SPLIT cash+card
- **Action** : panier 20€ → "Encaisser" → SPLIT → 10€ cash + 10€ card
- **✅ Visuel** : 2 tranches affichées clairement, total atteint, paiement validé
- **✅ Technique** : `order_payments` 2 rows (CASH 10 + CARD 10), order.total=20, payment_status=PAID
- **📸** : `T2-POS-08-split-payment.png`

#### S-POS-09 — Refund + Z window enforcement
- **Action** : depuis liste commandes du jour → refund partiel (qty -1)
- **✅ Visuel** : modal raison + montant pré-rempli + bouton "Rembourser"
- **✅ Technique** : `RefundCreated` event → mirror order créée avec `parent_order_id` + `parent_order_serial_no` + fresh `fiscal_sequence_no`. AuditLog chain extends.
- **📸** : `T2-POS-09-refund.png`

#### S-POS-10 — Z report close + PDF
- **Action** : Menu → Z reports → bouton "Clôturer la journée"
- **✅ Visuel** : modal récap (total ventes / CB / cash / nombre commandes) → confirmer
- **✅ Technique** :
  ```sql
  SELECT MAX(z_report_no), MAX(fiscal_sequence_no_until) FROM z_reports WHERE branch_id=1;
  ```
  → row monotonic. AuditLog `z_report.closed` row added.
- **📸** : `T2-POS-10-z-close.png` + `T2-POS-10-z-pdf.png`

#### S-POS-11 — Frozen-zone tamper attempt (POSITIF — doit échouer)
- **Action** : tenter d'ouvrir `public/js/pos-wizard.js` dans un IDE et ajouter un commentaire `// test`
- **Action 2** : sans rebuild, recharger `/admin/pos`
- **✅ Attendu** : RIEN ne change visuellement (le bundle compilé n'a pas été rebuild). Pour vraiment tester : commit avec ce changement → CI doit fail (frozen-zone sentinel)
- **🔴 ROUGE** : si commit + bundle rebuilt sans bloquer → frozen-zone protection cassée → escalade

---

### 4.2 — KIOSK BORNE (`/kiosk/idle`)

#### S-KIO-01 — Idle screen + tap to start
- **URL** : `/kiosk/idle` (viewport 1080×1920 portrait)
- **✅ Visuel** :
  - Mode 100% **light** (pas un seul fragment dark, pas de "moitié sombre" comme bug 2026-05-21)
  - Logo Le Cayenne visible
  - Sélecteur langue (FR/EN/AR si activé)
  - Promo carousel ou message "Tap to start"
  - Pas de bouton "thème" (retiré)
- **✅ Technique** : `localStorage.getItem('foodking:kiosk-theme')` === `'light'`. `document.documentElement.dataset.kioskTheme` === `'light'`.
- **📸** : `T2-KIO-01-idle.png`

#### S-KIO-02 — Catalogue après tap
- **Action** : tap "Commencer" ou écran
- **✅ Visuel** :
  - 9 catégories Le Cayenne (Sandwich / Galette / Tacos / Burgers / Bols / Menus / Desserts / Boissons / etc.)
  - Tiles produits avec image + nom + prix € correct
  - Pas de raw label `kiosk.X`
  - Pas de `0undefined` ou `[object Object]`
- **📸** : `T2-KIO-02-catalogue.png`

#### S-KIO-03 — Wizard Sandwich Cayenne (kiosk)
- **Action** : tap Sandwich Cayenne
- **✅ Visuel** : identique POS S-POS-03 mais en navigation client (étapes successives plein écran, pas popup)
- **📸** : `T2-KIO-03-wizard-{1,2,3,4}.png`

#### S-KIO-04 — Wizard Tacos + Bowl (cumulatif)
- Identique S-POS-04 + S-POS-05 mais en navigation client
- **📸** : `T2-KIO-04-tacos.png` + `T2-KIO-04-bowl.png`

#### S-KIO-05 — Panier multi-items + upsell
- **Setup** : panier avec 3 items (sandwich + bowl + boisson)
- **Action** : aller à panier → "Valider"
- **✅ Visuel** :
  - Total calculé visible
  - Page upsell propose accompagnement (boisson, dessert) si pas déjà au panier
  - Bouton "Passer" toujours accessible
- **📸** : `T2-KIO-05-cart.png` + `T2-KIO-05-upsell.png`

#### S-KIO-06 — Paiement card kiosk (simulation)
- **Action** : sélectionner CARD → simuler TPE approval
- **✅ Visuel** : écran "Paiement en cours" → "Merci, votre commande" → écran attente
- **✅ Technique** :
  ```sql
  SELECT id, source, fiscal_sequence_no, total, payment_status 
  FROM orders WHERE source LIKE '%kiosk%' ORDER BY id DESC LIMIT 1;
  ```
  → source=KIOSK / fiscal_sequence_no incrémenté (alloué à création paid) / payment_status=PAID
- **📸** : `T2-KIO-06-payment.png` + `T2-KIO-06-confirmation.png`

#### S-KIO-07 — Auto-redirect après "Commande prête"
- **Setup** : commande payée affichée en page "Préparation"
- **Action** : depuis KDS (autre onglet) → bump → READY
- **✅ Visuel kiosk** : auto-redirect vers home après 10s. Bouton Home visible toujours.
- **📸** : `T2-KIO-07-ready-redirect.png`

---

### 4.3 — KDS CUISINE (`/kds`)

#### S-KDS-01 — Board layout V2 grid
- **URL** : `/kds`
- **Viewport** : 1920×1080 TV wall
- **✅ Visuel** :
  - Grille 4×2 = 8 slots (cards FIFO)
  - Header "Historique" + warning "Liste pleine (X affichée(s))" si full
  - Chaque card : badge NOUVELLE/EN COURS, source (BORNE/CAISSE), queue number (A0001…), timer ATTENTE
  - Items lignes avec quantité × nom
  - CTA "Prêt" bottom-right par card (52px hauteur)
- **✅ Technique** : polling 5s OU Echo channel `private-branch.1`. WCAG contrast headers ≥4.5:1.
- **📸** : `T2-KDS-01-board.png`

#### S-KDS-02 — Bump ACCEPT → PREPARING → PREPARED
- **Action** : clic bump sur card NOUVELLE → state PREPARING (couleur change)
- **Action 2** : clic bump sur card PREPARING → state PREPARED → card disparaît du board, apparaît dans Historique
- **✅ Technique** : `order_states` rows pour transitions. `OrderStateMachine` enforced (pas de transition arrière).
- **📸** : `T2-KDS-02-bump-flow.png`

#### S-KDS-03 — Allergens modal
- **Setup** : item avec `allergen_flags=["arachides"]` (peut nécessiter seed manuelle)
- **Action** : clic sur card avec icône allergène
- **✅ Visuel** : modal liste allergènes, fond contrasté, bouton fermer
- **📸** : `T2-KDS-03-allergens-modal.png`

#### S-KDS-04 — Hostile reverse-transition (positif — DOIT échouer)
- **Action** : via API curl, tenter `POST /api/admin/kds-order/{id}/recall` sur order DELIVERED
- **✅ Attendu** : HTTP 422 ou 409, pas de mutation DB
- **🔴 Échec** : si transition réussit → OrderStateMachine cassé → P0 critique

---

### 4.4 — OSS ÉCRAN CLIENT

#### S-OSS-01 — Wall display via alias
- **URL** : `/order-status-screen` (alias public)
- **Viewport** : 1920×1080 TV
- **✅ Visuel** :
  - 2 colonnes : "En préparation" (rouge brand) + "Prêt" (vert brand)
  - Order numbers A0001-A99XX visibles
  - Pas de modal qui couvre les infos
  - Pas de bouton login visible
- **✅ Technique** :
  - curl HTTP 200 sur `/order-status-screen` (alias) — pas 404
  - curl HTTP 200 sur `/admin/order-status-screen` (canonique)
  - publicFriendlyPaths fonctionne (pas de bounce login en 401)
- **📸** : `T2-OSS-01-wall.png`

#### S-OSS-02 — Real-time refresh
- **Setup** : OSS dans onglet 1 + KDS dans onglet 2
- **Action onglet 2** : bump PREPARED sur card
- **Action onglet 1** : observer si ordre migre "En préparation" → "Prêt"
- **✅ Technique** : latence <5s (polling fallback) ou <500ms (Echo)
- **📸** : `T2-OSS-02-flip.png` (avant + après)

#### S-OSS-03 — Wakelock TV
- **Action** : laisser OSS ouvert sans interaction 5 min
- **✅ Visuel** : écran ne se met PAS en veille (wakelock JS actif)
- **✅ Technique** : `navigator.wakeLock.request('screen')` log visible console
- **📸** : pas nécessaire (validation timing)

---

### 4.5 — ADMIN DASHBOARD

#### S-ADM-01 — Dashboard KPIs
- **URL** : `/admin/dashboard` (ou `/admin`)
- **✅ Visuel** : KPI cards (Total ventes / commandes / articles / SLA / canal). Sidebar 17+ items French i18n.
- **📸** : `T2-ADM-01-dashboard.png`

#### S-ADM-02 — Items catalogue
- **URL** : `/admin/items`
- **✅ Visuel** : table 45 items, search, filter category, pagination (mais ignored — V1.0.2 backlog OK), bouton "Nouveau"
- **📸** : `T2-ADM-02-items.png`

#### S-ADM-03 — Stock rupture management V2
- **URL** : `/admin/stock/rupture` (PAS `/admin/stock-rupture-dashboard` qui est 404)
- **✅ Visuel** : 2-pane (categories left + products right), toggles in-stock/out-of-stock, role=switch
- **Action** : toggle 1 produit → vérifier sync POS+Kiosk
- **📸** : `T2-ADM-03-stock.png`

#### S-ADM-04 — Z reports
- **URL** : `/admin/dashboard` → widget "Dernier Z" + bouton Cloturer
- **✅ Visuel** : list Z fermés + PDF download possible (caveat : nécessite branch_id pinning user)
- **📸** : `T2-ADM-04-z-list.png`

#### S-ADM-05 — Cash overview unifié
- **URL** : `/admin/cash-overview`
- **✅ Visuel** : breakdown source × mode (Kiosk-card / POS-cash / Livreur-cash...) avec totaux + reconciliation 100% =
- **📸** : `T2-ADM-05-cash-overview.png`

#### S-ADM-06 — Permissions check (sécurité visuelle)
- **Setup** : créer user avec role Branch Manager (sans permission `settings`)
- **Action** : login en tant que Branch Manager → tenter `/admin/setting`
- **✅ Attendu** : redirect 403 ou page "Pas d'accès"
- **📸** : `T2-ADM-06-permission-denied.png`

---

### 4.6 — LIVREUR

#### S-LIV-01 — Admin liste delivery-boys
- **URL** : `/admin/delivery-boys`
- **✅ Visuel** : table livreur avec colonnes (Nom / Email / Téléphone format `+33700000010` PAS `nullPENDING_CREATE_*`)
- **📸** : `T2-LIV-01-list.png`

#### S-LIV-02 — Cash sessions list (heal axios verified)
- **URL** : `/admin/delivery-boy-cash-sessions`
- **✅ Visuel** : table avec session id=1 livreur=10 branch=1 status=Ouverte opening=50,00€
- **✅ Technique** : Network tab → axios call `/api/admin/delivery-boy/cash-sessions` (PAS `/api/api/`)
- **📸** : `T2-LIV-02-cash-sessions.png`

#### S-LIV-03 — Session show + reconcile
- **URL** : `/admin/delivery-boy-cash-sessions/1`
- **✅ Visuel** : session details + movements list
- **📸** : `T2-LIV-03-session-detail.png`

---

## §5 — TIER 3 — CROSS-SURFACE INTERSECTIONS (1-2h)

### Format intersection test

```
🔗 Test X.Y — <chain name>
Setup       : N tabs ouverts simultanés
Step 1      : <action surface A>
Step 2      : <observation surface B>
Step 3      : <verification DB + audit_log + chain hash>
Latence cible : <ms>
Capture     : tabs 1+2+DB
```

### X-01 — Kiosk → KDS broadcast

- **Tabs** : 1=Kiosk `/kiosk/idle` | 2=KDS `/kds` | 3=DB tinker | 4=Admin orders list
- **Step 1** : Kiosk → wizard Sandwich Cayenne → ajouter panier → paiement card simulation
- **Step 2** : observer KDS tab 2 — la card doit apparaître dans **<3s** (Echo) ou **<5s** (polling)
- **Step 3** : tab 3 :
  ```sql
  SELECT id, source, total, fiscal_sequence_no, composition_snapshot, payment_status
  FROM orders ORDER BY id DESC LIMIT 1;
  ```
- **✅ Intégrité numérique** : `cart_total_kiosk == order.total == kds_card.total`
- **📸** : `T3-X01-kiosk-payment.png` + `T3-X01-kds-arrival.png` + `T3-X01-db-row.txt`

### X-02 — POS → KDS broadcast

- Identique X-01 mais source POS Caisse
- **✅ Visuel KDS** : badge "CAISSE" (pas "BORNE")
- **📸** : `T3-X02-pos-payment.png` + `T3-X02-kds-arrival.png`

### X-03 — KDS → OSS flip

- **Tabs** : 1=KDS | 2=OSS
- **Step 1** : KDS bump card de PREPARING → PREPARED
- **Step 2** : OSS tab 2 — order doit migrer "En préparation" → "Prêt" en **<5s**
- **📸** : `T3-X03-before-bump.png` + `T3-X03-after-bump.png`

### X-04 — Stock cascade

- **Tabs** : 1=Admin `/admin/stock/rupture` | 2=POS `/admin/pos` | 3=Kiosk `/kiosk/idle`
- **Step 1** : tab 1 → toggle "Sandwich Cayenne" en RUPTURE
- **Step 2** : tab 2 POS → la tile Sandwich Cayenne devient grisée/disabled en **<1s** (Echo) ou refresh
- **Step 3** : tab 3 Kiosk → badge "Épuisé" sur la tile
- **📸** : `T3-X04-{admin,pos,kiosk}-after-rupture.png`

### X-05 — Settings cascade

- **Tabs** : Admin `/admin/setting` + POS + Kiosk
- **Step 1** : changer Currency Symbol € → £ (test only, restore après)
- **Step 2** : observer tous les € → £ flip cross-surface
- **CAVEAT** : ne pas sauvegarder en production — restore.

### X-06 — Branch deactivate → tokens revoke

- **Action** : `php artisan tinker --execute="\App\Models\Branch::find(1)->update(['status'=>0])"`
- **Observer** : tous les tabs admin/kiosk/POS doivent forcer re-login en **<10s**
- **Action retour** : restore status=1 avant T4.

### X-07 — Refund mirror order chain integrity

- **Action** : POS refund partiel sur order existante
- **Verify** :
  ```sql
  SELECT parent_order_id, parent_order_serial_no, fiscal_sequence_no, total
  FROM orders WHERE parent_order_id IS NOT NULL ORDER BY id DESC LIMIT 1;
  -- Should be the mirror with fresh fiscal_sequence_no, parent != null
  ```
- **NF525 check** : chain hash extended, no break.

---

## §6 — TIER 4 — ADVERSARIAL ABUSE (2-3h)

**Mode** : hostile. Tu tentes délibérément de casser chaque invariant. Le défense DOIT tenir.

### A-01 — INV-1 Pricing SSOT (cart tampering)

- **Action** : via Network tab browser → intercepter POST `/api/frontend/order` → modifier `total` body → 0.01€
- **✅ Attendu** : backend ignore le total client, calcule depuis composition. Order créée avec total réel (pas 0.01).
- **🔴 ROUGE** : si order.total == 0.01 → PricingService SSOT cassé → P0 fiscal.

### A-02 — INV-2 Fiscal sequence gap insertion

- **Action** : tinker :
  ```php
  \DB::table('orders')->insert([
    'branch_id'=>1, 'fiscal_sequence_no'=>99999,
    'total'=>10, 'status'=>4, 'payment_status'=>1,
    'created_at'=>now(), 'updated_at'=>now()
  ]);
  ```
- **Action 2** : créer une vraie order kiosk après
- **Verify** : nouveau fiscal_sequence_no = max(99999, séquence normale)+1 ? OU sequence reprend ?
- **NB** : ce test peut casser la chain pour de bon → fait sur backup DB only.

### A-03 — INV-3 Audit chain DELETE attempt

- **Action** : tinker :
  ```php
  try {
    \App\Models\AuditLog::first()->delete();
    echo 'DANGER : DELETE réussi'.PHP_EOL;
  } catch (\Throwable $e) {
    echo 'OK : '.$e->getMessage().PHP_EOL;
  }
  ```
- **✅ Attendu** : exception SQLSTATE 45000 ou Eloquent block.
- **🔴 ROUGE** : si DELETE réussit → trigger DB pas en place ou bypassed → CRITICAL P0.

### A-04 — INV-4 Branch isolation (IDOR)

- **Setup** : créer order temporaire avec branch_id=2 (forcer via tinker)
- **Action** : login admin branch_id=1 → tenter `GET /api/admin/order/show/<id_branch2>`
- **✅ Attendu** : HTTP 404 (BranchScope filter). PAS 403 (révèle existence), PAS 200.

### A-05 — INV-5 Composition snapshot UPDATE attempt

- **Action** : tinker :
  ```php
  \DB::table('order_items')->where('id', 1)->update(['composition_snapshot'=>json_encode(['hacked'=>true])]);
  ```
- **✅ Attendu** : SQLSTATE 45000 (trigger `order_items_composition_snapshot_no_update`).
- **🔴 ROUGE** : si UPDATE réussit → trigger manquant ou disabled.

### A-06 — INV-6 Idempotency double-fire

- **Action** : 2x rapide même POST `/api/admin/pos-order` avec même `X-Idempotency-Key`
- **✅ Attendu** : 1er → HTTP 201 created. 2ème → HTTP 200 + header `Idempotency-Replayed: true`, même order_id.
- **Action 2** : 2x avec même key mais payload différent
- **✅ Attendu** : 2ème → HTTP 409 conflict.

### A-07 — INV-7 OrderStateMachine reverse transition

- **Action** : tenter API call `POST /api/admin/order/{id}/status` avec status=NEW sur order DELIVERED
- **✅ Attendu** : 422 + message rejet.

### A-08 — INV-8 Frozen-zone tamper

- **Action** : `echo "// hack" >> public/js/pos-wizard.js && git diff --stat`
- **✅ Attendu** : git diff montre 1 ligne. CI sentinel doit bloquer commit. Revert immédiat.

### A-09 — XSS via product name

- **Action** : tinker `Item::find(22)->update(['name'=>'<script>alert(1)</script> Cayenne'])`
- **Action 2** : recharger POS catalogue
- **✅ Attendu** : nom affiché comme texte échappé `&lt;script&gt;`, pas d'alert. Restore après.

### A-10 — File upload polyglot

- **Setup** : créer fichier `evil.jpg.php` avec contenu PHP
- **Action** : admin upload via interface item image
- **✅ Attendu** : rejet (validation extension + magic bytes).

### A-11 — Concurrent kiosk + POS simultaneous payment

- **Tabs** : Kiosk + POS en parallèle
- **Step** : démarrer paiement card simultanément sur les 2 surfaces
- **Verify** : 2 orders distinctes créées, fiscal_sequence_no monotonic sans collision.

### A-12 — Receipt printer offline simulation

- **Action** : kill imprimante simulée OU déconnecter service
- **Action 2** : payment cash POS
- **✅ Attendu** : modal "Ticket d'impression failed" mais order reste PAID + audit complet.

### A-13 — Network drop mid-payment kiosk

- **Action** : DevTools → Network throttling → "Offline" pendant paiement
- **Action 2** : Restore network après 30s
- **✅ Attendu** : retry visible OU recovery page. Pas de double-charge.

### A-14 — Browser refresh mid-flow

- **Action** : F5 au milieu du wizard kiosk (étape 2)
- **✅ Attendu** : retour idle OU restauration panier (selon UX choice). Pas d'orpheline.

### A-15 — Decimal precision boundary (€0.01)

- **Action** : créer order avec montants 0.005, 0.001, 9999.99
- **Verify** : round() correct. Pas de drift €0.001. Stripe envoie cents corrects (`floatval * 100`).

### A-16 — Mass orders queue stress

- **Action** : créer 50 orders rapides via API (script bash boucle curl)
- **Verify** : KDS board overflow chip "+N en attente" affiché correctement. Polling cadence stable.

---

## §7 — TIER 5 — PERSONAS (1-2h)

### P-01 — Cashier RUSH (15 commandes en 5 min)

- **Scénario** : enchaîne 15 orders cash POS, alternance simple/wizard/split
- **Verify** : aucun timeout, aucun freeze UI, drawer balance correct end-of-rush
- **Capture** : vidéo 5 min (capture-screen tool) + DB state final

### P-02 — Client IMPATIENT kiosk (timeout + recovery)

- **Scénario** : démarrer wizard, laisser 90s sans action
- **Verify** : inactivity overlay apparaît → tap recovery → état préservé OU reset propre

### P-03 — Chef under PRESSURE KDS (20 orders rush)

- **Scénario** : pré-seed 20 orders status NEW → ouvrir KDS
- **Verify** : grid overflow chip, contrast lisible à 2m de distance, bump rapide (<200ms response)

### P-04 — Owner LATE-NIGHT Z close + reconcile

- **Scénario** : end of day → revue cash overview → cloture Z → vérifier PDF + audit chain extends
- **Verify** : chain count grows by 1+, last_hash extended, Z report PDF téléchargeable

### P-05 — New EMPLOYEE first day (sandbox mode)

- **Scénario** : créer user role POS Operator (limited perms) → tester sa view
- **Verify** : voit seulement POS, pas settings/Z reports/Admin

---

## §8 — TIER 6 — FINAL CONVERGENCE (30 min)

### F-01 — Smoke broad
```bash
php artisan test --filter='Sentinel|Pricing|Fiscal|Branch' 2>&1 | tail -20
```
**Attendu** : 100% passed sur sentinels critiques.

### F-02 — Vitest sentinels
```bash
npx vitest run tests/js/sentinels/ --reporter=basic 2>&1 | tail -10
```
**Attendu** : compare vs baseline (6 pre-existing fails acceptés V1.0.X).

### F-03 — Frozen-zone diff final
```bash
git diff --stat -- <13 frozen files>
```
**Attendu** : empty.

### F-04 — NF525 chain final
```bash
php artisan fiscal:verify-chain --all
```
**Attendu** : CHAIN OK + count grew appended-only.

### F-05 — Visual regression sweep
- Compare T2 captures avec captures référence si existantes (sinon archive comme nouvelle baseline)

### F-06 — i18n raw label sweep
```bash
grep -rE "(kiosk|pos|kds|oss|admin|label)\.[a-z_]+$" /tmp/foodking-test/*.png_dom 2>&1 | head -20
```
**Attendu** : empty (aucun raw key visible).

---

## §9 — INTÉGRITÉ NUMÉRIQUE (à valider à CHAQUE test order)

```
cart_total (FE display)
  ==
backend order.total (DB)
  ==
receipt.total (PDF)
  ==
kds_card.total (KDS DOM)
  ==
audit_log.payload.total (audit_logs row)
  ==
oss_card.amount (OSS DOM si affiché)
```

**Toute incohérence numérique sur n'importe quel maillon = P0 critique.**

---

## §10 — CRITÈRES CONVERGENCE FINALE

✅ **GREEN** si TOUS les suivants :

1. T1 Foundation : 6/6 PASS
2. T2 Surfaces : 0 raw label / 0 console error / 0 layout broken sur 6 surfaces × N pages
3. T3 Intersections : 7 chains numerically intègres
4. T4 Adversarial : tous les 16 défenses tiennent (aucun invariant cassé)
5. T5 Personas : 5 scenarios passent
6. T6 Final : sentinels GREEN + chain OK + frozen-zone diff=0

🔴 **RED** si UN SEUL :
- Raw label visible
- Numéro incohérent inter-surface
- Frozen-zone touched
- NF525 chain break
- Invariant breakable (A-XX réussit)

---

## §11 — REPORTING + RÉPERTOIRES

### Capture
- **Screenshots** : `/tmp/foodking-test-2026-05-28/T{1..6}-{ID}.png`
- **Findings JSON** : `reports/test-e2e/owner-trial-test-2026-05-28/findings.json`
- **Final report** : `reports/test-e2e/owner-trial-test-2026-05-28/REPORT.md`

### Template findings.json
```json
{
  "test_id": "T2-POS-03",
  "name": "Wizard Sandwich Cayenne",
  "status": "PASS|FAIL|SKIP",
  "visual_capture": "/tmp/foodking-test-2026-05-28/T2-POS-03-wizard-{etape}.png",
  "expected_visual": "10 sauces SANS Cayenne fromagère",
  "observed_visual": "...",
  "technical_check": "DB row order created with composition_snapshot non-empty",
  "technical_result": "PASS",
  "blocker": false,
  "severity": "P0|P1|P2|P3|INFO",
  "notes": "..."
}
```

### Template REPORT.md
```markdown
# Owner Trial-Test Report — 2026-05-28
## Verdict : GREEN-V1-LOCAL / NEEDS-HEAL / NO-GO

## Per-tier results
- T1 Foundation : X/6 PASS
- T2 Surface POS : X/11 PASS
- T2 Surface Kiosk : X/7 PASS
- T2 Surface KDS : X/4 PASS
- T2 Surface OSS : X/3 PASS
- T2 Surface Admin : X/6 PASS
- T2 Surface Livreur : X/3 PASS
- T3 Intersections : X/7 PASS
- T4 Adversarial : X/16 PASS
- T5 Personas : X/5 PASS
- T6 Final : X/6 PASS

## P0/P1 findings
| ID | Severity | System | Title | Reproduction | Visual evidence |

## Frozen-zone diff final
(paste output)

## NF525 chain attestation
(paste output)
```

---

## §12 — ORDRE D'EXÉCUTION RECOMMANDÉ

```
Jour 1 (4h)
  ├─ T1 Foundation (30 min)
  ├─ T2 POS (45 min) — caissier prioritaire
  ├─ T2 Kiosk (45 min) — client prioritaire
  ├─ T2 KDS (30 min)
  ├─ T2 OSS (20 min)
  └─ Pause + review captures

Jour 2 (4h)
  ├─ T2 Admin (60 min)
  ├─ T2 Livreur (30 min)
  ├─ T3 Intersections X01-X07 (90 min)
  └─ Review + intermediate verdict

Jour 3 (3h)
  ├─ T4 Adversarial (2h) — hostile mode
  └─ T5 Personas (1h)

Jour 4 (1h)
  ├─ T6 Final convergence
  ├─ Report final
  └─ Verdict GO/NO-GO
```

**Tu peux compresser** si certains tiers passent vite. **Tu ne peux PAS skip** T1 ou T6.

---

## §13 — SI BESOIN — PARALLÉLISATION AGENTS

Je peux dispatcher des sub-agents pour :
- **Bulk capture Playwright headed** des 60+ surfaces T2 en 1h (pendant que tu fais autre chose)
- **Adversarial probe automatisé** des 16 A-XX en parallèle (curl + tinker hostile scripts)
- **Numeric integrity ledger** auto-build sur DB (extraction cart_total/order.total/kds.total/audit.payload.total pour chaque order créée)
- **Cross-surface latence measurement** automatisé (timestamp DOM mutation Kiosk → KDS card insert)

Dis-moi si tu veux que je lance ces agents en // pendant ton trial-test manuel.

---

## §14 — RAPPELS V1 LOCAL Le Cayenne

- POS_SIMULATION_HARDWARE=true autorisé en dev local UNIQUEMENT
- En prod : production boot guard refuse de booter si simulation_hardware != false
- 1 branch Le Cayenne (branch_id=1)
- 1 TPE physique (idéalement seedé id=1)
- 0 cloud, 0 SaaS, 0 multi-tenant V1
- French i18n stricte — 0 mots anglais visibles user-facing
- Frozen-zone discipline absolue
- Owner approval gate avant tout deploy / cloud / hardware install

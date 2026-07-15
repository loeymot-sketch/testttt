# Journey B — BORNE (kiosk) — Round 1 — 2026-07-15

Serveur LIVE http://127.0.0.1:8000. Token kiosk:order forgé (`e2e-borne-rj`, user_id=3, kiosk machine ULTRA-E2E-BORNE, branch 1).

## Parcours exécuté (preuves)

1. **kiosk-login** OK — `POST /api/auth/kiosk-login` → token, kiosk id=1 branch=1.
2. **Quote** `POST /api/frontend/order/quote` (Tacos M 26 + viande 43 + sauce 311 + extra Cheddar 250, cash) → `subtotal 7.80, total_ttc 7.80, total_tax 0.71, quote_token, signature`.
3. **Order** `POST /api/frontend/order` (quote_token+signature) → **order 5693** `total=7.80` == quote (preview == facturé ✓), `payment_status=15 PENDING_COUNTER`, `pos_payment_method=COUNTER_DEFERRED`, `status=Acceptée` (Plan B cash → comptoir ✓).
4. **composition_snapshot figé** ✓ — snapshot présent sur order_item 5456, lignes viande/sauce/extra avec `line_total` scellés.
5. **domain_events** ✓ — `order.created` (EV 10568) + `order.status_changed` (EV 10569) émis pour aggregate 5693.

## Invariants adversaires TESTÉS — tous TIENNENT (aucun défaut)

- **Immuabilité snapshot** : `UPDATE order_items SET composition_snapshot=... WHERE id=5456` → **BLOQUÉ** `SQLSTATE[45000] 1644 NF525: composition_snapshot is immutable (J2-HEAL-06)`. ✓
- **Quote intent mismatch** : order avec items plus chers (extras 251+248 ajoutés) réutilisant signature du quote 7.80 → `{"status":false,"message":"Order quote intent mismatch."}`. ✓
- **Quote consume-once** : 2e submit même quote_token (idem key différent) → `Order quote has already been consumed.`. ✓
- **quote_token requis (borne physique)** : order sans quote_token → 422 `The quote token field is required.`. ✓
- **Idempotency replay** : 2 submits même `X-Idempotency-Key` → même order id 5706, zéro doublon. ✓
- **Token ability** : token forgé SANS `kiosk:order` → quote `Kiosk quote requires kiosk order ability.`, order **403**. ✓
- **Rupture variation** : Tacos M avec viande status=10 (id 44) → 422 `Sélectionnez au moins 1 Viande 1 (actuel : 0)` (variation rupture filtrée, order bloqué). ✓
- **Rupture item** : Big Cayenne (item 36, status=10) au quote → `Article 36 inactif dans le catalogue. Commande rejetée.`. ✓
- **branch_id serveur-résolu** : borne ignore branch_id client, résolu depuis KioskMachine. ✓
- **source_surface serveur-autoritaire** : `='kiosk'` fixé serveur (FrontendOrderService:578), pas depuis le client. ✓

## Défaut trouvé (P3 — hardening / defense-in-depth)

### [P3] Plan B non appliqué côté serveur : commande borne CARTE crée un ordre inencaissable ~3h
- Fichier : `app/Services/FrontendOrderService.php:199-291` + `config/kiosk.php:187` (`payment_route_all_to_counter=true`).
- Repro EXÉCUTÉE : quote cash→card, puis `POST /api/frontend/order` avec `payment_method=4` (carte) → **order 5702** créé `payment_status=10 (UNPAID)`, `status=1 (PENDING)`, `pos_payment_method=NULL`, `pending_counter=false`, **0 domain_events** (pas de order.created → KDS non notifié).
- Impact : Le backend NE fait PAS respecter `payment_route_all_to_counter` — il fait confiance au `payment_method` client. Une commande carte borne :
  - n'est PAS dispatchée au KDS (chemin carte diffère la cuisine jusqu'au callback TPE),
  - n'apparaît PAS dans la file `/admin/pos/counter-collect/pending` (elle filtre `payment_status=PENDING_COUNTER` uniquement — routes/api.php:816),
  - reste UNPAID+PENDING donc inencaissable via le flux comptoir standard, jusqu'à la purge janitor (`CleanupStalePendingKioskOrders` TTL 180 min → REJECTED).
- Atténuations réelles : (1) sous Plan B, `KioskPaymentComponent.vue` masque la carte et auto-soumet cash (le `v-if` counter-route n'affiche que l'écran comptoir) → chemin carte NON atteignable via l'UI normale ; (2) le janitor reaps après 3h. Donc requiert un payload forgé OU un bundle borne périmé (pré-Plan-B).
- Fix suggéré : quand `config('kiosk.payment_route_all_to_counter')===true`, forcer `payment_method=CASH_ON_DELIVERY` (ou rejeter carte/TR) pour les commandes machine-borne dans FrontendOrderService, au lieu de se reposer uniquement sur le masquage UI. Aligne le backend sur le mandat Plan B (autoritativité serveur).

## Verdict
Parcours borne bout-en-bout SOLIDE. Prix SSOT, snapshot scellé+immuable, quote signé/consommé-une-fois, idempotency, ability token, routage Plan B cash, rupture item/variation — tous prouvés OK. Seul écart : Plan B non ré-appliqué serveur sur le `payment_method` (P3, UI-inatteignable + janitor-atténué).

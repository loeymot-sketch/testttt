# Validation E2E — corrections du jour (blocs B / C / D) — 2026-07-18

**Verdict global : 5 / 5 PASS — aucun défaut applicatif.** Toutes les corrections
du jour sont validées en conditions RÉELLES (serveur dev `:8000`, DB `foodking_e2e`).

| Test | Sujet | Commits validés | Résultat |
|------|-------|-----------------|----------|
| **V1** | Multi-sauces ticket cuisine ESC/POS + KDS | `95ec82adb`, `56cf0e8d1`, `8daa9ad03` | ✅ PASS |
| **V2** | Accept web INLINE en caisse (C1/C2) | `379eddac6` | ✅ PASS |
| **V3** | Notif client canal `customer.{id}` (C3) | `379eddac6` | ✅ PASS |
| **V4** | Fidélité ON / remises OFF (découplage) | `f2bf1b1a3`, `58545f534` | ✅ PASS |
| **V5** | 86 d'un extra/variation depuis la caisse (D) | `ef38e6c11`, `7f166b804` | ✅ PASS |

- **Spec** : `tests/e2e/_teste2e-blocCD-2026-07-18.spec.js` (READ/TEST — 0 fichier applicatif modifié).
- **Lancer** : `PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/_teste2e-blocCD-2026-07-18.spec.js --workers=1`
- **HEAD** : `7f166b804` · **DB** : `foodking_e2e` · **Run final** : `5 passed (36.2s)`.
- **Discipline** : fixtures préfixées `VALGOAL-`, cleanup `beforeAll`+`afterAll` (fiscal_sequence_no nullé AVANT tout hard-delete — trigger `orders_no_delete_when_fiscalized`). **Résidu post-run = 0** (orders/users/items/extras/attributes/loyalty_tx). Chaîne fiscale post-run : `fiscal:verify-chain --all` → **CHAIN OK sur 4 branches actives**.

---

## V1 — Multi-sauces : ticket cuisine ESC/POS ET KDS nomment la 2e sauce ✅

**Bug d'origine** : la 1ère sauce = variation nommée, mais la 2e+ sauce était un
`ItemExtra` générique « Sauce supplémentaire » SANS nom (le nom réel ne vivait que
dans le texte libre `instruction`, strippé par ticket/KDS) → on ne voyait que la 1ère.

**Preuves (rendu RÉEL) :**

- **Commande RÉELLE #5727** (Tacos M « Algérienne, Andalouse », OrderItem #5484)
  rendue via `OrderReceiptEscPosRenderer` (bytes décodés CP858→UTF-8) :
  - **Ticket CUISINE** : `… | ALG` (1ère sauce en symbole) **+** `* Sauce supplémentaire : Andalouse` (2e NOMMÉE). Contient `Andalouse` ✓, `Sauce suppl` ✓, `ALG` ✓.
  - **Ticket CLIENT** : contient `Algérienne` ✓ **ET** `Andalouse` ✓ (les 2 noms pleins).
- **Fixture board-released** (Tacos « Blanche, Andalouse ») + **feed KDS RÉEL**
  `GET /api/admin/kds-order` (exactement ce que `fetch` le composant KDS) : `order_items[0].instruction` contient `Blanche` ✓ **ET** `Andalouse` ✓.
- **Rendu VISUEL de l'écran cuisine** (capture `screenshots/V1-kds-multisauce.png`) :
  tuile `1× G | TAC | M | P | S | BL` (1ère sauce **BL**=Blanche en symbole) **+**
  ligne orange `⭐ Sauce supplémentaire : Andalouse` (2e sauce NOMMÉE) → **les deux, pas seulement la 1ère**.
- **Bonus — 2 sauces FRITES gratuites** : Menu avec `Sauce frites : Ketchup, Mayonnaise`
  → ticket cuisine `MENU : KTP MAY` ✓ (les 2), **jamais** `Sauce suppl` ✓, total `10,40` inchangé ✓ (sauce frites = 0 €).

---

## V2 — Accept web INLINE en caisse → encaissement fiscal, sans quitter le POS ✅

Cycle web unifié prouvé de bout en bout (commande web #5809, COD, à emporter) :

| Étape | Endpoint | Résultat |
|-------|----------|----------|
| Création web COD | `POST /api/frontend/order` | `source_surface=web`, `status=PENDING(1)`, `payment=UNPAID(10)` |
| Visible « Commandes web » | `GET /api/admin/pos/web-orders/pending` | `200` — id présent : `[…,5809]` |
| Accept INLINE | `POST /api/admin/online-order/change-status/5809` `{status:ACCEPT}` | `200` → `status=ACCEPT(4)`, `payment=PENDING_COUNTER(15)`, `pos_payment_method=COUNTER_DEFERRED(6)` |
| Quitte « Commandes web » | `GET …/web-orders/pending` | id **absent** (plus PENDING) |
| Encaissable comptoir | `GET /api/admin/pos/counter-collect/pending` | `200` — id présent : `[…,5809]` |
| Confirm CASH | `POST /api/admin/pos/counter-collect/5809/confirm` `{mode:1,received:20}` | `200` → `payment=PAID(5)`, **`fiscal_sequence_no=2677`** |

→ Accept inline + basculement COD→encaissable + file d'encaissement unifiée + allocation NF525 au VRAI encaissement : **tout le cycle bouclé en caisse**.

---

## V3 — Notif client : canal `customer.{id}` inspoofable + polling « Prête » ✅

Testé via l'endpoint RÉEL `POST /api/broadcasting/auth` (driver `pusher`, la garde
d'autorisation identitaire s'exécute réellement) :

| Requête (client A, id 251) | Attendu | Obtenu |
|----------------------------|---------|--------|
| `channel_name=private-customer.251` (SON canal) | 200 + signature | **200** `{"auth":"app-key:aae626fe…"}` ✅ |
| `channel_name=private-customer.252` (canal du client B) | 403 | **403** ✅ (anti-fuite cross-client) |

- **Polling fallback** : `GET /api/frontend/order/show/{id}` (commande PREPARED, token client)
  → `data.status = 8` **et** `data.status_name = "Prête"` ✅.

---

## V4 — Fidélité ON / remises OFF (découplage complet) ✅

Config runtime vérifiée : `manual_discount_enabled = false`, `loyalty_enabled = true`.

- **(a) Accrual actif malgré remises coupées** : commande complétée (listener réel
  `AwardLoyaltyPointsOnDelivery`) → solde client `0 → 10` pts, ledger `earn = 1`.
  Corroboré sur la surface API : `POST /api/frontend/loyalty/check` → `points = 10` ✅.
- **(b) Remise manuelle caisse REFUSÉE** : `POST /api/admin/pos/quote` (remise 1 € sur
  12 € = 8,3 %, sous le plafond ladder) → **200** ; puis `POST /api/admin/pos` (même
  remise) → **422** `« Les remises manuelles sont désactivées en V1 … »` ✅ (kill-switch,
  malgré la permission `pos-discount-up-to-10` du caissier).
- **(c) Redeem fidélité AUTORISÉ (F1 fixé)** : `PosRedemptionService::applyToOrder`
  (100 pts) → NON refusé, `discount_eur = 1`, solde `500 → 400`, total commande `25 → 24`,
  ledger `redeem = 1` ✅. Le kill-switch remises n'a PAS court-circuité la fidélité.

---

## V5 — 86 d'un extra précis depuis la caisse (panel partagé) ✅

Persona POS Operator (`pos@lecayenne.fr`, permission `availability_toggle`, PAS `items_show`) :

| Étape | Endpoint | Résultat |
|-------|----------|----------|
| Charger les options | `GET /api/admin/item/details/{item}?branch_id=1` | **200** (pas 403) — extras/variations présents, extra `is_available=true` |
| 86 de l'extra | `POST /api/admin/menu/availability/extra/toggle` `{is_available:false, reason:out_of_stock_manual}` | **200** `{ok:true, is_available:false}` |
| Relecture panel | `GET …/item/details/…` | extra `is_available = false` ✅ (branch-aware) |
| Réactivation | `POST …/extra/toggle` `{is_available:true}` | **200** → extra `is_available = true` ✅ |

---

## Défauts

**Aucun défaut applicatif.** Les 5 corrections du jour se comportent exactement comme
spécifié en conditions réelles.

### Notes de conformité (non-défauts)
- **NF525 / cleanup** : l'encaissement de test V2 a alloué `fiscal_sequence_no=2677`
  sur une commande de test ensuite supprimée (numéro nullé AVANT delete, comme l'exige
  le trigger `orders_no_delete_when_fiscalized`). Les écritures `cash_movements` /
  `audit_logs` correspondantes restent orphelines = **comportement CORRECT** (immuables).
  La chaîne d'audit reste `CHAIN OK` sur les 4 branches après le run.
- **Contexte accrual** : le listener lit `order_amount` pour un ordre POS ; cette colonne
  est absente de `foodking_e2e` → repli correct sur `total` (`?? $order->total`). Pas un bug.

### Notes d'écriture du spec (mécaniques app, découvertes en cours de rédaction)
Ces points ne sont PAS des défauts applicatifs — juste la manière dont l'app fonctionne,
documentée pour la reproductibilité :
- `OrderItem.composition_snapshot` est casté `array` → une fixture doit assigner un ARRAY
  brut (un `json_encode` serait double-encodé et illisible par le renderer/KDS).
- `User.loyalty_code` / `loyalty_points` ne sont PAS `fillable` → assignation directe +
  `saveQuietly()` requise pour semer un client fidélité.
- `availability/extra/toggle` : `reason` est obligatoire+whitelisté au 86 (`is_available:false`)
  et doit être ABSENT/null à la réactivation (`is_available:true`).

---

## Artefacts
- Spec : `tests/e2e/_teste2e-blocCD-2026-07-18.spec.js`
- Capture écran cuisine (V1) : `reports/goal-parite-sync-2026-07-18/screenshots/V1-kds-multisauce.png`
- Run final : `5 passed (36.2s)` — `PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/_teste2e-blocCD-2026-07-18.spec.js --workers=1`

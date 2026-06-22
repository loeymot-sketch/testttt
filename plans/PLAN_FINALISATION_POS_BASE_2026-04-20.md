# PLAN MÉGA — Finalisation POS + Robustesse base globale (2026-04-20)

**Auteur** : `foodking-planner-orchestrator` (Claude Opus 4.7)
**Date** : 2026-04-20
**Source** : Audit global V1→V11 + audit critique du méga-rapport + audit live de la prise de commande POS/Kiosk/KDS
**Mode** : `RUNNER_MODE: single-session` + `auto-remediation.mdc` actif
**Pré-requis** : aucun (cycle indépendant des 2 gates en attente C1-C8 + C9 — qui restent pertinents en parallèle)

---

## 0. Audit critique du méga-rapport V1→V11

Avant d'attaquer, je liste **les angles morts** que mon propre audit global n'avait pas couverts (car focus infrastructure / sentinelles / KIs) :

| # | Angle mort | Conséquence métier | Couvert par |
|---|---|---|---|
| AM-01 | **Asymétrie POS↔Kiosk sur multi-variation** : POS = single-select radio, Kiosk = compteur multi-qté | Bug bloquant prise de commande (tacos 4 viandes impossible en caisse) | Tâches **T01, T02, T03, T04** |
| AM-02 | Aucune **règle métier formalisée** pour `min_select` / `max_select` / `allow_repeat` par attribut | Modélisation produit ad-hoc, drift POS↔Kiosk inévitable | T01 (schéma backend) + T02 (UI POS) + T05 |
| AM-03 | Aucun audit **UX POS opérateur** (parking commandes, hold, recall, void, discount per-line) | Caisse manque les gestes pro standards (Lightspeed/Square parity) | T08, T09, T10 |
| AM-04 | Aucune tâche **hardware** (imprimante ESC/POS, cash drawer, scanner barcode, NFC fidélité) | Caisse ne peut pas opérer en restaurant réel | T15, T16 |
| AM-05 | Aucun audit **a11y POS** (focus order, ARIA, contrast caisse en environnement lumineux) | Non-conformité, fatigue opérateur, lockout en plein service | T18 |
| AM-06 | Aucune tâche **gestion erreurs UX** (réseau down, item 86'd en cours de saisie, payment decline) | Crash mental opérateur lors d'un edge-case | T11, T17 |
| AM-07 | Aucune tâche **KDS UX** (filtres station, son, regrouper par table, recall préparé) | Cuisine perd le fil sur rush | T13, T14 |
| AM-08 | Aucune tâche sur **OrderItem snapshot fidélité** (composition exacte au moment T) | Audit fiscal RETURNED partiel ; conflits si recette change | T19 |
| AM-09 | **Pricing SSOT** prouvé pour le format actuel `{attrId: varId}` mais **pas pour le format multi** `{attrId: {varId: count}}` | Si T01 livre, le serveur rejette / mal-calcule | T05 (extension SSOT pricing) + T20 |
| AM-10 | Aucune tâche **performance perçue** caisse (skeleton, optimistic add-to-cart, debounce search) | Lenteur perçue qui dégrade le rythme service | T12 |
| AM-11 | Aucune tâche **payment UX POS** (split, tip, partial refund per-line, multi-tender visuel) | UX paiement archaïque vs marché | T21 |
| AM-12 | Aucun **test E2E parcours métier complet** "tacos 4 viandes mix → POS → KDS → prep → ready → drawer" | Aucune garantie de non-régression bout-en-bout | T22 |

**Verdict** : le méga-rapport V1→V11 a livré une **infrastructure solide** (sentinelles, tests unitaires, KIs, gates), mais **n'a pas adressé les fonctionnalités métier finales** de la caisse. C'est ce que ce plan cible.

---

## 1. Vue d'ensemble des 22 tâches

### Code couleur surface
🟥 **POS** (caisse opérateur) — 🟦 **Kiosk** (borne client) — 🟨 **KDS** (cuisine) — 🟩 **Backend** — 🟪 **Cross-surface**

### Tableau master

| # | ID | Titre court | Surface | Modèle | Sév | Effort | Bloque ? |
|---|---|---|---|---|---|---|---|
| T01 | `P14_VARIATION_MODEL_MULTI_QTY_BACKEND` | Schéma backend multi-qty par variation (min/max/allow_repeat) | 🟩 | GPT-5.4 + GATE | **P0** | 1 j | T02-T05 |
| T02 | `P14_POS_VARIATION_MULTI_QTY_UI` | UI POS compteur multi-qté par variation (parité Kiosk) | 🟥 | GPT-5.4 | **P0** | 1.5 j | T03 |
| T03 | `P14_POS_KIOSK_VARIATION_PARITY_TESTS` | Tests parité POS↔Kiosk pour les 6 produits multi-viande/sauce/extras | 🟪 | Composer | P1 | 0.5 j | — |
| T04 | `P14_VARIATION_FIXTURES_DATA_REPAIR` | Correction fixtures DB pour produits multi-choix existants | 🟩 | Composer | P1 | 0.5 j | T01 |
| T05 | `P14_PRICING_SSOT_MULTI_QTY` | Extension SSOT pricing pour le format multi `{attrId:{varId:count}}` | 🟩 | GPT-5.4 + GATE | **P0** | 1 j | T01 |
| T06 | `P14_VARIATION_VALIDATION_BACKEND` | Form Request `min_select/max_select/allow_repeat` côté serveur | 🟩 | Composer | P1 | 0.5 j | T01 |
| T07 | `P14_ORDER_ITEM_SNAPSHOT_HARDENING` | OrderItem snapshot composition exacte (immutable) | 🟩 | GPT-5.4 + GATE | **P0** | 1 j | — |
| T08 | `P14_POS_PARK_HOLD_RECALL` | Parking commandes : hold / recall / multi-tabs | 🟥 | GPT-5.4 | P1 | 1 j | — |
| T09 | `P14_POS_LINE_DISCOUNT_VOID` | Discount par ligne + void per-line + reason audit | 🟥 | Composer + GATE | P1 | 0.5 j | — |
| T10 | `P14_POS_SEARCH_BARCODE_FAST` | Recherche produit instantanée + barcode scan + raccourcis | 🟥 | Composer | P1 | 0.5 j | — |
| T11 | `P14_POS_AVAILABILITY_LIVE_GUARD` | Gel UI quand item 86'd pendant saisie (live block + toast) | 🟥 | Composer | P1 | 0.5 j | — |
| T12 | `P14_POS_PERF_PERCEIVED` | Skeleton + optimistic add + debounce + virtual scroll catégories | 🟥 | Composer | P2 | 0.5 j | — |
| T13 | `P14_KDS_STATION_FILTER_SOUND` | Filtres station + alerte sonore + regroupement table | 🟨 | Composer | P1 | 0.5 j | — |
| T14 | `P14_KDS_BUMP_RECALL_LIFECYCLE` | Bump bar + recall préparé + timer + escalation visuelle | 🟨 | Composer | P1 | 0.5 j | — |
| T15 | `P14_HARDWARE_PRINTER_ESC_POS` | Impression ESC/POS reçu + ticket cuisine + fallback browser | 🟥+🟩 | GPT-5.4 | **P0** | 1 j | — |
| T16 | `P14_HARDWARE_DRAWER_BARCODE_NFC` | Cash drawer trigger + barcode 1D/2D + NFC fidélité | 🟥+🟩 | Composer | P1 | 0.5 j | T15 |
| T17 | `P14_POS_PAYMENT_RESILIENCE` | Resilience paiement (decline retry, timeout, idempotency UX) | 🟥+🟩 | GPT-5.4 + GATE | **P0** | 1 j | C9 (gate dispatch) |
| T18 | `P14_A11Y_POS_OPERATOR` | A11y POS opérateur (focus order, ARIA, contraste service) | 🟥 | Composer | P2 | 0.5 j | — |
| T19 | `P14_POS_TABLE_FLOORPLAN` | Plan de salle minimal + assign table + transfer | 🟥 | GPT-5.4 | P1 | 1 j | — |
| T20 | `P14_DEFENSIVE_TYPE_NORMALIZATION` | Normalisation types `attrId/varId` (string⇄int drift) cross-surface | 🟪 | Composer | P1 | 0.5 j | T01 |
| T21 | `P14_POS_RECEIPT_REDESIGN` | Redesign reçu POS (largeur 80mm, multi-tender, TVA détaillée NF525) | 🟥+🟩 | Composer | P1 | 0.5 j | T15 |
| T22 | `P14_E2E_TACOS_4_VIANDES_FULL_FLOW` | E2E Playwright "tacos 4 viandes mix → POS → KDS → ready → drawer → reçu" | 🟪 | Composer | **P0** | 1 j | T01-T17 |

**Effort total** : ~16 jours-développeur (parallélisable). **Cycles GPT-5.4** : 6 (T01, T02, T05, T07, T15, T17, T19) dont **3 nécessitent un gate humain** (T01, T05, T17).

---

## 2. Phasage recommandé (4 vagues)

### Vague A — Fondation backend (2-3 jours, GPT-5.4 + gate)
- **T01** schéma multi-qty + migration
- **T05** SSOT pricing extension
- **T07** OrderItem snapshot
→ **Bloque tout le reste**. Une fois validé : la donnée et le calcul prix supportent le multi-choix.

### Vague B — Correction massive POS (3-4 jours, parallélisable)
- **T02** UI POS multi-qté (GPT-5.4)
- **T04** fixtures repair (Composer)
- **T06** Form Request validation (Composer)
- **T20** type normalization (Composer)
→ Le POS gère réellement le multi-choix.

### Vague C — Finalisation caisse opérateur (4-5 jours, parallélisable)
- **T08** park/hold/recall
- **T09** discount/void per-line
- **T10** recherche/barcode
- **T11** availability live guard
- **T15** imprimante ESC/POS (GPT-5.4)
- **T17** payment resilience (GPT-5.4 + gate)
- **T19** plan de salle (GPT-5.4)
- **T21** receipt redesign
→ Caisse opérationnelle production.

### Vague D — KDS + cross-surface + tests E2E (3-4 jours)
- **T13** filtres station KDS
- **T14** bump/recall KDS
- **T03** parité POS↔Kiosk
- **T12** perf perçue POS
- **T16** hardware drawer/NFC
- **T18** a11y POS
- **T22** E2E parcours complet

→ **Système de caisse production-ready**.

---

## 3. Détail des 22 tâches

### T01 🟩 `P14_VARIATION_MODEL_MULTI_QTY_BACKEND` — P0 — GPT-5.4 + GATE

**Contexte** : Le modèle actuel `OrderItem.item_variations = {attrId: varId}` ne permet qu'**une variation par attribut**. Pour un tacos avec attribut "Viande" et `max=4` mixable, il faut `{attrId: {varId: count}}` ou `OrderItemVariation[]` avec `quantity`.

**Goal** : Étendre le schéma DB (`order_item_variations` table : ajouter colonne `quantity` UNSIGNED INT DEFAULT 1) + ajouter colonnes `min_select`, `max_select`, `allow_repeat` BOOL sur `item_attributes`. Migration backfill `quantity=1` pour l'existant.

**Scope files** :
- `database/migrations/2026_04_2X_extend_item_attributes_min_max_repeat.php` (CREATE)
- `database/migrations/2026_04_2X_add_quantity_to_order_item_variations.php` (CREATE)
- `app/Models/ItemAttribute.php` (EDIT — fillable + casts)
- `app/Models/OrderItemVariation.php` (EDIT — fillable + casts)
- `app/Services/OrderService.php` (EDIT — méthode `attachVariations` accepte format multi)
- `app/Services/FrontendOrderService.php` (idem symétrie)
- `app/Resources/OrderDetailsResource.php` (EDIT — sérialise `quantity`)

**Acceptance** :
- Migration up/down idempotente, backfill testé sur 1 fixture multi-variation
- Tests Feature : `OrderServiceMultiQtyVariationTest` 5 cas (1 viande, 4 mêmes, 2+2, 3+1, 1 par variation différente)
- API JSON `OrderDetailsResource` retourne `[{variation_id, quantity, name}]`
- Pas de breaking change pour les commandes legacy `quantity=1`

**Dépendances** : aucune.
**Bloque** : T02, T03, T04, T05, T06.
**Gate humain requis** : oui (zone gelée OrderService LOCK_A+B + schéma DB).

---

### T02 🟥 `P14_POS_VARIATION_MULTI_QTY_UI` — P0 — GPT-5.4

**Contexte** : `ItemComponent.vue` POS lignes 73-114 utilise `<select>` + `<radio>` single-select sur `temp.item_variations.variations[attrId] = varId`. Doit basculer en compteur multi-qté quand `attribute.max_select > 1` ou `attribute.allow_repeat = true`.

**Goal** : Réécrire le bloc variations dans `ItemComponent.vue` :
- Si `max_select === 1 && !allow_repeat` → garder radio (rétrocompatibilité)
- Si `max_select > 1` ou `allow_repeat` → afficher compteur ± par variation, badge `total / max`, désactiver `+` si `total === max`
- Inspirer du design `KioskStepViandeComponent.vue` (responsive POS, pas de touch-only)

**Scope files** :
- `resources/js/components/admin/pos/ItemComponent.vue` (EDIT — bloc variations 73-180)
- `resources/js/store/modules/posCart.js` (EDIT — mutations multi-qty)
- `resources/js/components/admin/pos/PosComponent.vue` (EDIT — affichage panier multi-qty)
- `tests/js/posVariationMultiQty.spec.js` (CREATE — 8 cas Vitest)

**Acceptance** :
- Tacos 4 viandes : peut sélectionner 4×Steak, 2+2, 3+1, 1×Steak+1×Poulet+1×Cordon+1×Nuggets, etc.
- Validation bouton "Ajouter au panier" disabled si `total < min_select`
- Récap panier affiche "Tacos 4 viandes : 3× Steak + 1× Poulet"
- Modification depuis le panier conserve les quantités
- Tests Vitest 8/8 pass

**Dépendances** : T01 (schéma backend prêt).
**Gate humain requis** : non (UI uniquement, pas de zone gelée).

---

### T03 🟪 `P14_POS_KIOSK_VARIATION_PARITY_TESTS` — P1 — Composer

**Goal** : Tests Vitest qui chargent les 6 produits multi-choix actuels (tacos 2/3/4, burger sauce multi, dessert mix) et vérifient que **POS et Kiosk produisent le même payload backend** pour la même sélection logique (3 Steak + 1 Poulet → mêmes 4 OrderItemVariation rows).

**Scope** :
- `tests/js/posKioskVariationParity.spec.js` (CREATE)
- Fixture JSON 6 produits dans `tests/js/__fixtures__/multiVariationItems.json`

**Acceptance** : 6 produits × 3 sélections types = 18 tests, 18/18 pass.

---

### T04 🟩 `P14_VARIATION_FIXTURES_DATA_REPAIR` — P1 — Composer

**Goal** : Audit DB des produits existants où `attribute.name` matche /viande|sauce|garniture|topping|extra/i et `max_select=1` alors que le métier veut `max_select>1`. Fournir un seeder `RepairMultiVariationFixturesSeeder` qui :
1. Liste les attributs candidats (CSV)
2. Demande validation human-in-the-loop (mode `--dry-run` par défaut)
3. Met à jour `min_select / max_select / allow_repeat` selon une politique fournie en JSON

**Scope** :
- `database/seeders/RepairMultiVariationFixturesSeeder.php` (CREATE)
- `database/seeders/_data/multi_variation_policy.json` (CREATE — déclaratif)
- `reports/data-repair/MULTI_VARIATION_AUDIT_2026-04-2X.md` (sortie audit dry-run)

**Acceptance** : `--dry-run` produit un CSV des 50 plus probables candidats à `max_select>1` ; aucune modif DB par défaut.

---

### T05 🟩 `P14_PRICING_SSOT_MULTI_QTY` — P0 — GPT-5.4 + GATE

**Contexte** : `PricingService` (SSOT) calcule actuellement `sum(variation.price for varId in variations)`. Avec multi-qty, devient `sum(variation.price * count for (varId, count) in variations)`. Risque NF525 si mal fait.

**Goal** : Étendre `PricingService::computeItemTotal()` pour supporter le format multi-qty, **sans casser** le format legacy. Ajouter sentinel test que le pricing du format multi == N × pricing du format simple répété.

**Scope** :
- `app/Services/PricingService.php` (EDIT — méthode `computeItemTotal`)
- `tests/Feature/PricingMultiQtyTest.php` (CREATE — 12 cas)
- `docs/known-issues/KI_001_DISPATCH_AFTER_COMMIT_2026-04-20.md` (mention si dispatch impacté)

**Acceptance** :
- 12/12 tests pass : (1) backward compat legacy, (2-7) variations multi-qty avec prix variés, (8-12) edge cases (qty=0, qty=max, attribute_price=0)
- Pas de divergence vs frontend `kioskPricing.js` (déjà multi-qty pour Kiosk)
- `tests/Feature/PosPricingSsotProofTest.php` (V4 #7) toujours vert

**Dépendances** : T01.
**Gate humain requis** : oui (pricing = invariant SSOT critique).

---

### T06 🟩 `P14_VARIATION_VALIDATION_BACKEND` — P1 — Composer

**Goal** : Form Request `OrderStoreRequest` (et `PosOrderStoreRequest`, `FrontendOrderStoreRequest`) valide :
- `sum(variations[attrId][*].quantity) >= attribute.min_select`
- `sum(variations[attrId][*].quantity) <= attribute.max_select`
- Si `!attribute.allow_repeat` : chaque `quantity === 1` (mode N variations distinctes)

Erreur 422 structurée : `{ "errors": { "variations.123": ["Sélectionnez entre 2 et 4 viandes (actuel : 1)"] } }`.

**Scope** :
- `app/Http/Requests/Admin/OrderStoreRequest.php` (ou équivalent)
- `app/Http/Requests/Frontend/OrderStoreRequest.php`
- `app/Rules/MultiVariationConstraint.php` (CREATE — règle dédiée)
- Tests Feature `MultiVariationValidationTest` 8 cas

**Acceptance** : tests pass, erreurs 422 lisibles, FR/EN/AR.

---

### T07 🟩 `P14_ORDER_ITEM_SNAPSHOT_HARDENING` — P0 — GPT-5.4 + GATE

**Contexte** : Quand un opérateur déclenche RETURNED 3 mois après, on doit pouvoir reconstruire **exactement** la composition à T0 (audit fiscal NF525). Si une variation a été renommée / un prix a bougé / une variation supprimée, l'audit ment.

**Goal** : `OrderItem` doit persister un snapshot JSON immutable `composition_snapshot` au moment de la création :
```json
{
  "item_name": "Tacos 4 viandes",
  "variations": [
    {"attribute_name": "Viande", "variation_name": "Steak", "quantity": 3, "unit_price": 0},
    {"attribute_name": "Viande", "variation_name": "Poulet", "quantity": 1, "unit_price": 0}
  ],
  "extras": [...],
  "captured_at": "2026-04-20T12:34:56Z",
  "schema_version": 1
}
```

**Scope** :
- Migration `add_composition_snapshot_to_order_items.php`
- `app/Services/OrderService.php` méthode `snapshotOrderItem`
- `app/Services/FrontendOrderService.php` (parité)
- Tests Feature `OrderItemSnapshotTest` (renommer variation après order → snapshot intact)

**Acceptance** : snapshot immutable, schema versionné, tests RETURNED affichent le snapshot original même après modif catalogue.

**Dépendances** : T01.
**Gate humain requis** : oui (NF525 + zone OrderService).

---

### T08 🟥 `P14_POS_PARK_HOLD_RECALL` — P1 — GPT-5.4

**Goal** : Caisse pro doit pouvoir **mettre une commande de côté** (parking) sans la finaliser, gérer plusieurs commandes en parallèle (sales floor multi-clients), recall instantané.

**UX** :
- Bouton "Park" dans la barre POS → snapshot panier en `pos_parked_orders` (par opérateur + branche)
- Liste latérale des commandes parkées (table # / nom client / heure / total)
- Click sur une parked → restore panier + supprime de la liste
- Auto-park sur logout / inactivity 5 min

**Scope** :
- Migration `pos_parked_orders` (id, branch_id, user_id, payload_json, created_at)
- `app/Services/PosParkedOrderService.php` (CREATE)
- `resources/js/components/admin/pos/ParkedOrdersComponent.vue` (CREATE)
- `resources/js/store/modules/posParked.js` (CREATE)
- Intégration dans `PosComponent.vue`

**Acceptance** : park 5 commandes en parallèle, restore l'une, vérifier intégrité variations + extras + customer.

---

### T09 🟥 `P14_POS_LINE_DISCOUNT_VOID` — P1 — Composer + GATE

**Goal** :
- Discount par ligne (€ ou %) avec raison obligatoire (dropdown + free text)
- Void per-line avec raison + audit log NF525
- Reason enum : `'customer_complaint'`, `'staff_meal'`, `'damaged'`, `'other'`

**Scope** :
- Migration `add_discount_to_order_items` + `add_voided_at_voided_reason_to_order_items`
- `app/Services/OrderService.php` méthodes `applyLineDiscount`, `voidLineItem`
- AuditLog NF525 obligatoire pour chaque action
- UI POS : modal "Discount" + modal "Void"

**Gate humain requis** : oui (NF525 audit + OrderService).

---

### T10 🟥 `P14_POS_SEARCH_BARCODE_FAST` — P1 — Composer

**Goal** : Barre de recherche instantanée (debounce 100ms, fuzzy) + intégration scanner barcode (HID keyboard mode) + raccourcis clavier (F1-F12 = catégories favorites).

**Scope** :
- `resources/js/components/admin/pos/SearchBarComponent.vue` (CREATE)
- `resources/js/helpers/posBarcode.js` (CREATE — buffer caractères rapides puis enter = barcode)
- `app/Models/Item.php` ajouter `barcode` index
- Migration `add_barcode_index_to_items`

---

### T11 🟥 `P14_POS_AVAILABILITY_LIVE_GUARD` — P1 — Composer

**Contexte** : Si un item est 86'd (épuisé) pendant qu'un opérateur le tape au POS, **rien ne l'avertit**. Le V1 a couvert le pruning panier, mais pas le **gel saisie en cours**.

**Goal** : Souscrire le POS à `ItemAvailabilityChanged` (Pusher). Si l'item ouvert dans le modal devient indispo : freeze le bouton "Add to cart" + toast rouge + suggestion alternative (variations restantes).

**Scope** :
- `resources/js/components/admin/pos/ItemComponent.vue` (EDIT — listener Echo)
- `resources/js/store/modules/posCart.js` (EDIT — état `frozenItemId`)

---

### T12 🟥 `P14_POS_PERF_PERCEIVED` — P2 — Composer

**Goal** : Skeleton lors du chargement catégories/items, optimistic add-to-cart (UI réagit avant API), debounce input recherche, virtual scroll grilles >100 items.

**Scope** :
- `resources/js/components/admin/pos/SkeletonGrid.vue` (CREATE)
- `resources/js/store/modules/posCart.js` (EDIT — optimistic + rollback)
- Lib : `vue-virtual-scroller` (déjà installé, vérifier)

---

### T13 🟨 `P14_KDS_STATION_FILTER_SOUND` — P1 — Composer

**Goal** :
- Filtre dropdown : "Bar / Cuisine chaude / Cuisine froide / Tous" (config `kds_station` par item)
- Alerte sonore configurable (volume slider, on/off, son personnalisable) sur new order
- Regroupement par table (collapse / expand)

**Scope** :
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (EDIT)
- `app/Models/Item.php` ajouter `kds_station` enum
- localStorage : préférences station de la machine

---

### T14 🟨 `P14_KDS_BUMP_RECALL_LIFECYCLE` — P1 — Composer

**Goal** :
- Bump bar : flèche → → marque ready (au lieu de menu déroulant)
- Recall (annule un bump par erreur) avec timer de grâce 60s
- Timer per-order (couleurs : <5min vert, 5-10min orange, >10min rouge clignotant)
- Escalation visuelle automatique

**Scope** :
- KDS component + `kds.js` store

---

### T15 🟥+🟩 `P14_HARDWARE_PRINTER_ESC_POS` — P0 — GPT-5.4

**Contexte** : Caisse réelle = imprimante thermique 80mm ESC/POS sur réseau ou USB. Sans ça : pas de production. Fallback : print HTML browser.

**Goal** :
- Service `PrinterService` qui parle ESC/POS via TCP socket (port 9100) ou WebUSB
- Templates : reçu client (logo + lignes + total + TVA NF525 + footer), ticket cuisine (par station), ticket parking
- Configuration imprimante par branche (`printers` table : id, branch_id, type, ip, port, station)
- Fallback browser print si imprimante injoignable + alerte ops
- Test page d'impression

**Scope** :
- `app/Services/Hardware/EscPosPrinterService.php` (CREATE)
- Migration `printers` table
- `resources/js/services/posPrinter.js` (CREATE — gère socket WebUSB ou requête backend)
- `app/Http/Controllers/Admin/PrinterController.php` (config UI)

**Gate humain requis** : non (zone neuve, pas de frozen). Mais critique → review carefully.

---

### T16 🟥+🟩 `P14_HARDWARE_DRAWER_BARCODE_NFC` — P1 — Composer

**Goal** :
- Cash drawer : trigger ouverture via imprimante (commande ESC/POS `\x1B\x70`) à la fin d'un paiement cash
- Barcode 1D/2D : déjà couvert T10 (scanner HID), confirmer ergonomie
- NFC fidélité : Web NFC API (Chrome Android) lit UID carte → recherche customer

**Scope** :
- `EscPosPrinterService::openDrawer()` (EDIT T15)
- `resources/js/services/posNfc.js` (CREATE — Web NFC reader)
- `app/Http/Controllers/Admin/CustomerLookupController.php`

**Dépendances** : T15.

---

### T17 🟥+🟩 `P14_POS_PAYMENT_RESILIENCE` — P0 — GPT-5.4 + GATE

**Contexte** : Si paiement CB échoue (decline) ou timeout, l'opérateur doit pouvoir retry sans créer 2 transactions DB. Lié au gate C9 (idempotency).

**Goal** :
- UI paiement : état loading clair, bouton retry, switch tender (CB → Cash), partial payment
- Backend : tous les `PaymentService::charge()` derrière `Idempotency-Key` (header) avec persistance résultat 24h
- Decline → log structuré + audit + UX guide
- Timeout 30s → "Vérifier sur le terminal" (pas d'auto-retry)

**Scope** :
- `app/Services/PaymentService.php` (refactor avec idempotency)
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` (CREATE — couvert F-VERIFY-09-02)
- `resources/js/components/admin/pos/PaymentComponent.vue` (EDIT — UX retry)
- Tests Feature `PaymentIdempotencyRaceTest` (CREATE — couvre F-VERIFY-16-03)

**Gate humain requis** : oui (PaymentService LOCK + idempotency = invariant).
**Dépendance** : C9 fermé (dispatch-after-commit) avant cette tâche, sinon double-broadcast risque.

---

### T18 🟥 `P14_A11Y_POS_OPERATOR` — P2 — Composer

**Goal** :
- Focus order keyboard logique (Tab → catégories → items → quantité → add → total → pay)
- ARIA labels sur boutons numpad, modals, drawer
- Contraste service : mode "high contrast" pour éclairage cuisine
- Live regions pour annonces (`aria-live="assertive"` sur erreurs)

**Scope** : audit `PosComponent.vue` + composants enfants, ajouts ciblés.

---

### T19 🟥 `P14_POS_TABLE_FLOORPLAN` — P1 — GPT-5.4

**Goal** : Plan de salle minimal :
- Page `/admin/pos/floorplan` avec grille de tables (status libre/occupée/réservée)
- Click table → assign commande en cours OU ouvrir commande existante
- Transfer table (→ table B) avec audit log
- Configuration tables par branche (admin)

**Scope** :
- Migration `tables` + `add_table_id_to_orders`
- `app/Services/TableService.php`
- `resources/js/components/admin/pos/FloorplanComponent.vue` (CREATE)

---

### T20 🟪 `P14_DEFENSIVE_TYPE_NORMALIZATION` — P1 — Composer

**Contexte** : Audits successifs ont vu `attrId` arriver tantôt en `'42'` (string DOM) tantôt `42` (int DB) tantôt `'42'` (JSON). Comparaisons strictes échouent silencieusement → bugs invisibles.

**Goal** : Helper `posNormalizeIds(payload)` qui force `Number()` sur attrId/varId/itemId/branchId à tous les points d'entrée. Tests cas limites : NaN, '', null, '0', '042'.

**Scope** :
- `resources/js/helpers/posNormalizeIds.js` (CREATE)
- Appel dans `posCart.js`, `ItemComponent.vue`, `PaymentComponent.vue`
- Tests Vitest

---

### T21 🟥+🟩 `P14_POS_RECEIPT_REDESIGN` — P1 — Composer

**Goal** : Reçu POS conforme NF525 :
- Largeur 80mm + 58mm
- En-tête : SIRET, TVA intra, adresse, n° caisse, opérateur
- Lignes détaillées : nom + qty × PU + total HT + TVA détaillée par taux
- Multi-tender : "Cash 10€ + CB 15€ + TR 5€" + change
- Footer : URL self-care, mentions légales, n° ticket NF525, hash chaîne audit

**Scope** : `app/Services/Receipt/ReceiptBuilder.php` (refactor) + templates Blade ou ESC/POS string builder.

**Dépendances** : T15 (imprimante).

---

### T22 🟪 `P14_E2E_TACOS_4_VIANDES_FULL_FLOW` — P0 — Composer

**Goal** : Test E2E Playwright complet :
1. Login POS opérateur
2. Sélectionne tacos 4 viandes
3. Configure 3× Steak + 1× Poulet + 2 sauces + frites
4. Add to cart, vérifie ligne panier "Tacos 4 viandes : 3× Steak, 1× Poulet"
5. Park la commande (T08)
6. Recall, ajoute 1 boisson
7. Paie 50% cash + 50% CB (T17)
8. Vérifie reçu imprimé contient les 4 viandes (T21)
9. Vérifie ticket cuisine apparaît sur KDS station Cuisine chaude (T13)
10. KDS bump → ready (T14)
11. Vérifie OrderItem snapshot DB contient bien `[3×Steak, 1×Poulet]` (T07)
12. RETURNED 1 ligne (T09) → verify audit log NF525

**Scope** :
- `tests/e2e/full-flow-tacos-4-viandes.spec.js` (CREATE)
- Fixtures DB seed `TacosE2ESeeder`

**Acceptance** : test vert en CI (avec fallback offline pour imprimante en CI).

**Dépendances** : T01 → T17 livrés.

---

## 4. Critères de succès post-vague

### Post-Vague A (T01+T05+T07)
- ✅ DB supporte multi-qty + snapshot immutable
- ✅ Pricing SSOT calcule juste pour multi-qty
- ✅ Aucune régression legacy (commandes existantes lisibles)

### Post-Vague B (T02+T04+T06+T20)
- ✅ POS gère le tacos 4 viandes mix end-to-end (UI → DB)
- ✅ Form Request rejette les payloads invalides avec message clair
- ✅ Tests parité POS↔Kiosk verts

### Post-Vague C (T08-T11+T15+T17+T19+T21)
- ✅ Caisse pro complète : park, discount, search, barcode, payment retry, plan salle, reçu NF525
- ✅ Imprimante ESC/POS opérationnelle avec fallback

### Post-Vague D (T03+T12-T14+T16+T18+T22)
- ✅ KDS pro : filtres, son, bump, recall, escalation
- ✅ Hardware périphériques connectés
- ✅ A11y conforme
- ✅ Test E2E complet vert

---

## 5. Gates humains à approuver pour cette campagne

| Gate | Cycles concernés | Justification |
|---|---|---|
| **G14-A** (schéma + pricing + snapshot) | T01, T05, T07 | NF525 + zones gelées OrderService LOCK_A+B + invariant SSOT pricing |
| **G14-B** (payment + line discount/void) | T09, T17 | NF525 audit + PaymentService LOCK + IdempotencyKey middleware |

→ **2 gates** consolidés possibles dans `docs/gates/GATE_P14_FINALISATION_CAISSE_2026-04-20.md` (à créer si vous validez le plan).

---

## 6. Hors scope explicite

- Vente en ligne (delivery / pickup côté web public) — déjà couvert par cycles antérieurs
- App mobile dédiée — backlog produit
- Multi-devise — backlog SaaS
- Inventaire / stock multi-niveau — couvert par availability + R-RES-07 KDS group
- Datadog / Sentry backend — R-RES-05 backlog ops
- Migration Vite — R-RES-04 backlog tooling

---

## 7. Lien avec gates en attente (V1→V11)

| Gate ouvert V1→V11 | Impact sur ce plan |
|---|---|
| **C9** dispatch-after-commit | **Bloque T17** payment resilience (sinon double-broadcast au retry). À fermer en priorité. |
| **C1-C8** zones gelées P0 | T01, T05, T07, T09, T17 toucheront ces zones → consolider avec G14-A et G14-B en un seul gate géant si plus efficace |

---

## 8. Métriques cible production-ready POS

| Métrique | Cible | Mesuré par |
|---|---|---|
| Temps moyen prise commande tacos | ≤ 30 s | T22 + chronomètre dev |
| Temps "add to cart" perceived | ≤ 100 ms | T12 + RUM |
| Temps impression reçu | ≤ 2 s | T15 logs |
| Précision pricing multi-qty | 100 % | T05 sentinels |
| Couverture E2E parcours réels | ≥ 5 scénarios | T22 + extensions |
| Aucun ghost order POS | 0 | C9 + T17 |

---

## 9. Risques et mitigations

| Risque | Probabilité | Mitigation |
|---|---|---|
| T01 migration casse données existantes | Moyenne | Migration backfill testée + rollback documenté + dry-run staging |
| T15 ESC/POS non testable en CI | Haute | Mock printer + integration test manuel obligatoire pré-merge |
| T17 idempotency mal implémenté → bugs subtils | Moyenne | Sentinel test `PaymentIdempotencyRaceTest` + revue gate |
| T02 UI POS plus lente avec multi-qty | Faible | T12 perf perçue couvre |
| Drift POS↔Kiosk pendant les vagues | Moyenne | T03 tests parité dès vague A pour figer le contrat |

---

## 10. Prochaines étapes opérationnelles

1. **Lire ce plan + valider scope/priorités** (humain)
2. **Approuver gates G14-A + G14-B** (humain) → débloque vague A
3. **Lancer vague A en parallèle** (T01, T05, T07 — 3 subagents GPT-5.4)
4. Audit + close vague A → lancer vague B
5. Idem C, D
6. **Audit final** + RUN E2E T22 → handoff prod

---

*Fin du PLAN_FINALISATION_POS_BASE_2026-04-20.*

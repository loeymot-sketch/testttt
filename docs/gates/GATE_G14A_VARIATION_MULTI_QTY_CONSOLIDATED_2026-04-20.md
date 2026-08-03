# GATE G14-A — Variation Multi-Quantity Backend (T01 + T05 + T07) — Consolidated

**Format**: human-gates.mdc
**Date**: 2026-04-20
**Gate ID**: `G14-A`
**Wave**: A — Backend Foundation (PLAN_FINALISATION_POS_BASE_2026-04-20.md)
**Status**: PENDING_HUMAN_APPROVAL
**Owner request**: orchestrator (Claude)

---

## 1) Trigger

3 cycles GPT-5.4 critiques sont prêts à exécuter (T01 + T05 + T07). Ils touchent **3 zones gelées** simultanément :

- **OrderService LOCK_B (POS-9.4.BL + POS-9.2 + POS-9.3)** → still ACTIVE
- **PricingService SSOT** (`app/Services/Pricing/PricingService.php` — `// IMMUTABLE CONTRACT`)
- **NF525 ticket reprint immutability** (snapshot composition pour reprint identique à T0)

Conformément à `.cursor/rules/human-gates.mdc` §"Frozen zones / OrderService / PricingService SSOT" + `auto-remediation.mdc` §"Critical zones override" → **HUMAN_GATE obligatoire avant tout EXECUTE**.

---

## 2) User-reported Bug (root cause)

> "lors de choisir un tacos 2 ou 3 ou 4 viandes j'ai le droit de choisir qu'une viande !
>  alors je peut choisir une ou miste et 3 de un et un de l'autre"

**Diagnostic recon-confirmé** :

- **POS** (`resources/js/components/admin/pos/ItemComponent.vue` L82-112, L520) : `<select>` + `<input type="radio">` v-model `temp.item_variations.variations[attributeId] = variationId` → **single-select strict** par attribut. Aucun moyen UI d'exprimer "3× Steak + 1× Poulet".
- **Kiosk** (`KioskWizardComponent.vue` L950-960) : 1 viande "principale" ⇒ variation, viandes additionnelles ⇒ **hack `normalizedExtras` (1 extra/unité)**. Workaround fonctionnel mais sémantiquement incorrect (extras payants ≠ variations) + impossible de mixer si l'attribut n'a pas d'extras correspondants.
- **Backend** (`OrderService.php` L378, 683, 1085 + `FrontendOrderService.php` L293 + `PricingService.php` L97) : itère `foreach ($item->item_variations as $variation)` et fait `$variationTotal += $dbVar->price` **sans notion de quantity**. Le format JSON persisté (`order_items.item_variations` LONGTEXT) accepte un array `[{id, name, ...}]` — pas de `quantity` field.
- **`item_attributes`** : aucune contrainte `min_select` / `max_select` / `allow_repeat` côté DB ni côté admin form.

**Conséquence métier** : un tacos "4 viandes mixables" n'est **pas exprimable** dans le modèle actuel ni en POS ni en backend strict. Bug architectural P0.

---

## 3) Affected Subsystems

### Frozen zones touchées

| Fichier | LOCK | Modif prévue |
|---|---|---|
| `app/Services/OrderService.php` | LOCK_B POS-9.4.BL+9.2+9.3 (ACTIVE) | T05 — extension boucle pricing (multiplier par `quantity ?? 1`) sur **3 sites** L378-388, L683-693, L1085-1095. **Aucune nouvelle règle métier**, dérogation §LOCK_B.règles : "wire-in / refacto / propagation". |
| `app/Services/FrontendOrderService.php` | LOCK_B (Track A frozen) | T05 — symétrie 1 site L293-303. |
| `app/Services/Pricing/PricingService.php` | SSOT IMMUTABLE | T05 — extension boucle L97-117 + L120-141. **Backward-compat strict** : `quantity ?? 1` ⇒ comportement actuel inchangé pour payloads legacy. |
| `database/migrations/*` | (nouveau) | T01 + T07 — 3 ALTER ADD COLUMN nullables (zéro perte). |
| `app/Models/ItemAttribute.php` | (modèle) | T01 — fillable + casts. |
| `app/Models/OrderItem.php` | (modèle) | T07 — fillable + cast `composition_snapshot` JSON. |
| `app/Http/Resources/OrderItemResource.php` | (transformer) | T07 — fallback `composition_snapshot ?? item_variations`. |

### Hors-frozen (libre)

- Tests Feature/Unit nouveaux
- Migrations (additives only, nullable, idempotent)
- Documentation `docs/PRICING_SSOT.md` + `docs/known-issues/KI_003_*` si nécessaire

### OFF-LIMITS (interdits cette vague)

- `resources/js/components/admin/pos/ItemComponent.vue` (UI POS — sera vague B / T03)
- `resources/js/components/frontend/kiosk/**` (UI Kiosk — sera vague B / T04)
- `app/Http/Requests/**Order**` (validation form requests — sera T02)
- Schéma `payments`, `transactions`, `dining_tables` (hors scope vague A)
- `OrderStateMachine`, `OrderPaymentStateMachine` (hors scope)
- Toute logique de `branch_id` / d'autorisation (hors scope)

---

## 4) Invariants at Risk

### À PROTÉGER (test obligatoire)

1. **Backward-compat lecture legacy** : un `OrderItem` créé avant cette vague (`item_variations = [{id, name}]`, sans `quantity`, sans `composition_snapshot`) doit continuer à être :
   - Recalculable à l'identique par `PricingService` (régression check sur `tests/Feature/PricingIntegrityTest.php`)
   - Affichable identiquement par `OrderItemResource` (test sérialisation snapshot ancien)
   - Reprintable identiquement (NF525 — snapshot manquant ⇒ fallback `item_variations`)

2. **Backward-compat écriture legacy** : un payload POS actuel `[{id, item_id, item_attribute_id, name}]` doit continuer à produire EXACTEMENT le même `total_price` qu'avant (quantity implicite = 1). Test bit-identique sur fixture POS V1.

3. **SSOT pricing** : tout `total_price` reste calculé par `PricingService` ou la logique mirror dans `OrderService`. Aucun calcul dupliqué ni shortcut.

4. **NF525 — snapshot immutable** : une commande clôturée + Z scellé ne doit JAMAIS voir son `composition_snapshot` muter. T07 doit garantir snapshot écrit **dans la même transaction** que `order_items.insert`, et n'est jamais ré-écrit par les jobs/listeners aval.

5. **Cross-item guards** : `enforceCrossItemGuards` continue à rejeter une variation/extra n'appartenant pas à l'item parent (test régression `PricingServiceTest`).

6. **Idempotency `X-Idempotency-Key`** : la création d'un même OrderItem multi-qty avec même payload + même clé doit retourner la même réponse / le même order id (pas de doublons).

7. **Branch isolation** : `branch_id` reste dérivé de `$user` ou `$order` côté serveur. Aucun changement.

8. **Dispatch-after-commit (KI-001)** : aucune nouvelle dispatch d'event hors `DB::afterCommit`. Sentinel `tests/Feature/DispatchAfterCommitTest.php` doit rester vert (ou n'introduire aucune nouvelle violation).

### À NE PAS RÉGRESSER (suite verte)

- `tests/Feature/PricingIntegrityTest.php`
- `tests/Feature/Services/Pricing/PricingServiceTest.php`
- `tests/Feature/PosOrderStoreTest.php` (si existe)
- `tests/Feature/FrontendOrderServiceTest.php` (si existe)
- `tests/Feature/DispatchAfterCommitTest.php` (sentinel V4 #8)
- `scripts/check-invariants.sh` (6 invariants 4/6 multilignes V9)

---

## 5) Decision Required

**Approuver ou rejeter** l'exécution consolidée des 3 cycles T01 + T05 + T07 selon la **stratégie technique unique** définie ci-dessous :

### Stratégie retenue : extension JSON backward-compat + colonnes additives nullables

**A. Schéma DB (T01 + T07)** — 3 migrations additives, nullable, idempotentes, no data move :

1. `2026_04_2X_add_min_max_repeat_to_item_attributes.php` :
   - ADD `min_select` UNSIGNED INT DEFAULT 0 (signifie "optionnel" si 0)
   - ADD `max_select` UNSIGNED INT DEFAULT 1 (signifie "single-select" si 1, multi sinon)
   - ADD `allow_repeat` BOOLEAN DEFAULT FALSE (true ⇒ même variation comptable plusieurs fois)
   - Backfill aucun (defaults rétro-compatibles avec UI single-select existante)

2. `2026_04_2X_add_composition_snapshot_to_order_items.php` :
   - ADD `composition_snapshot` JSON NULLABLE (NULL ⇒ legacy ⇒ fallback `item_variations`)
   - Aucun backfill (lecture legacy fonctionne sans)

3. (Pas de 3e migration — `order_items.item_variations` reste LONGTEXT JSON tel quel.)

**B. Format payload étendu (T05)** — backward-compat strict :

```json
// Avant (legacy, toujours accepté) :
{ "item_id": 7, "quantity": 1, "item_variations": [{ "id": 42, "item_id": 7, "item_attribute_id": 3, "name": "Steak" }] }

// Après (nouveau, accepté) :
{ "item_id": 7, "quantity": 1, "item_variations": [
  { "id": 42, "item_id": 7, "item_attribute_id": 3, "name": "Steak", "quantity": 3 },
  { "id": 43, "item_id": 7, "item_attribute_id": 3, "name": "Poulet", "quantity": 1 }
]}
```

- Pricing : `$variationTotal += $dbVar->price * max(1, (int) ($variation->quantity ?? 1))`
- Validation (par `PricingService`) :
  - `max(1, quantity) > 0`
  - `sum(quantity by attributeId) BETWEEN min_select AND max_select` si `max_select > 0`
  - Si `allow_repeat == false` : refuser plusieurs occurrences DU MÊME `variation_id` (mais accepter plusieurs `variation_id` différents pour le même attribut tant que sum <= max_select)
  - Erreur HTTP 422 explicite par cas

**C. Snapshot immutable (T07)** :

À la création d'un `OrderItem`, `OrderService` + `FrontendOrderService` écrivent EN MÊME TRANSACTION :

```json
{
  "schema_version": 1,
  "captured_at": "2026-04-20T13:42:01Z",
  "lines": [
    {
      "variation_id": 42,
      "attribute_id": 3,
      "attribute_name": "Viande",
      "variation_name": "Steak",
      "quantity": 3,
      "unit_price": 1.50
    }
  ],
  "extras": [ ... idem ... ]
}
```

`OrderItemResource` lit prioritairement `composition_snapshot.lines` ; fallback sur `item_variations` legacy.

### Pourquoi pas d'alternative hors-frozen

- **Alternative 1 (table normalisée `order_item_variations`)** : aurait nécessité (a) migration de données existantes JSON → rows, (b) double-écriture transitoire, (c) refonte intégrale `OrderItemResource` + Vuex POS + helpers Kiosk, (d) risque casse de tickets reprint historiques. **Rejetée** car coût ×3 sans gain métier.

- **Alternative 2 (rester sur répétition de l'objet `[{id:42},{id:42},{id:42}]` sans champ `quantity`)** : techniquement faisable côté pricing actuel, MAIS (a) explose la taille JSON, (b) ne permet pas de validation `allow_repeat`, (c) sémantiquement obscur (3 lignes "Steak" vs 1 ligne "Steak ×3"), (d) ne prépare pas T07 snapshot. **Rejetée**.

- **Alternative 3 (modifier UI POS sans toucher backend)** : impossible, la limite est dans le pricing/persistence, pas dans l'UI.

---

## 6) Approval Requested

| Coche | Décision |
|---|---|
| ☐ APPROVED | Lancer T01 + T05 + T07 selon stratégie ci-dessus, en parallèle, 3 subagents `foodking-complex-implementer` (GPT-5.4). Audit consolidé après les 3 close. |
| ☐ APPROVED_PARTIAL | Lancer uniquement T01 (schéma + modèles), différer T05 et T07 à un second gate après revue. |
| ☐ REJECTED | Ne pas lancer. Demander reformulation / scope alternatif. |

**Validation utilisateur signalée** : message du 2026-04-20 "*je valide globalement là c'est ton tours d'utilisé ton intelligence et faire de ce plan une realité fonctionelle... attaque vague 1*" — interprétée comme **APPROVED** sous réserve d'absence de désaccord explicite dans la prochaine réponse.

---

## 7) Rollback / Safety Net

- Toutes les ALTER sont nullable additives ⇒ **rollback = `php artisan migrate:rollback --step=2`** sans perte.
- Code legacy lecture (sans `quantity`, sans `composition_snapshot`) reste opérationnel grâce aux fallback ⇒ **toggle off** = ne pas envoyer `quantity` côté frontend, le système se comporte exactement comme avant.
- Tests régression bit-identiques sur fixtures POS V1 ⇒ détection immédiate de toute drift.

---

## 8) Acceptance Globale (G14-A close criteria)

- ☐ T01 CLOSED — schéma + modèles, migration up/down testée
- ☐ T05 CLOSED — pricing multi-qty, 5+ tests Feature couvrant : 1 viande, 4 mêmes viandes (allow_repeat), 2+2 mixé, 3+1 mixé, 1+1+1+1 (4 différentes), violation `max_select`, violation `min_select`, violation `allow_repeat=false` avec doublons
- ☐ T07 CLOSED — snapshot immutable, test "rename variation après commande, ticket reprint inchangé"
- ☐ Régression : `tests/Feature/PricingIntegrityTest.php` + `PricingServiceTest.php` 100 % verts inchangés
- ☐ Régression : `tests/Feature/DispatchAfterCommitTest.php` vert
- ☐ `scripts/check-invariants.sh` 6/6 verts inchangés
- ☐ Audit final : aucun nouveau LOCK posé, aucune nouvelle frozen zone.

---

## 9) Référence

- Plan source : `plans/PLAN_FINALISATION_POS_BASE_2026-04-20.md` (T01, T05, T07)
- Audit global précédent : `reports/execution/AUDIT_GLOBAL_V1_V11_2026-04-20.md`
- Active LOCK : `tasks/phase9-sync/LOCK_B_POS_9_2_3_OrderService_2026-04-18.md`
- ACTIVE_CYCLE : `.cursor/ACTIVE_CYCLE.md` (TASK_ID = `PLAN_P14_FINALISATION_POS_BASE_2026-04-20`)

---

## 10) Signature

- **Orchestrator** : Claude — 2026-04-20
- **Human approver** : ___________________ — date : __________
- **Decision** : APPROVED / APPROVED_PARTIAL / REJECTED

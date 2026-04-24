# BINDING_MAP_POS_V4 — squelette W0

**Cycle** : POS_V4_IMPL_EXEC_FINAL_2026-04-26  
**Phase** : W0 (livraison W0-B)  
**Auteur** : Claude terminal (lecture seule, aucune édition de SFC)  
**Date** : 2026-04-26  
**Statut** : **DRAFT — à compléter par cursor-composer / codex-terminal en W0/W1**  
**JOIN gate** : ce fichier doit avoir **toutes les colonnes "statut" remplies (≠ vide)** pour les 9 SFC avant ouverture W1.

---

## 1. Légende des colonnes

| Colonne | Définition |
|---|---|
| **SFC** | Composant Vue (chemin relatif depuis `resources/js/components/admin/pos/`) |
| **Binding** | Type d'attache (`@click`, `@submit`, `v-model`, `$emit`, `ref=`, `props.X`, `axios`, `$store`) avec ligne approximative |
| **Cible template v4** | Élément/sélecteur du design POS v4 qui doit recevoir/conserver ce binding |
| **Statut** | `KEEP` (binding conservé tel quel) / `RENAME` (selector change, logique conservée) / `WRAP` (composable interne) / `SPLIT` (à découper) / `TODO` (à analyser) |
| **Test garde** | Vitest spec ou Playwright test qui vérifie que le binding survit au merge |
| **Service appelé** | (HYPERREVIEW L18) Service backend touché : `OrderService`, `FrontendOrderService`, `axios direct`, `$store/posOrder`, `$store/posCart`, `appService`, `none` |

---

## 2. Inventaire SFC (9 réels confirmés W0-C)

| SFC | Lignes | Bindings (count grep) | Risque (HYPERREVIEW §5) |
|---|---|---|---|
| `ReceiptDuplicataMarker.vue` | 70 | 0 | 0 — display only |
| `SkeletonGrid.vue` | 19 | 0 | 0–1 — confirmé sans emit/ref |
| `ReceiptComponent.vue` | 479 | 3 | 2 — display backend-fed |
| `CreateCustomerAddressComponent.vue` | 196 | 8 | 8 — branch_id indirect |
| `ParkedOrdersComponent.vue` | 345 | 6 | 8 — list + reprise |
| `FloorplanComponent.vue` | 284 | 6 | 12 — cross-branch 422 |
| `ItemComponent.vue` | 1276 | 21 | 45 — pricing_ssot violation L734-770 |
| `PosComponent.vue` | 2404 | 57 | 45 — shell, magic int statut L1390/L1413 |
| `PaymentComponent.vue` | 313 | 20 | 36 — mute props parent L251-265 |

Total bindings (grep) : **121**. Ordre merge §5 HYPERREVIEW à respecter.

---

## 3. Squelette des bindings (à compléter par cursor-composer en W0)

### 3.1 `ReceiptDuplicataMarker.vue` — risque 0
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `props.order.receipt_print_count` (l.23) | `.fk-pos-v4 .receipt__duplicata` (à confirmer) | KEEP | Vitest snapshot `printCount=2 → DUPLICATA #1 visible` | none |

### 3.2 `SkeletonGrid.vue` — risque 0
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `props.count` (l.11) | `.fk-pos-v4 .pos-grid--loading` | KEEP | Vitest snapshot `count=12 → 12 .skeleton-tile` | none |

### 3.3 `ReceiptComponent.vue` — risque 2
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `v-for tax_lines` (à localiser) | `.fk-pos-v4 .receipt__taxes ul` | TODO | `tests/js/posReceipt.spec.js` (à créer) | axios `/admin/order/print/{id}` |
| @click impression | `.fk-pos-v4 .receipt__btn-print` | TODO | E2E print confirm | none |
| ReceiptDuplicataMarker child slot | `.fk-pos-v4 .receipt__duplicata` | KEEP | inherited 3.1 | none |

### 3.4 `CreateCustomerAddressComponent.vue` — risque 8
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `@submit form address` | `.fk-pos-v4 .address-modal form` | TODO | Vitest "ne soumet pas si branch_id manquant" (HYPERREVIEW L13) | axios `/admin/customer-address` |
| `v-model fields` (8 occurrences) | inputs `.fk-pos-v4 .address-modal__field` | TODO | snapshot field count | none |

### 3.5 `ParkedOrdersComponent.vue` — risque 8
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| @click reprise commande (l. ~150) | `.fk-pos-v4 .parked-list__item` | TODO | `tests/Feature/Pos/PosParkedOrderTest.php` (existe) | $store/posOrder + axios |
| `formatMoney(order.preview_total)` | `.fk-pos-v4 .parked-list__total` (display backend) | KEEP | snapshot | $store/posCart |
| branch_id filter (l.72 commenté) | aucun, scope backend | TODO documenter | PHPUnit branch isolation | axios `/admin/pos/parked-orders?branch_id=` |

### 3.6 `FloorplanComponent.vue` — risque 12
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| @click table select | `.fk-pos-v4 .floorplan__table` | TODO | `tests/js/posFloorplan.spec.js` (existe — HYPERREVIEW L17) | $store/posOrder |
| 422 cross-branch (l.94 commenté) | toast `.fk-pos-v4 .toast--error` | TODO documenter | PHPUnit `FloorplanControllerTest` | axios `/admin/dining-tables` |
| @click reprise commande active | `.fk-pos-v4 .floorplan__active-order` | TODO | E2E flow | $store/posOrder |

### 3.7 `ItemComponent.vue` — risque 45 — **AUDIT BLOQUANT W0-A**
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| **`totalPriceSetup()` (l.734-770) — pricing_ssot** | `.fk-pos-v4 .item-modal__total` | **TODO** — voir `reports/audit/W0_PRICING_SSOT_ITEMCOMPONENT_DECISION.md` | garde CI `pos:lint:pricing` (D1) | none (calc local — à conditionner) |
| `bumpPricingToCatalog()` (l.~795) | re-fetch convert_price | KEEP | Vitest "bump rebascule prix catalog" | axios `/admin/items/{id}/pricing` |
| `changeVariation/Extra` (l.731+) | `.fk-pos-v4 .item-modal__variations` | TODO | snapshot variations | none |
| 21 bindings totaux | à cartographier exhaustivement | TODO | matrice `posItem.spec.js` | mix |

### 3.8 `PosComponent.vue` — risque 45 — shell
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| **L.1390 `[4,7,8]` magic int** (filter kiosk cash) | aucun template change requis | **REFACTOR REQUIS** → `OrderStatus.ACCEPT/PREPARING/PREPARED` (cf. `app/Enums/OrderStatus.php`) | grep CI `rg "\b[4-8,13]\b.*order_status"` doit échouer | $store + axios `/admin/kds-order` |
| **L.1413 `status: 13` magic int** (collect kiosk DELIVERED) | aucun | **REFACTOR REQUIS** → constante importée `OrderStatus.DELIVERED` | idem | axios `/admin/kds-order/change-status/{id}` |
| `loadKioskCashOrders` broadcast `OrderStatusChanged` (l.1188) | `.fk-pos-v4 .kiosk-cash-tray` | KEEP | Vitest broadcast → list refresh | Echo + axios |
| `idempotency_key` (l.1822) `${Date.now()}_${random}_${branch_id||0}` | aucun | **GUARD AJOUT** → assert branch_id != null (HYPERREVIEW L14) | Vitest "idem key contient branch_id" | axios `/admin/pos/orders` |
| Header / branch_id banner (33 occurrences branch_id) | `.fk-pos-v4 .pos-header__branch` | TODO | snapshot banner | $store |
| @click 57 occurrences | divers | TODO matrice | E2E | mix |

### 3.9 `PaymentComponent.vue` — risque 36 — **dernier merge**
| Binding | Cible template v4 | Statut | Test garde | Service |
|---|---|---|---|---|
| `$store.dispatch('posOrder/save', form)` (l.240) | bouton `.fk-pos-v4 .payment__pay-btn` | KEEP | Vitest "click → 1 dispatch" + double-tap test | $store/posOrder |
| **`this.$props.props.form.subtotal/discount/total = …` (l.251-265) — mute props** | aucun | **REFACTOR REQUIS** → `$emit('payment-reset')` (HYPERREVIEW L10) | Vitest snapshot post-erreur paiement | $store action `resetCart` |
| `posPaymentMethodEnum.CASH` (l.245) | `.fk-pos-v4 .payment__method-cash` | KEEP | snapshot enum | enum local |
| `openDrawer()` (l.247) | aucun | KEEP | mock test | drawer bridge |
| `appService.modalHide('#orderpayment')` (l.266) | `.fk-pos-v4 .payment-modal[role=dialog]` | RENAME selector | Playwright "modal hidden after pay" | appService |
| 20 bindings totaux | divers | TODO | E2E payment full | mix |

---

## 4. Statut JOIN (à mettre à jour à la fin de W0)

| SFC | Bindings recensés | Statut "TODO" restants | Ready W1 ? |
|---|---|---|---|
| `ReceiptDuplicataMarker.vue` | 1/1 | 0 | OUI |
| `SkeletonGrid.vue` | 1/1 | 0 | OUI |
| `ReceiptComponent.vue` | 3/3 | 2 | NON |
| `CreateCustomerAddressComponent.vue` | 2/8 | 2 | NON |
| `ParkedOrdersComponent.vue` | 3/6 | 1 | NON |
| `FloorplanComponent.vue` | 3/6 | 3 | NON |
| `ItemComponent.vue` | 4/21 | 1 + audit W0-A pending | NON |
| `PosComponent.vue` | 5/57 | 2 refactors + bindings | NON |
| `PaymentComponent.vue` | 5/20 | 2 refactors + bindings | NON |

**JOIN gate W1** : exige 9/9 SFC à statut "OUI". Estimation cursor-composer pour compléter : **1 jour ouvré**.

---

## 5. Refactors P0 identifiés (sortie de W0)

| Refactor | Fichier:ligne | Justification | Priorité |
|---|---|---|---|
| Magic int `[4,7,8]` → `OrderStatus.*` import | `PosComponent.vue:1390` | Invariant `order_status` (HYPERREVIEW L9, ST-2) | **P0** — blocant G2 |
| Magic int `status: 13` → `OrderStatus.DELIVERED` | `PosComponent.vue:1413` | idem | **P0** — blocant G2 |
| Mutate props parent → `$emit('payment-reset')` | `PaymentComponent.vue:251-265` | Anti-pattern + risque commit_before_dispatch (HYPERREVIEW L10) | **P0** — blocant G5 |
| Garde CI `pos:lint:pricing` | `package.json` + `ItemComponent.vue` | `pricing_ssot` (W0-A décision D1) | **P0** — blocant G3 |
| Guard idempotency_key `branch_id != null` | `PosComponent.vue:1822` | branch_id integrity (HYPERREVIEW L14) | **P1** — blocant G5 |

---

## 6. Trace
- `EXECUTE_DELEGATION: claude-terminal` (squelette)
- À compléter (statuts TODO → KEEP/RENAME/REFACTOR) par : `cursor-composer` (cartographie bindings restants) + `codex-terminal` si dispo (refactors P0)
- **Aucun** SFC modifié pendant la production de ce squelette — lecture seule.
- Ingest `memory/episodes/12_decisions_log.jsonl` post W1 (entry `pos_v4_binding_map_join_complete`).

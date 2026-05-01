# Claude — MEGA AUDIT PLAN — Process complet, synchronisation, centralisation, gestion — 2026-04-27

Auteur : Claude (orchestrateur FoodKing)
Décision utilisateur : **Hardware UAT mis en hold** tant que l'audit process complet n'est pas validé.
Bug observé qui déclenche ce plan : **kiosk se bloque sur la page post-paiement (waiting/confirmation) sans retour automatique** quand le paiement est simulé (pas de TPE réel) et que le KDS ne fait pas avancer le statut.

Objectif global : prouver, documenter et stabiliser **chaque process complet** (kiosk seul, POS seul) avec preuve Playwright répétée, synchronisation cross-channel auditée, stock dynamique, queue numbers uniques, persistance/historique, centralisation et gestion dashboard.

Ce plan est exécutable mission par mission par Codex. Chaque mission produit code+tests+documentation.

---

## 0. Verdict actuel et pré-conditions

```
HARDWARE_UAT_DECISION: HOLD
REASON: process audit complet pas terminé + bug kiosk waiting/confirmation
PRÉ-CONDITION pour reprendre UAT : C0..C10 PASS
```

---

## 1. Stratégie de tests : "Run-Many" pour fiabilité

Chaque test critique est exécuté **en boucle 5×** pour détecter les flakies, les races conditions et les memory leaks. Critère : **5/5 PASS** sinon REWORK.

Pattern Playwright standard pour ce plan :

```js
for (let i = 0; i < 5; i++) {
  test(`scenario_X iteration ${i+1}/5`, async ({ browser }) => { /* … */ });
}
```

Les tests Vitest/PHPUnit critiques se relancent via `--repeat-each=5` (Playwright) ou wrapper PHPUnit.

---

## 2. Liste des missions (C0 → C10)

```
C0  P0 hotfix    — Kiosk waiting/confirmation auto-return après paiement simulé
C1  PLAYWRIGHT   — Process complet KIOSK (5 scenarios × 5 itérations)
C2  PLAYWRIGHT   — Process complet POS / CAISSE (5 scenarios × 5 itérations)
C3  SYNC AUDIT   — Cross-channel KIOSK ↔ POS ↔ KDS ↔ OSS realtime
C4  STOCK DYN    — Stock V2 décrément/release dynamique multi-canal
C5  QUEUE        — Queue number unique cross-channel
C6  PERSIST      — audit_logs / fiscal / outbox / composition_snapshot intégrité
C7  DOC CENTRAL  — Documentation data centralisation
C8  DOC SYNC     — Documentation storage + fluidité + sync architecture
C9  DASHBOARD    — Audit gestion produits / catégories / stock
C10 RAPPORT      — Consolidation + go/no-go hardware UAT
```

**Ordre obligatoire** : C0 immédiat → C1+C2 parallèle → C3 (consomme C1+C2 fixtures) → C4 → C5 → C6 (parallèle C3-C5) → C9 → C7+C8 → C10.

---

## 3. Mission C0 — Hotfix kiosk waiting/confirmation auto-return

### 3.1 Diagnostic

**Symptôme** : après paiement simulé (CASH ou CARD simulation), le kiosk reste sur `kiosk.waiting` sans atteindre `kiosk.confirmation`, ou reste sur `kiosk.confirmation` sans retourner à `kiosk.idle`.

**Cause racine probable**, vérifiée par lecture directe :

- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:283-285` : `markReady()` n'est appelé que si `numericStatus === STATUS_PREPARED (8)` OU `STATUS_DELIVERED (13)`.
- En paiement simulé, l'order reste en `ACCEPT (4)` ou `PENDING (1)` car aucun KDS n'avance le statut.
- → Le poll boucle indéfiniment, l'utilisateur est bloqué.

**Cause racine secondaire à confirmer** :

- `KioskConfirmationComponent.vue:66-67` montre un countdown auto_return ; vérifier que la transition `kiosk.waiting → kiosk.confirmation` se déclenche bien et que le countdown trigger `goHome()` à 0.
- Pour le path **cash-at-counter** (D-KIOSK-02), le statut backend reste `payment_status=PENDING_COUNTER`, l'order n'avance pas tant que POS n'a pas confirmé. Le kiosk doit malgré tout retourner à idle après affichage du ticket d'attente.

### 3.2 Scope C0

Mission **PRODUCT-COMPOSER-SYNC-C0-KIOSK-AUTO-RETURN-FIX** :

1. Patch `KioskWaitingComponent.vue` :
   - Ajouter une **transition forcée vers `kiosk.confirmation`** dès que la commande est confirmée backend (`order.id` existe + `payment_status` cohérent), sans attendre `STATUS_PREPARED`.
   - Différencier les 3 modes :
     - **Card simulé / Cash réel POS** : passer à `kiosk.confirmation` dès la création order.
     - **Cash-at-counter** (`payment_status=PENDING_COUNTER`) : passer à `kiosk.confirmation` avec message "À régler au comptoir – numéro X".
     - **Kitchen ready** (`STATUS_PREPARED`) : conserver l'écran "Votre commande est prête".
2. Patch `KioskConfirmationComponent.vue` :
   - Garantir que le countdown `auto_return` (par défaut 30s) déclenche `goHome()` automatiquement.
   - Garantir que `goHome()` route vers `kiosk.idle` ET reset le store kiosk-cart (cleanup).
   - Permettre override par config `kiosk.confirmation_auto_return_seconds` (default 30).
3. Pour le mode `KITCHEN_WAITING` (cas restaurant rapide où on attend que le KDS prépare), garder l'écran d'attente actuel mais ajouter un **bouton "Nouvelle commande"** toujours visible.

### 3.3 Allowlist C0

```
resources/js/components/frontend/kiosk/KioskWaitingComponent.vue
resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue
resources/js/store/modules/kioskCart.js                                # reset() au goHome
config/kiosk.php                                                       # confirmation_auto_return_seconds
resources/js/languages/fr.json                                         # message PENDING_COUNTER cohérent
resources/js/languages/en.json
tests/js/kioskWaitingAutoReturn.spec.js                                # NEW
tests/js/kioskConfirmationCountdown.spec.js                            # NEW
tests/e2e/kiosk-post-payment-auto-return.spec.js                       # NEW
missions/PRODUCT-COMPOSER-SYNC-C0-KIOSK-AUTO-RETURN-FIX/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C0-KIOSK-AUTO-RETURN-FIX.md
reports/post_execute_latest.log
```

### 3.4 Tests C0

| Test | Cas couverts |
|---|---|
| `tests/js/kioskWaitingAutoReturn.spec.js` | Order créée → status=ACCEPT → après 1s de poll, transition vers `kiosk.confirmation`. Order status=PENDING_COUNTER → transition immédiate avec message comptoir. Order status=CANCELED → redirect idle. |
| `tests/js/kioskConfirmationCountdown.spec.js` | Mount Confirmation avec total → countdown 30s → 0 → `router.push({name:'kiosk.idle'})` + store reset. Bouton "Nouvelle commande" → idem instantané. |
| `tests/e2e/kiosk-post-payment-auto-return.spec.js` | Flow complet : kiosk wizard → cart → payment CARD → submit → assert URL `/kiosk/confirmation` < 5s → assert countdown UI visible → après timer fast (override config 5s en test) → assert URL `/kiosk/idle`. **Répété 5×.** |

PASS C0 = 3 tests verts, 5/5 itérations E2E.

---

## 4. Mission C1 — Process complet KIOSK Playwright

### 4.1 Pré-condition
C0 PASS.

### 4.2 5 scénarios end-to-end kiosk

Créer `tests/e2e/kiosk-full-process/` avec 5 fichiers, chaque fichier teste un scénario × 5 itérations.

#### Scénario K1 — Kiosk simple item, paiement CARD simulé
`tests/e2e/kiosk-full-process/k1-card-simple.spec.js`

```
1. Visit /kiosk → idle screen
2. Touch screen → categories
3. Select category "Tacos"
4. Select item "Tacos M"
5. Wizard : viandes (2), sauces, suppléments (skip), boisson
6. Validate → cart
7. Continue → loyalty (skip)
8. Continue → upsell (skip)
9. Continue → payment
10. Select CARD
11. Submit (simulation success)
12. Assert URL /kiosk/confirmation
13. Assert order_number visible
14. Wait countdown
15. Assert URL /kiosk/idle
```

Assertions backend : order persisté avec `payment_status=PAID`, `fiscal_sequence_no` alloué, KDS event dispatched.

#### Scénario K2 — Kiosk menu (composition complète tacos), paiement CARD
`k2-card-composition-tacos.spec.js`

Tacos M avec 2 viandes différentes + sauces algerienne + samurai + extra fromage + boisson Coca.
Vérifier `composition_snapshot` complet en DB, `fiscal_sequence_no` alloué.

#### Scénario K3 — Kiosk paiement ESPÈCES au comptoir (cash-at-counter)
`k3-counter-deferred.spec.js`

```
1-9. Idem K1 jusqu'à payment
10. Select CASH
11. Modal "À régler au comptoir" → confirm
12. Submit → POST /api/frontend/order avec payment_method=COUNTER_DEFERRED
13. Assert URL /kiosk/confirmation
14. Assert message "À régler au comptoir – numéro X"
15. Wait countdown
16. Assert URL /kiosk/idle
17. Assert backend : order.payment_status=PENDING_COUNTER, fiscal_sequence_no=NULL
```

#### Scénario K4 — Kiosk avec rupture sur 1 choix
`k4-rupture-during-wizard.spec.js`

```
1. Pre-condition : seed item "Boeuf" stock=0 sur la branche
2. Open wizard tacos → étape viandes
3. Assert badge "Indisponible aujourd'hui" sur "Boeuf"
4. Click sur Boeuf → assert no selection (silence ou tooltip)
5. Choisir "Poulet" (dispo) → continue
6. Complete flow CARD
7. Assert order créée, stock poulet décrémenté
```

#### Scénario K5 — Kiosk abandon (timeout idle, cancel cart)
`k5-abandon.spec.js`

```
1. Open wizard, ajout 2 items
2. Visit cart
3. Wait 60s sans interaction
4. Assert idle timer trigger → redirect kiosk.idle
5. Assert cart vide au retour
```

### 4.3 Documentation produite

`docs/process/KIOSK_FULL_PROCESS_2026-04-27.md` :

- Schéma flow utilisateur complet (markdown ASCII).
- Étapes UX par écran avec captures Playwright.
- Backend events à chaque étape.
- Stockage : tables touchées par étape.
- Cas limites (rupture, abandon, network loss).

### 4.4 Allowlist C1

```
tests/e2e/kiosk-full-process/k1-card-simple.spec.js
tests/e2e/kiosk-full-process/k2-card-composition-tacos.spec.js
tests/e2e/kiosk-full-process/k3-counter-deferred.spec.js
tests/e2e/kiosk-full-process/k4-rupture-during-wizard.spec.js
tests/e2e/kiosk-full-process/k5-abandon.spec.js
tests/e2e/kiosk-full-process/_helpers.js                              # factories réutilisées
docs/process/KIOSK_FULL_PROCESS_2026-04-27.md
docs/process/screenshots/kiosk/                                       # captures auto-générées
missions/PRODUCT-COMPOSER-SYNC-C1-KIOSK-FULL-PROCESS/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C1-KIOSK-FULL-PROCESS.md
reports/post_execute_latest.log
```

### 4.5 Critères PASS C1

- 5 scénarios × 5 itérations = **25 runs verts**.
- Aucune régression sur tests E2E existants.
- Doc `KIOSK_FULL_PROCESS` couvre les 5 scénarios + cas limites.

---

## 5. Mission C2 — Process complet POS / CAISSE Playwright

### 5.1 5 scénarios end-to-end POS

#### Scénario P1 — POS sur place, walk-in, espèces
`tests/e2e/pos-full-process/p1-dine-in-walkin-cash.spec.js`

```
1. Login POS Operator
2. Open POS
3. Skip customer (walk-in implicite)
4. Add tacos M + boisson via wizard rapide
5. Validate cart
6. Pay CASH, received >= total
7. Assert receipt printed (mock)
8. Assert order created, payment_status=PAID, fiscal_sequence_no alloué
```

#### Scénario P2 — POS à emporter, customer identifié, carte
`p2-takeaway-customer-card.spec.js`

```
1. Login POS Operator
2. Search customer "Dupont"
3. Add menu Tacos
4. Add bouton "À EMPORTER"
5. Validate
6. Pay CARD, last 4 digits
7. Confirm receipt
```

#### Scénario P3 — POS livraison, distance, fee 5€/5km
`p3-delivery-geocode-fee.spec.js`

```
1. Login POS Operator
2. Customer with valid saved address
3. Add items
4. Order type DELIVERY
5. Backend recompute delivery_charge from saved coordinates
6. Assert delivery_charge correspond à la règle 5€/5km
7. Pay CARD
8. Forge payload delivery_charge=999 → assert backend recompute
```

#### Scénario P4 — POS encaisse une commande kiosk pending counter
`p4-counter-collect-confirm.spec.js`

```
1. Pre-seed : kiosk cash order PENDING_COUNTER existe
2. Login POS Operator
3. Open Counter Collect panel (.kiosk-cash-fab)
4. Find order by queue_number
5. Click Encaisser → modal mode CASH
6. Confirm → assert payment_status=PAID, fiscal_sequence_no alloué
7. Refresh KDS → assert badge "PAIEMENT COMPTOIR" disparu
```

#### Scénario P5 — POS annule une commande kiosk pending counter (no-show)
`p5-counter-collect-cancel.spec.js`

```
1. Pre-seed : kiosk cash order PENDING_COUNTER existe avec stock décrémenté
2. POS open Counter Collect
3. Click Annuler sur cette commande
4. Assert payment_status=REFUNDED, status=CANCELED, fiscal_sequence_no=NULL
5. Assert stock release effectif (movement reason='order_canceled')
6. Assert KDS retire le ticket
```

### 5.2 Documentation produite

`docs/process/POS_FULL_PROCESS_2026-04-27.md` couvrant les 5 scénarios POS avec les mêmes sections que la doc kiosk.

### 5.3 Allowlist C2

```
tests/e2e/pos-full-process/p1-dine-in-walkin-cash.spec.js
tests/e2e/pos-full-process/p2-takeaway-customer-card.spec.js
tests/e2e/pos-full-process/p3-delivery-geocode-fee.spec.js
tests/e2e/pos-full-process/p4-counter-collect-confirm.spec.js
tests/e2e/pos-full-process/p5-counter-collect-cancel.spec.js
tests/e2e/pos-full-process/_helpers.js
docs/process/POS_FULL_PROCESS_2026-04-27.md
docs/process/screenshots/pos/
missions/PRODUCT-COMPOSER-SYNC-C2-POS-FULL-PROCESS/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C2-POS-FULL-PROCESS.md
reports/post_execute_latest.log
```

### 5.4 Critères PASS C2

- 5 scénarios × 5 itérations = **25 runs verts**.
- Doc complète + captures.

---

## 6. Mission C3 — Synchronisation cross-channel KIOSK ↔ POS ↔ KDS ↔ OSS

### 6.1 Scénarios sync

#### S1 — Kiosk order arrive en realtime sur KDS
`tests/e2e/sync/s1-kiosk-to-kds-realtime.spec.js`

```
1. Open KDS page (admin login)
2. In parallèle, kiosk envoie order CARD K1
3. Assert KDS reçoit le ticket en < 3s SANS reload (Echo WS)
4. Assert composition_snapshot visible sur le ticket
5. KDS clic "Préparé" → status PREPARED
6. Assert kiosk.waiting (si encore visible) reçoit l'event et marque ready
```

Itérations 5×.

#### S2 — POS order visible sur KDS et POS live board
`s2-pos-to-kds-and-liveboard.spec.js`

POS crée order P1 → KDS la voit en realtime + autre POS staff voit dans live board.

#### S3 — Kiosk pending counter visible sur POS counter-collect
`s3-kiosk-pending-on-pos.spec.js`

Kiosk K3 (cash-at-counter) → POS panel pending l'affiche en < 2s.

#### S4 — Statut KDS PREPARED propage vers OSS et kiosk
`s4-kds-prepared-propagation.spec.js`

KDS clic Préparé → OSS écran public affiche numéro + kiosk.waiting marque ready (si encore en attente).

#### S5 — Cancel order propage partout
`s5-cancel-everywhere.spec.js`

POS annule order kiosk en PENDING_COUNTER → KDS retire ticket + kiosk si encore visible affiche cancel + customer historique reflète CANCELED.

#### S6 — Multi-branche isolation realtime
`s6-multi-branch-isolation.spec.js`

Branche A crée order → KDS branche B ne reçoit AUCUN event Echo (channel `private-branch.A.kds` ≠ `private-branch.B.kds`).

#### S7 — Network loss + reconnect resilience
`s7-network-loss-reconnect.spec.js`

```
1. Kiosk crée order
2. Coupe WS (mock close)
3. Order avance backend (KDS marque prepared)
4. WS reconnect → assert kiosk reçoit l'event manqué via fallback polling OU resync
```

### 6.2 Documentation

`docs/sync/CROSS_CHANNEL_SYNC_AUDIT_2026-04-27.md` :

- Diagramme séquence pour chaque event critique (`OrderCreated`, `OrderStatusChanged`, `OrderPaidAtCounter`, `OrderCanceled`, `StockLevelChanged`, `CatalogChanged`, `ItemAvailabilityChanged`).
- Channels Echo utilisés.
- Outbox `domain_events` patterns.
- Fallback polling intervals.

### 6.3 Allowlist C3

```
tests/e2e/sync/s1-kiosk-to-kds-realtime.spec.js
tests/e2e/sync/s2-pos-to-kds-and-liveboard.spec.js
tests/e2e/sync/s3-kiosk-pending-on-pos.spec.js
tests/e2e/sync/s4-kds-prepared-propagation.spec.js
tests/e2e/sync/s5-cancel-everywhere.spec.js
tests/e2e/sync/s6-multi-branch-isolation.spec.js
tests/e2e/sync/s7-network-loss-reconnect.spec.js
tests/e2e/sync/_helpers.js
docs/sync/CROSS_CHANNEL_SYNC_AUDIT_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-C3-CROSS-CHANNEL-SYNC/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C3-CROSS-CHANNEL-SYNC.md
```

### 6.4 Critères PASS C3

- 7 scénarios × 5 itérations = **35 runs verts**.
- Diagrammes séquence dans la doc.

---

## 7. Mission C4 — Stock V2 dynamique multi-canal

### 7.1 Scénarios stock

#### St1 — Décrément atomique kiosk + POS concurrentiels
`tests/e2e/stock/st1-concurrent-decrement.spec.js`

Stock initial 3 unités. Lancer en parallèle 5 commandes (3 kiosk + 2 POS). Attendu : 3 succès, 2 rupture (StockUnavailableException). Vérifier qu'il n'y a pas de stock négatif. Répété 5×.

#### St2 — Release sur cancel
`st2-release-on-cancel.spec.js`

Kiosk créer 1 order (stock 3 → 2) → POS cancel → assert stock 3 (release).

#### St3 — Release sur refund POS
`st3-release-on-refund.spec.js`

POS encaisse, puis refund partiel ou total → stock release proportionnel.

#### St4 — Rupture badge visuel realtime kiosk + POS
`st4-rupture-realtime-badge.spec.js`

Stock=1. Kiosk client ajoute item (stock 1→0). En parallèle, autre kiosk ouvert affiche immédiatement le badge "Indisponible". POS aussi.

#### St5 — Stock par variation et extra (pas seulement item)
`st5-stockable-polymorphic.spec.js`

Décrément choix viande "Boeuf" (variation) → stock variation décrémenté. Décrément extra "Fromage" → stock extra décrémenté. Pas d'impact sur item parent.

#### St6 — Branch isolation stock
`st6-branch-isolation.spec.js`

Branche A décrémente → branche B intacte.

### 7.2 Documentation

`docs/stock/STOCK_V2_DYNAMIC_AUDIT_2026-04-27.md` :
- Schéma `stock_levels` + `stock_movements`.
- Pattern décrément atomique (lockForUpdate + idempotency_key).
- Listeners release.
- Channel Echo rupture.
- Pattern UI badge.

### 7.3 Allowlist C4

```
tests/e2e/stock/st1-concurrent-decrement.spec.js
tests/e2e/stock/st2-release-on-cancel.spec.js
tests/e2e/stock/st3-release-on-refund.spec.js
tests/e2e/stock/st4-rupture-realtime-badge.spec.js
tests/e2e/stock/st5-stockable-polymorphic.spec.js
tests/e2e/stock/st6-branch-isolation.spec.js
docs/stock/STOCK_V2_DYNAMIC_AUDIT_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-C4-STOCK-DYNAMIC/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C4-STOCK-DYNAMIC.md
```

### 7.4 Critères PASS C4

- 6 scénarios × 5 itérations = **30 runs verts**.
- Aucun stock négatif détecté sur 30 runs.
- Doc complète.

---

## 8. Mission C5 — Queue number unique cross-channel

### 8.1 Scénarios

#### Q1 — POS et kiosk même branche, même jour, queue numbers disjoints
`tests/e2e/queue/q1-cross-channel-unique.spec.js`

Lancer 10 orders (5 kiosk + 5 POS) en parallèle même branche, même jour. Asserter que les `queue_number` sont 10 valeurs uniques.

#### Q2 — Reset jour suivant
`q2-business-date-reset.spec.js`

Ordre J1 → queue_number=42. Avancer business_date à J2 (mock clock). Nouvel ordre → queue_number=1 (reset).

#### Q3 — Multi-branche : numbers indépendants par branche
`q3-multi-branch-independent.spec.js`

Branche A queue=5 ne bloque pas branche B queue=5 (uniqueness scope = `(branch_id, business_date, queue_number)`).

#### Q4 — Concurrence extrême
`q4-extreme-concurrency.spec.js`

50 orders parallèles même branche → 50 queue_numbers distincts (1..50). Pas de gap, pas de duplicate.

### 8.2 Documentation

`docs/queue/QUEUE_NUMBER_UNIQUENESS_AUDIT_2026-04-27.md` :
- Migration `add_unique_branch_queue_number_to_orders.php`.
- Algorithme allocation (lock + MAX+1 ou séquence dédiée).
- Comportement reset business_date.

### 8.3 Allowlist C5

```
tests/e2e/queue/q1-cross-channel-unique.spec.js
tests/e2e/queue/q2-business-date-reset.spec.js
tests/e2e/queue/q3-multi-branch-independent.spec.js
tests/e2e/queue/q4-extreme-concurrency.spec.js
docs/queue/QUEUE_NUMBER_UNIQUENESS_AUDIT_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-C5-QUEUE-UNIQUENESS/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C5-QUEUE-UNIQUENESS.md
```

### 8.4 Critères PASS C5

- 4 scénarios × 5 itérations = **20 runs verts**.
- Q4 : 50 numbers distincts confirmés sur chaque itération.

---

## 9. Mission C6 — Persistance et historique

### 9.1 Audit

#### A1 — `audit_logs` chain integrity
Test PHPUnit `tests/Feature/Audit/AuditLogChainIntegrityTest.php` :
- Insérer N=100 audit logs concurrents.
- Vérifier que chaque row a un `prev_hash` qui matche le `hash` du précédent.
- Vérifier qu'on ne peut pas update/delete un row (observer ou trigger).
- Vérifier HMAC-SHA256 signature valide.

#### A2 — `fiscal_sequence_no` monotonic + gap-free
`tests/Feature/Fiscal/FiscalSequenceMonotonicTest.php` :
- Allouer 100 séquences sur même branche concurremment.
- Asserter que la séquence finale est `1, 2, ..., 100` sans gap.
- Asserter qu'un rollback de transaction NE consomme PAS la séquence (cf. comment OrderService:880-884).

#### A3 — `domain_events` outbox idempotency
`tests/Feature/Outbox/DomainEventsIdempotencyTest.php` :
- Dispatch même event 3× avec même `correlation_id` → 1 seule row.
- Worker process row une fois → marqué `processed_at`.

#### A4 — `composition_snapshot` immuable
`tests/Feature/Order/CompositionSnapshotImmutableTest.php` :
- Créer order_item avec composition.
- Modifier item parent (rename viande en DB).
- Asserter que `order_item.composition_snapshot` n'a pas changé (frozen NF525).

#### A5 — Z-reports cohérents
`tests/Feature/Fiscal/ZReportConsistencyTest.php` :
- Créer N orders (paid + canceled + refunded mix).
- Générer Z-report → totaux matchent agrégation orders.
- Z-report immuable (re-run même date → même résultat).

### 9.2 Documentation

`docs/persistence/HISTORY_AND_AUDIT_INTEGRITY_2026-04-27.md`.

### 9.3 Allowlist C6

```
tests/Feature/Audit/AuditLogChainIntegrityTest.php
tests/Feature/Fiscal/FiscalSequenceMonotonicTest.php
tests/Feature/Outbox/DomainEventsIdempotencyTest.php
tests/Feature/Order/CompositionSnapshotImmutableTest.php
tests/Feature/Fiscal/ZReportConsistencyTest.php
docs/persistence/HISTORY_AND_AUDIT_INTEGRITY_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-C6-PERSIST-AUDIT/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C6-PERSIST-AUDIT.md
```

### 9.4 Critères PASS C6

5 tests × 5 itérations PHPUnit = **25 runs verts**.

---

## 10. Mission C7 — Documentation centralisation données

Pas de code. Documentation pure exhaustive.

`docs/architecture/DATA_CENTRALIZATION_2026-04-27.md` :

1. **Schéma global tables centrales**
   - `items` / `item_categories` / `item_attributes` / `item_variations` / `item_extras` / `item_addons`
   - `item_wizard_profiles` / `item_wizard_steps`
   - `branches`, `tenants`
   - `orders` / `order_items` / `order_payments` / `order_quotes`
   - `stock_levels` / `stock_movements` / `item_branch_availability`
   - `audit_logs` / `domain_events` / `fiscal_sequences`
   - Relations clés + cardinalités

2. **SSOT par domaine** :
   - **Pricing** : `PricingService` + `OrderQuote` (jamais frontend)
   - **Availability** : `item_branch_availability`
   - **Stock** : `stock_levels` (polymorphe)
   - **Composer** : `item_wizard_profiles` (par item, override branche)
   - **Fiscal** : `fiscal_sequence_no` allocation atomique
   - **Audit** : `audit_logs` HMAC chain

3. **Branch isolation** :
   - Toutes les queries scope par `branch_id`
   - Listeners scope par `branch_id`
   - Echo channels `private-branch.{id}.*`
   - Tests d'isolation existants

4. **Multi-tenant** :
   - Si applicable, scope `tenant_id` au-dessus de `branch_id`

5. **Cycle de vie d'une donnée** : exemples concrets
   - Item créé en dashboard → `CatalogChanged` → invalidation cache → réception kiosk/POS
   - Order kiosk → events → outbox → KDS realtime + persistance + fiscalisation

### Allowlist C7

```
docs/architecture/DATA_CENTRALIZATION_2026-04-27.md
docs/architecture/diagrams/                                            # diagrammes ASCII ou Mermaid
missions/PRODUCT-COMPOSER-SYNC-C7-DOC-CENTRAL/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C7-DOC-CENTRAL.md
```

---

## 11. Mission C8 — Documentation storage / fluidité / sync

`docs/architecture/STORAGE_FLUIDITY_AND_SYNC_2026-04-27.md` :

1. **Storage** : MySQL/PostgreSQL tables, indexes critiques, partitioning si applicable.
2. **Caches** :
   - `kiosk-cache:menu:{branch}` (Redis/file)
   - `MenuSnapshot::current({branch})` version counter
   - Politique d'invalidation par event
3. **Echo / Pusher** :
   - Canaux : `private-branch.{id}.kds`, `private-branch.{id}.pos`, `private-branch.{id}.kiosk`, `private-branch.{id}.stock`
   - Authentication broadcast
   - Fallback polling intervals
4. **Outbox pattern** :
   - `domain_events` table
   - Worker `DispatchDomainEventsJob`
   - Idempotency via `correlation_id`
5. **API contracts** :
   - `/api/frontend/menu` — kiosk
   - `/api/admin/pos/quote|store` — POS
   - `/api/admin/pos/counter-collect/*` — counter
   - `/api/frontend/order` — web/kiosk
6. **Hot path performance budgets** :
   - Création order P95 < 500ms backend
   - Echo broadcast P95 < 2s
   - Polling fallback 5s

### Allowlist C8

```
docs/architecture/STORAGE_FLUIDITY_AND_SYNC_2026-04-27.md
missions/PRODUCT-COMPOSER-SYNC-C8-DOC-SYNC/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C8-DOC-SYNC.md
```

---

## 12. Mission C9 — Audit gestion dashboard (produits / catégories / stock)

### 12.1 Scénarios Playwright

#### M1 — Manager crée catégorie + produit + photo + composition
`tests/e2e/management/m1-create-category-product-composition.spec.js`

```
1. Login Branch Admin
2. Settings → Categories → New "Pizza" wizard_template=pizza
3. Save → assert category visible
4. Items → New "Pizza Margarita"
5. Upload photo
6. Variations : pâte (fine/épaisse) prix +0/+1
7. Extras : fromage extra +1.5, basilic +0.5
8. Composer tab → activer steps "Pâte", "Garnitures"
9. Publish → assert version bump
10. Visit kiosk in another tab → assert "Pizza Margarita" visible avec composer profile
```

#### M2 — Édition prix d'un item, propagation kiosk
`m2-price-edit-propagation.spec.js`

Edit prix item → assert kiosk affiche nouveau prix après bump version.

#### M3 — Suppression item, propagation
`m3-delete-item.spec.js`

Soft-delete item → kiosk ne le voit plus + cache invalidé.

#### M4 — Manager ajuste stock manuellement
`m4-manual-stock-adjustment.spec.js`

Manager set stock=10 sur item → `stock_movements` row reason='manual_in' → kiosk badge rupture retiré.

#### M5 — Authz par rôle
`m5-authz-roles.spec.js`

POS Operator tente d'accéder Settings → 403.
Branch Admin branche A tente d'éditer item branche B → 403 (si branch_id_scope présent).

### 12.2 Documentation

`docs/process/DASHBOARD_MANAGEMENT_AUDIT_2026-04-27.md` :
- Workflow manager type
- UI screens captures
- Authz matrix

### 12.3 Allowlist C9

```
tests/e2e/management/m1-create-category-product-composition.spec.js
tests/e2e/management/m2-price-edit-propagation.spec.js
tests/e2e/management/m3-delete-item.spec.js
tests/e2e/management/m4-manual-stock-adjustment.spec.js
tests/e2e/management/m5-authz-roles.spec.js
docs/process/DASHBOARD_MANAGEMENT_AUDIT_2026-04-27.md
docs/process/screenshots/management/
missions/PRODUCT-COMPOSER-SYNC-C9-DASHBOARD-AUDIT/{allowlist.txt,execute_brief.md,input.json}
reports/audit/GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C9-DASHBOARD-AUDIT.md
```

### 12.4 Critères PASS C9

5 scénarios × 5 itérations = **25 runs verts**.

---

## 13. Mission C10 — Rapport consolidé final + go/no-go hardware UAT

Pas de code. Synthèse.

`reports/audit/CLAUDE_CONSOLIDATED_PROCESS_SYNC_AUDIT_2026-04-27.md` :

1. **Récapitulatif missions C0..C9** : statut PASS/REWORK, evidence, écarts résiduels.
2. **Métriques** : nombre total de runs Playwright/PHPUnit, taux de réussite, durée moyenne.
3. **Findings résiduels P0/P1/P2** : si tous PASS → P0/P1 = 0.
4. **Documentation produite** : liste exhaustive des docs livrés.
5. **Décision finale** :
   - `PROCEED_HARDWARE_UAT` si C0..C9 tous PASS.
   - `REWORK_BEFORE_UAT` sinon, avec scope précis.
6. **Annexes** : captures, logs, traces.

### Critères PASS C10

- Tous les missions C0..C9 PASS.
- Aucun gap >P2 résiduel.
- Documentation complète.

---

## 14. Récapitulatif total

| Mission | Tests Playwright | Tests PHPUnit/Vitest | Documentation | Itérations | Total runs |
|---|---|---|---|---|---|
| C0 | 1 | 2 | inline | 5× E2E | 5 + 2 |
| C1 | 5 | – | KIOSK_FULL_PROCESS | 5× | 25 |
| C2 | 5 | – | POS_FULL_PROCESS | 5× | 25 |
| C3 | 7 | – | CROSS_CHANNEL_SYNC | 5× | 35 |
| C4 | 6 | – | STOCK_V2_DYNAMIC | 5× | 30 |
| C5 | 4 | – | QUEUE_UNIQUENESS | 5× | 20 |
| C6 | – | 5 | HISTORY_AND_AUDIT | 5× | 25 |
| C7 | – | – | DATA_CENTRALIZATION | – | – |
| C8 | – | – | STORAGE_FLUIDITY | – | – |
| C9 | 5 | – | DASHBOARD_MANAGEMENT | 5× | 25 |
| C10 | – | – | CONSOLIDATED | – | – |
| **Total** | **33 specs** | **7 tests** | **8 docs** | – | **~190 runs** |

Estimation effort Codex : 7-10 jours homme.

---

## 15. Règles d'exécution Codex (par mission)

Chaque mission C<n> respecte les règles standard FoodKing :

1. Mission folder `missions/PRODUCT-COMPOSER-SYNC-C<n>-<NAME>/` avec `execute_brief.md`, `allowlist.txt`, `input.json`.
2. Implémentation strictement dans l'allowlist.
3. **Run-Many** : 5× chaque test critique. Si ≥1 fail → debug → retry. PASS = 5/5.
4. Self-audit `GPT_SELF_AUDIT_PRODUCT-COMPOSER-SYNC-C<n>-<NAME>.md`.
5. Audit Claude post-mission `CLAUDE_REVIEW_PRODUCT-COMPOSER-SYNC-C<n>-<NAME>.md`.
6. `git diff --check` propre.
7. `npm run production` PASS si frontend modifié.
8. Aucune édition `OrderService` / `FrontendOrderService` hors hunks autorisés (frozen invariant).

### Règles d'arrêt
- 2 cycles healing même mission sans PASS → escalade humaine.
- Édition hors allowlist → REWORK forcé.
- Test flaky (4/5 PASS) → débugger root cause, ne jamais marquer PASS partiel.
- Pricing frontend détecté → REWORK forcé.

---

## 16. Ordre d'exécution recommandé

```
[J1]   C0 (kiosk auto-return fix) — débloque toute la suite
[J2-3] C1 (kiosk full process) ── parallèle ── C2 (POS full process)
[J4]   C3 (cross-channel sync)   ◄── consomme fixtures C1 + C2
[J5]   C4 (stock dynamic)        ── parallèle ── C5 (queue uniqueness)
[J6]   C6 (persistence audit)
[J7]   C9 (dashboard management)
[J8]   C7 (doc central) ── parallèle ── C8 (doc storage/sync)
[J9]   C10 (rapport consolidé) → décision finale
[J10]  Si PASS → reprise hardware UAT
```

---

## 17. Bug C0 immédiat — script de validation manuel

Pour le bug rapporté (kiosk bloqué après paiement simulé), avant de lancer C0, validation manuelle 30 secondes :

```bash
# Lancer dev server
npm run dev &
php artisan serve &

# Ouvrir kiosk
open http://localhost:8000/kiosk

# Faire commande complète, paiement CARD simulé.
# OBSERVATION : noter exactement où on est bloqué (URL + écran).
# Si kiosk.waiting bloqué : poll renvoie quel status ?
# Si kiosk.confirmation bloqué : countdown affiché ? trigger ?
```

Ce diagnostic alimentera le scope précis de C0.

---

## 18. Notes finales

- **Hardware UAT reste en HOLD** jusqu'à C10 PASS.
- Ce plan **n'invalide pas** les missions B-FIX-1/B-FIX-2 déjà réalisées (security + lifecycle). Il les complète par un audit process complet.
- Les P2 résiduels (seeder 4 rôles, closures inline counter-collect, PosLiveBoard agrégé) restent à arbitrer en post-UAT.
- Si un audit C<n> révèle un nouveau P0/P1, ouvrir une mission FIX dédiée hors plan, puis reprendre la séquence.

---

Document généré par Claude le 2026-04-27.
Codex peut commencer par C0 immédiatement (hotfix kiosk auto-return), puis C1+C2 en parallèle.

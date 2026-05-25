# Proposition — Bouton « Rembourser » UI POS (NF525 counter-entry)

**Date** : 2026-05-25
**Auteur** : GAP-PROPOSAL-05 (FoodKing Le Cayenne V1 Gap-Hunt)
**Statut** : OPEN-V1 (V1 ship-gate — bloque tant que non implémenté)
**Effort estimé** : ~6 h (correspond à l'estimate `PROJECT_BRAIN.md`)
**Recommandation** : Option B (modal dédié réutilisable) + permission `pos-refund` granted par défaut **Admin + Branch Manager UNIQUEMENT**

---

## 1. Pourquoi ça compte

- **V1 ship gate** : l'un des 5 owner-gates restants avant cutover Le Cayenne LOCAL (`PROJECT_BRAIN.md` — owner-gate #3 « POS Refund UI »).
- **Workflow réel cassé** : aujourd'hui le caissier ne peut **pas** rembourser un client au comptoir. Cas d'usage concrets impossibles en V1 :
  - reverse d'une CB qui finit par être déclinée après émission de ticket
  - mauvais article servi, client veut sa monnaie back
  - changement d'avis client avant départ
- **Pas de fallback admin** : la GUI `/admin/pos/order/{id}` ne porte pas non plus de bouton refund (vérifié gap-hunt) — donc même un manager qui tente de contourner depuis l'admin n'y arrive pas. Le seul vecteur aujourd'hui = `tinker` ou cURL direct (interdit en prod).
- **Le backend est prêt à 100 %** (cf. §2) — l'absence d'UI est purement frontend. C'est de la dette d'intégration, pas un risque NF525.

**Verdict V1** : sans cette UI le caisson est inopérant sur un scénario quotidien et bloque le go LOCAL.

---

## 2. Backend déjà prêt — evidence

### Route
- `routes/api.php:961-963` — `POST /api/admin/pos-order/{order}/refund-with-counter-entry`
  - middleware : `throttle:pos-order-update`, `idempotency` (race-protection backend OK)
  - name : `refundWithCounterEntry`

### Controller
- `app/Http/Controllers/Admin/PosOrderController.php:47-116` — `refundWithCounterEntry(Order, Request, RefundWithCounterEntryService)`
  - validation `reason` : **required string min:3 max:700** (cf. ligne 52-54)
  - garde cross-branch defense-in-depth (ligne 56-61) — admin bypass, staff scopé à `branch_id`
  - réponse 201 avec `OrderDetailsResource` + meta `parent_order_id` + `mirror_fiscal_sequence_no`
  - **409 `MIRROR_ALREADY_EXISTS`** sur double-refund (UNIQUE constraint heal A.3, ligne 82-96) — risk register §8 RAS
  - 422 sur `InvalidArgumentException` (ex. parent non remboursable)

### Service
- `app/Services/Order/RefundWithCounterEntryService.php` (**chemin réel**, pas `Payment/` comme indiqué dans la mission — déjà migré)
  - **Full-negate uniquement** (ligne 115-117 `subtotal/total_tax/total × -1`) — **pas de refund partiel supporté** (cf. §8 risk register #2 résolu)
  - Mirror order créé dans la Z window courante, parent immuable
  - `parent_order_id` exposé via G2-HEAL-01 (ligne 312)
  - Tranches split-payment mirrorées négatives (ligne 158-201, iter15 P0-10)
  - K2-HEAL-03 try/catch loyalty + K2-HEAL-07 cash_movement (verified HEAD, listeners `app/Listeners/ClawbackLoyaltyPointsOnRefund.php` + `ReleaseStockOnRefundCreated.php` + `ReleaseAvailabilityOnRefundCreated.php` + `PersistOrderPaymentStatusChangedOnRefundCreated.php`)

### Sentinel + intégration tests (8 fichiers GREEN HEAD)
- `tests/Feature/Refund/RefundWithCounterEntryRefundsLoyaltyPointsTest.php`
- `tests/Feature/Refund/RefundMirrorSplitPaymentTest.php`
- `tests/Feature/Refund/RefundListenerFailureIsolationTest.php`
- `tests/Feature/Refund/RefundLoyaltyTryCatchHardenedSentinelTest.php`
- `tests/Feature/Refund/RefundCashMovementRecordedSentinel.php`
- `tests/Feature/Refund/RefundCounterEntryUniqueParentTest.php`
- `tests/Feature/Refund/RefundBroadcastsPaymentStatusChangedTest.php`
- `tests/Feature/RefundCreatedDispatchTest.php`
- + `tests/Feature/Loyalty/LoyaltyClawbackOnRefundSentinelTest.php`

### Affichage
- `resources/js/components/admin/pos/ReceiptRemboursementMarker.vue` — marqueur visuel **REMBOURSEMENT** (F2-HEAL-03) déjà en place ; fallback i18n `label.remboursement`. Imprimé automatiquement quand `parent_order_id` est non-null.

**Conclusion §2** : la mission a évoqué `app/Services/Payment/RefundWithCounterEntryService.php` — le fichier réel est sous `app/Services/Order/`. Mineure mais à corriger dans le hand-off.

---

## 3. Surface UI nécessaire

### Constat préalable
- **`PosOrderShowComponent.vue` n'existe pas** dans `resources/js/components/admin/pos/` (vérifié `find`). Les vues passées POS V1 vivent dans `PosOrdersTrackerComponent.vue` (liste + détail inline).
- `PosCounterCollectModal.vue` existe — c'est le **pattern à mirrorer** (modal métier dédié, gate permission, axios + toast + refresh).

### Option A — Inline dans `PosOrdersTrackerComponent.vue` (rejetée)
- Bouton « Rembourser » dans l'item-actions du tracker
- Modal inline dans le même composant
- **Rejeté** : alourdit un composant déjà ~3 k lignes, double couplage (tracker + modal), pas réutilisable depuis admin/Z-report drill-down futur.

### Option B — Composant dédié `PosRefundModal.vue` (RECOMMANDÉ)
- Nouveau fichier : `resources/js/components/admin/pos/PosRefundModal.vue`
- Modal standalone qui prend en `props` : `order: Object, visible: Boolean`
- Émet : `@close`, `@refunded(mirrorOrder)`
- Workflow interne :
  1. Affiche récap parent : numéro, total, date, payment_mode
  2. Champ `reason` (textarea, requis min 3, max 700, compteur live)
  3. Bouton « Confirmer le remboursement » (rouge danger)
  4. POST `axios.post(\`/api/admin/pos-order/${order.id}/refund-with-counter-entry\`, {reason})` avec `X-Idempotency-Key` UUID v4 fresh
  5. Sur 201 : toast succès (« Remboursement émis — séquence fiscale #X ») + emit `refunded`
  6. Sur 409 `MIRROR_ALREADY_EXISTS` : toast info « Déjà remboursé », ferme modal, refresh parent
  7. Sur 422 : surface `message` backend (ex. parent non éligible)
  8. Sur 5xx : toast erreur générique + log console (telemetry futur)
- Wire-up trigger : **bouton « Rembourser » dans `PosOrdersTrackerComponent.vue`** sur la fiche détail, conditionné `v-if="canRefund(order) && $can('pos-refund')"`
- Bénéfice : réutilisable depuis future page `OrderDetailComponent.vue`, future drill-down Z-report, ou test e2e isolé.

### `canRefund(order)` — logique côté frontend
```javascript
canRefund(order) {
  // V1 conservatif — backend est source of truth, ce gate sert juste à
  // cacher le bouton dans les cas évidemment non remboursables. Le
  // backend re-vérifie de toute façon (defense-in-depth).
  return (
    order.payment_status === 'PAID' &&           // doit être encaissée
    !order.parent_order_id &&                     // pas déjà un mirror
    !order.is_refunded &&                         // flag exposé via Resource
    order.status !== 'CANCELED'                   // déjà annulée → géré ailleurs
    // NOTE: pas de window 14 jours côté frontend — c'est le backend qui
    // décide (NF525 = refund autorisé tant que parent existe).
  );
}
```

**Note** : la fenêtre 14 jours évoquée dans la mission n'est **pas** une contrainte backend actuelle (le service accepte tout parent immuable). La proposer comme un gate frontend serait introduire un fake-invariant. Laissé en commentaire pour discussion owner.

---

## 4. Permission `pos-refund`

### À ajouter dans `database/seeders/PermissionTableSeeder.php`
Modèle calqué sur `pos.redeem-loyalty` (ligne 183-190) :

```php
[
    'title'      => 'POS Refund (Counter-Entry NF525)',
    'name'       => 'pos-refund',
    'guard_name' => 'sanctum',
    'url'        => 'pos/refund',
    'created_at' => now(),
    'updated_at' => now(),
],
```

### À gater côté Controller
Au début de `refundWithCounterEntry` (PosOrderController.php:47), ajouter :
```php
abort_unless(auth()->user()?->can('pos-refund'), 403, 'Insufficient permission.');
```
(à insérer ligne ~52, **avant** la validation reason — fail-fast).

### À assigner par défaut dans `RolePermissionTableSeeder.php`
- **Admin** : OUI (auto-grant ; possède toutes les permissions)
- **Branch Manager** : OUI (workflow comptoir, accountable)
- **POS Operator** : **NON** par défaut (élévation de privilège — risque mass-refund vector cf. §8)
- Owner peut grant manuellement via l'UI `/admin/role/{id}/edit`

### Sentinel à ajouter
`tests/Feature/Permission/PosRefundPermissionMatrixSentinelTest.php` :
- Admin → 201
- Branch Manager → 201
- POS Operator sans permission → 403
- POS Operator AVEC permission custom-granted → 201

---

## 5. Critères d'acceptation

1. **Visibilité conditionnée** : caissier avec `pos-refund` voit le bouton « Rembourser » sur une commande remboursable. Sans permission → bouton absent du DOM (pas juste hidden — `v-if` strict).
2. **Validation reason** : impossible de soumettre avec `reason` < 3 chars (gate frontend + double-check backend 422).
3. **Idempotency** : double-clic rapide sur « Confirmer » génère le même `X-Idempotency-Key` (frozen pendant la durée du modal) → 1 seul refund effectif. Re-tentative avec une nouvelle clé sur le même parent → 409 `MIRROR_ALREADY_EXISTS` (cf. PosOrderController.php:90-95).
4. **Receipt** : ticket imprimé porte le marqueur REMBOURSEMENT (`ReceiptRemboursementMarker.vue` déjà actif si `parent_order_id` non-null).
5. **Audit log** : entrée `audit_logs` créée (chain HMAC) — vérifiable via `tests/Feature/Refund/RefundBroadcastsPaymentStatusChangedTest.php` déjà GREEN.
6. **Cash drawer** : si payment mode parent = `cash`, mouvement de tiroir négatif enregistré (K2-HEAL-07, sentinel `RefundCashMovementRecordedSentinel.php` couvre).
7. **Refresh tracker** : après refund réussi, la commande parent affiche un badge « Remboursée » et le mirror apparaît dans la liste avec total négatif.
8. **A11y** : modal a `role="dialog"`, `aria-modal="true"`, focus trap, ESC ferme, première erreur a `aria-live="polite"`.
9. **NF525 chain** : `count(audit_logs)` augmente de N (typiquement 2 : creation + payment_status_changed) avec `last_hash` cohérent. Spec e2e : capture `php artisan fiscal:chain-verify` avant/après.

---

## 6. Invariants NF525 préservés

- **Mirror order** : créé par service backend, parent **strictement immuable** (cf. ligne 110-130 service).
- **Sealed parent** : guard implicite via `RefundWithCounterEntryService` (le parent reste tel quel — aucun update sur sa row).
- **Append chain** : audit_logs append-only respecté (chain HMAC), z_reports non-modifiés.
- **Aucun toucher frozen-zone** :
  - `PaymentComponent.vue` → NON touché (le refund est un workflow séparé, pas un paiement)
  - `FiscalSequenceService.php` → NON touché (le mirror utilise `allocateNextSequence()` standard)
  - `AuditLogService.php` → NON touché
  - Migrations `audit_logs`/`z_reports` → NON touchées
- **Idempotency** : `IdempotencyKeyMiddleware` déjà appliqué via la route group (line 962).
- **Pattern existant mirroré** : `PosCounterCollectModal.vue` (même structure modal + axios + toast).

---

## 7. Effort estimate détaillé

| Tâche | Heures | Note |
|---|---|---|
| Backend (route + controller + service + tests) | 0 h | Déjà GREEN HEAD, evidence §2 |
| Permission seed + RolePermission grant + sentinel | 0.5 h | Copy-paste pattern `pos.redeem-loyalty` |
| `PosRefundModal.vue` (nouveau composant) | 3 h | Modal + form + i18n FR + axios + toast + a11y |
| Wire-up bouton + `canRefund()` dans `PosOrdersTrackerComponent.vue` | 1 h | 1 bouton + 1 modal trigger + 1 listener `@refunded` |
| Sentinel permission matrix | 1 h | `PosRefundPermissionMatrixSentinelTest` (4 cas) |
| E2E Playwright spec | 1 h | `tests/e2e/pos-refund.spec.ts` — flow paid → refund → ticket REMBOURSEMENT |
| **Total** | **~6 h** | Matches BRAIN estimate |

**Risque dépassement** : +2 h si i18n nécessite ajout clés (`label.refund_*`, `pos.refund.*`) dans `lang/fr.json` + `lang/ar.json` + sentinel `I18nKeyDriftSentinelTest`. À vérifier en début de tâche.

---

## 8. Risk register

| # | Risque | Sévérité | Mitigation |
|---|---|---|---|
| 1 | Permission accidentellement grantée à tous les POS Operators → mass-refund vector | **HIGH** | Default OFF dans `RolePermissionTableSeeder` ; owner grant explicite via UI ; sentinel matrix bloque le drift CI |
| 2 | Modal supporte refund partiel mais backend ne sait pas → erreur 422 confuse | RÉSOLU | Backend `RefundWithCounterEntryService:115-117` négate **total full** uniquement. Pas de champ `amount` dans modal. Documenté dans helper text « Le remboursement émet une contre-entrée intégrale pour la totalité de la commande ». |
| 3 | Race condition : 2 caissiers cliquent en parallèle sur le même parent | RÉSOLU | Backend triple-défense : `X-Idempotency-Key` middleware (frozen UUID), `UNIQUE(parent_order_id)` migration heal A.3, 409 `MIRROR_ALREADY_EXISTS` propre (PosOrderController.php:90-95). Sentinel `RefundCounterEntryUniqueParentTest` couvre. |
| 4 | Refund émis sur commande déjà annulée (CANCELED) → mirror sans logique métier | LOW | Frontend `canRefund()` exclut `CANCELED` ; backend re-vérifie (defense-in-depth à ajouter dans service si pas déjà présent) |
| 5 | Refund cross-branch par un staff Branch B sur commande Branch A | RÉSOLU | Controller ligne 56-61 abort 403 ; BranchScope global ; admin bypass autorisé (workflow voulu) |
| 6 | i18n FR/AR keys manquantes → texte cru dans UI prod | MED | Sentinel `I18nKeyDriftSentinelTest` + screenshot review visuelle obligatoire (CLAUDE.md §6 visual mandate) |
| 7 | Caissier refund par erreur, pas de undo | INHERENT | NF525 design — un refund émis est une mirror chain bit-irrévocable. UX : confirmation 2-step (modal + bouton rouge), reason mandatory min 3 chars sert d'audit. **Pas de undo possible (loi de finance).** |
| 8 | Receipt printer ne reçoit pas le marqueur REMBOURSEMENT (hardware down) | LOW | `ReceiptRemboursementMarker.vue` déjà actif ; degraded mode existant POS_SIMULATION_HARDWARE en dev. Prod : retry queue (déjà en place). |

---

## 9. Verdict V1 ship-gate

**État actuel** : **OPEN-V1** — le gate POS Refund UI **bloque** le go LOCAL Le Cayenne.

**État post-implémentation** (après ~6 h de dev + 1 round adversarial visual) : **CLOSE-V1** — gate franchi, V1 ship-ready.

**Recommandation orchestrateur** :
1. Schedule cette tâche dans la wave V1.0.X finale aux côtés des 4 autres owner-gates restants.
2. Pipeline : `superpower-gstack` (Think → Plan → Build → Review → Test → Ship → Reflect) avec sentinel-driven TDD (commence par `PosRefundPermissionMatrixSentinelTest` RED, puis green).
3. Visual mandate (CLAUDE.md §6) : capture modal vide → modal rempli → toast succès → fiche post-refund (4 screenshots minimum).
4. Adversarial review : 1 sub-agent dispute (a11y, race, i18n drift) avant ship.

**Aucun risque NF525 introduit** — l'invariant chain est entièrement protégé par le backend déjà GREEN.

---

## 10. Hand-off pour exécuteur

- **Branche suggérée** : `feat/pos-refund-ui-2026-05-25` depuis `heal/cms-pr1-quickwins-2026-05-18`
- **Pre-flight** : `php artisan fiscal:chain-verify` (snapshot `count` + `last_hash`)
- **Fichiers à créer** :
  - `resources/js/components/admin/pos/PosRefundModal.vue`
  - `tests/Feature/Permission/PosRefundPermissionMatrixSentinelTest.php`
  - `tests/e2e/pos-refund.spec.ts`
- **Fichiers à éditer (hors frozen-zone)** :
  - `database/seeders/PermissionTableSeeder.php` (+1 entry pattern `pos.redeem-loyalty`)
  - `database/seeders/RolePermissionTableSeeder.php` (grant Admin + Branch Manager)
  - `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue` (1 bouton + 1 modal import + 1 listener)
  - `app/Http/Controllers/Admin/PosOrderController.php:47` (1 ligne `abort_unless` permission gate, **avant** la validation reason)
  - `lang/fr.json` + `lang/ar.json` (clés refund — à scoper)
- **Post-flight** : `php artisan fiscal:chain-verify` (cohérence chain), `npm run test`, `php artisan test --filter=Refund`, capture visuelle 4 screenshots, owner review.
- **Frozen-zone diff attendu** : **0**
- **Effort réel attendu** : **6 h ±1.5 h**

---

**Auteur** : GAP-PROPOSAL-05
**Co-Author** : Claude Opus 4.7 (1M context) — FoodKing orchestrator
**Référence BRAIN** : owner-gate #3 of 5 V1 ship-gates restants

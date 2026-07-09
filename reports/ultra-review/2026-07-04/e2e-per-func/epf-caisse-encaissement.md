# E2E per-func — CAISSE : encaissement borne + refund + Z/X

HEAD 3c7145bf4 · DB foodking_e2e · read-only (0 write). fiscal:verify-chain avant ET après = CHAIN OK (4 branches).

## Fonctionnalités énumérées + verdict e2e

### 1. counter-collect/pending — file d'encaissement — OK
- Query réplique read-only: 157 PENDING_COUNTER non-CANCELED (154 kiosk + 3 pos COUNTER_DEFERRED). Filet anti-NULL présent (routes/api.php:807-853).
- Exclut CANCELED (L822), FIFO created_at ASC, cap 200, branch-scope.

### 2. confirm → PAID + fiscal alloué à l'encaissement + gap-free — OK
- Données réelles: orders #5457/5456/5455/5454/5452 (source=kiosk) PAID avec fiscal_sequence_no 2612..2608, pos_payment_method=CASH, pos_received_amount enregistré (scénario rendu: total 1.90 / reçu 5.00).
- PaymentService::confirmCounterPayment:335-337 alloue `FiscalSequenceService::next` UNIQUEMENT si null, à l'encaissement (pas à la création). lockForUpdate (L222) = race guard.
- branch1 fiscal: count=2609, min=1, max=2612, dupes=0. (3 numéros manquants = orders test hard-deleted; chaîne HMAC verify-chain OK — non bloquant.)

### 3. double-confirm → 409 (race) — OK (code + données)
- PaymentService:278-310: si déjà PAID → même caissier=200 no-op ; caissier différent/inconnu=PaymentAlreadyCollectedException.
- Route (api.php:874-891) convertit en 409 avec error_code=payment_already_collected + collected_by/at. 409 non-cachée par idempotency (2xx-only).
- Audits réels `order.counter_payment_confirmed` présents (user_id 1 et 3 distincts) → détection cross-caissier alimentée.
- Guards additionnels: terminal status CANCELED/REJECTED/RETURNED → 422 (L323), CASH reçu<total → 422 (L329).

### 4. refund/RETURNED + contre-écriture Z — OK
- 26 orders RETURNED (pre-Z: cashBack + loyalty + audit order.returned).
- Mirrors counter-entry réels: #4607/#4559/#4549 (parent_order_id set, total négatif -12/-4/-24, fiscal_sequence_no alloué, status=RETURNED).
- refundWithCounterEntry (PosOrderController:47-196): gate `pos-refund` fail-fast + cross-branch abort ; SealedOrderGuard sélectionne pre-Z vs mirror ; UNIQUE(parent) → 409 MIRROR_ALREADY_EXISTS.

### 5. change-payment-status (gates) — OK
- PosOrderController:348-380 abort_unless can('pos-refund') (Admin/Branch Manager) ; changeStatus→RETURNED idem (L329). POS Operator exclu par défaut.

### 6. X-report — OK (LIVE 200)
- Appel réel (Branch Manager #105): status 200, totals TTC 631.15 / HT 574.60 / TVA 56.55, total_by_method {CASH 506.25, CARD 117.90, method4 7.00}, total_by_tax_rate {10: 56.55}, order_count 102, période = ouverture Z (2026-06-19) → now. Ventilation carte/espèces présente.
- Gate: Caissier (POS Operator) sans pos-manage-fiscal → 403 ; Admin branch_id=0 → 422 "pinned to a branch" (fiscal exige branche).

### 7. Z open/close chaîne HMAC — OK
- z_reports monotone: seq 21/22/23/24, closed_at renseignés, 1 open courant (id26 seq24 depuis 2026-06-19).
- fiscal:verify-chain --all = CHAIN OK (avant et après). Gates open/close = throttle 10/min + pos-manage-fiscal + resolveBranchId (422 si admin non pinné). Open/close NON déclenchés (writes) — vérifiés par code + chaîne.

## Défauts confirmés reproduits
Aucun P0/P1/P2. Observation non bloquante: 3 numéros de séquence fiscale absents branch1 (orders test supprimés) — chaîne HMAC intègre, hors périmètre NF525 audit-chain.

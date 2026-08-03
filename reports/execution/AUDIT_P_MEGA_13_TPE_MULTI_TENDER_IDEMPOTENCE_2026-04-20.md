# AUDIT P-MEGA-13 — TPE handshake + multi-tender + idempotence

**Date** : 2026-04-20
**Mode** : READONLY (Phase B du cycle W5)
**HEAD** : 781232fb40f95b4f2849bd699af6d6fa5974830c
**Subagent** : `explore` (readonly very thorough)

## 0. Synthèse exécutive (5 lignes max)

Il n'existe pas de méthode unique `OrderService::pay()` : l'encaissement est éclaté entre **POS** (`posOrderStore`), **kiosk** (`myOrderStore` + TPE bridge + `payment-confirm`), et **web** (`PaymentController::payment` / gateways). Le **TPE physique** est hors Laravel : bridge JS/Electron (`kioskHardware.tpeCharge`) puis **`POST …/payment-confirm`**. **Aucune table `order_payments` / split N tenders** : un seul `payment_method` / `pos_payment_method` par ligne `orders`. **Séquence NF525** : allouée en **POS** uniquement (`FiscalSequenceService::next` dans `posOrderStore`) ; les commandes **kiosk** créées via `FrontendOrderService::myOrderStore` **ne réservent jamais** `fiscal_sequence_no`, donc le **Z** les **exclut** (`whereNotNull('fiscal_sequence_no')`). **Idempotence HTTP** : kiosk + POS création OK (`X-Idempotency-Key`) ; **`payment-confirm`** et **`changePaymentStatus`** sans clé dédiée ni machine d'état stricte — risques métier documentés ci-dessous.

## 1. Architecture TPE

| Couche | Rôle | Fichier:ligne |
|--------|------|----------------|
| **UI borne** | Overlay TPE, timeout **120s**, `_invokeTpe` → `kioskHardware.tpeCharge` | `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:404-499` |
| **Bridge JS** | `tpeCharge` / `tpeRefund` / `cancelPayment` ; doc timeout TPE **90s** côté natif | `resources/js/services/kioskHardware.js:225-255` |
| **Config** | `TPE_TIMEOUT_MS`, `TPE_REFUSED_RETRY_MAX` (config kiosk hardware) | `resources/js/config/kioskHardware.js:15-16` (réf. grep projet) |
| **API après TPE** | `OrderController::paymentConfirm` : verrou ligne, `payment_status=PAID`, `transaction_id`, puis `finalizePaidKioskOrder` | `app/Http/Controllers/Frontend/OrderController.php:72-147` |
| **Driver Ingenico (hors HEAD web)** | TCP JSON documenté dans rapports / Remix | `reports/execution/borne-windows-2026-03-21.md` (hors `app/`) |

**Protocole** : pas d'appel Stone/Ingenico depuis PHP ; contrat **IPC/Electron** ou stub navigateur (`approved` synthétique si pas de bridge) `KioskPaymentComponent.vue:471-476`. **Handshake** : pas d'étapes nommées côté serveur — **charge terminal → `transaction_id` → confirm API**. **Retry** : **3 tentatives** backoff linéaire sur `payment-confirm` `KioskPaymentComponent.vue:558-572` ; pas de circuit breaker serveur. **Logs structurés correlation_id** sur le flux `payment-confirm` : **non** (pas de `Log::channel('fiscal')` dans ce contrôleur).

## 2. Idempotence — analyse approfondie

| Mécanisme | Détail | Fichier:ligne |
|-----------|--------|----------------|
| **Header création kiosk** | `X-Idempotency-Key` + lock `branch_id|key` + retour ordre existant + catch duplicate `23000` | `app/Services/FrontendOrderService.php:128-144,186-188,609-615` |
| **Header création POS** | Pré-check + `orders.idempotency_key` + catch `23000` | `app/Services/OrderService.php:566-574,587-589,984-994` |
| **Transport** | `posOrder.js` force header ; timeout 30s | `resources/js/store/modules/posOrder.js:71-90` |
| **`payment-confirm`** | **Pas** d'`Idempotency-Key` ; idempotence par **`payment_status === PAID`** + `lockForUpdate` | `app/Http/Controllers/Frontend/OrderController.php:101-118` |
| **`POST /{order}/pay` (web)** | Gateways via `PaymentManagerService` — pas d'analyse TPE ; pas de header idempotence dans ce contrôleur | `app/Http/Controllers/Frontend/PaymentController.php:56-68`, `routes/web.php:38-39` |

**Table dédiée `idempotency_keys` / `payment_attempts`** : **absente** ; réutilisation de la colonne `orders.idempotency_key` (création) et états `payment_status`. **Double-clic « Payer 50€ » POS** : clé **régénérée à chaque ouverture du modal paiement** → deux clés si deux chemins parallèles → **2 commandes possibles** `PosComponent.vue:1506-1508` (mitigé seulement si même clé rejouée via `posOrder/save`). **Crash après TPE OK avant DB** : le client retente `payment-confirm` ; si la première transaction a commit **PAID**, la seconde voit `alreadyPaid` `OrderController.php:106-108,124-129`. **Gap** : si **PAID** écrit mais **`finalizePaidKioskOrder` échoue** après la transaction du contrôleur, état **PAID + PENDING cuisine** possible (hors transaction unique contrôleur ↔ finalize).

## 3. Multi-tender split

| Question | Verdict |
|----------|---------|
| **API split N parts** | **Non** : pas de sous-paiements ; total encaissé = `orders.total` |
| **Modèle** | `orders.payment_method` (kiosk gateway enum), `orders.pos_payment_method` / `pos_received_amount` (POS) ; table `transactions` pour certains flux gateway/refund | `PaymentService.php:13-28` ; pas de `order_payments` |
| **UX kiosk** | Choix **exclusif** card / cash / TR ; pas d'UI « reste à payer » multi-tenders | `KioskPaymentComponent.vue:28-118` |
| **TR + CB** | Doc design « puis CB pour le reste » hors implémentation dans ce composant (un seul `processCardPayment` sur le **total**) | `borne (Remix)/docs/design/KIOSK_HARDWARE_CALLS.md:141-143` vs `KioskPaymentComponent.vue:406-407` |
| **Validation Σ paiements** | **N/A** (un seul tender) ; caisse POS : validation cash **reçu ≥ total serveur** | `OrderService.php:857-864` |

## 4. NF525 fiscal counter

| Sujet | Détail | Fichier:ligne |
|-------|--------|----------------|
| **Allocation** | `Cache::lock` + `lockForUpdate` + `MAX+1` ; rollback parent **ne consomme** pas le n° (SAVEPOINT) | `app/Services/Fiscal/FiscalSequenceService.php:57-94` ; commentaire consommation `OrderService.php:884-892` |
| **POS** | `fiscal_sequence_no` assigné **dans** la transaction `posOrderStore` avant `save()` | `OrderService.php:891-894` |
| **Kiosk / web création** | **Aucun** appel `FiscalSequenceService` dans `FrontendOrderService` (grep vide) | — |
| **Z report** | Agrège seulement `fiscal_sequence_no IS NOT NULL` | `app/Services/Fiscal/ZReportService.php:226-228` |

**Conséquence** : commandes **kiosk** (cash immédiat, ou CB/TR différé + confirm) **sans** numéro fiscal → **invisibles au Z signé** — écart OSS / fiscal majeur. **Double-incrément** : protégé par lock + contrainte unique branche ; **double commande** = deux séquences (comportement attendu si deux ventes). **Test race double-pay** : pas de test PHPUnit ciblé « deux `payment-confirm` concurrents » ; `KioskPaymentStateMachineTest` couvre le nominal et un cas « déjà PAID » `tests/Feature/KioskPaymentStateMachineTest.php:123-249`.

## 5. Drawer + ESC/POS

| Déclencheur | Comportement | Fichier:ligne |
|-------------|--------------|----------------|
| **POS navigateur** | Après **succès** `posOrder/save`, si **CASH** → `openDrawer()` fire-and-forget | `resources/js/components/admin/pos/PaymentComponent.vue:221-229` |
| **Kiosk cash** | `openDrawer()` **avant** navigation (bridge uniquement) | `KioskPaymentComponent.vue:501-509` |
| **Kiosk CB/TR** | **Pas** d'ouverture tiroir (flux TPE) | même fichier |
| **Driver** | `kioskHardware.openDrawer` → bridge `openDrawer` ; sinon `drawer_unavailable` | `resources/js/services/kioskHardware.js:259-262` |
| **Tests Vitest** | `tests/js/posCashDrawerOpen.spec.js` (CASH oui, CARD non) | — |

**Multi-tender** : non applicable côté code (un tender). **Cash insuffisant** : serveur **422** avant succès ; drawer POS **non** ouvert (appel seulement après then du save).

## 6. Réconciliation OSS

| Sujet | Détail | Fichier:ligne |
|-------|--------|----------------|
| **Z / caisse attendue** | Agrégats `total_by_method` depuis **champ méthode** sur commandes **fiscal_sequence_no non null** ; **pas** de formule « float initial + espèces − remboursements » dans ce service | `ZReportService.php:246-254,287-300` |
| **Audit immuable** | `AuditLogService` sur changements sensibles ; `action_logs` pour POS | `OrderService.php:1653-1668` |
| **TPE charged / DB FAILED** | Pas de table `payment_attempts` ; réconciliation = **support manuel** (lecture `transaction_id`, logs TPE, rejouer `payment-confirm` si encore UNPAID) | `OrderController.php:111-115` |

**Écart kiosk vs Z** : risque systémique (section 4).

## 7. Refund / annulation

| Flux | Comportement | Fichier:ligne |
|------|--------------|----------------|
| **Gateway / transaction** | `PaymentService::cashBack` crée ligne `transactions` signe `-` + audit `payment.cash_back_issued` | `PaymentService.php:31-68` |
| **POS sans `Transaction`** | `posOrderStore` **ne** crée **pas** `Transaction` ; RETURNED/CANCEL **ne** déclenche **pas** `cashBack` si pas de ligne | `reports/review/VERIFY_02_P2_MULTI_TENDER_2026-04-20.md` (réf.) ; `OrderService.php` chemins statut (grep `transaction`) |
| **Kiosk annulation client** | `FrontendOrderService::changeStatus` peut appeler `cashBack` si `$frontendOrder->transaction` | `FrontendOrderService.php:676-677` |
| **Refund TPE** | `tpeRefund` exposé côté JS ; **pas** de endpoint Laravel miroir dans l'audit HEAD | `resources/js/services/kioskHardware.js:245-248` |
| **Partiel / idempotence refund** | Non modélisé au-delà du total commande ; pas d'idempotency key refund | — |

## 8. UX error handling

| Scénario | Comportement | Fichier:ligne |
|----------|--------------|----------------|
| **Timeout TPE** | Rejet `TPE_TIMEOUT` après 120s ; message erreur | `KioskPaymentComponent.vue:404-411` |
| **Sync API après TPE** | Échec après 3 retries → `payment_sync_failed` | `KioskPaymentComponent.vue:558-572` |
| **Réseau POS** | Timeout 30s ; message « Ne relancez pas — vérifiez le statut » | `resources/js/store/modules/posOrder.js:77-88` |
| **Carte refusée** | Void order `change-status` 16 ; compteur échecs + route erreur (tests Vitest) | `KioskPaymentComponent.vue:425-431` ; `tests/js/kioskPaymentRetryGate.spec.js` |
| **Thread UI** | `async/await` dans Vue ; TPE **async** (ne bloque pas le thread JS unique mais **occupe** le flux jusqu'à résolution) | — |

## 9. Tests existants — couverture

| Catégorie | Fichiers / nombre | Lacunes |
|-----------|-------------------|---------|
| **`tests/Feature/Payments/*`** | **0 fichier** (dossier inexistant) | — |
| **PHPUnit paiement / idempotence** | `ConcurrentOrderTest` (kiosk création), `IdempotencyBranchScopedTest` (index), `KioskPaymentStateMachineTest`, `PosTicketRestaurantPaymentTest` | Pas de **split multi-tender** ; pas de **crash PHP** entre TPE et commit ; pas de **`payment-confirm` concurrent** ; pas de **Z inclut kiosk** |
| **Vitest** | `kioskHardwareService.spec.js`, `posCashDrawerOpen.spec.js`, `kioskPaymentRetryGate.spec.js`, `kioskOfflineQueue.spec.js` (replay header), etc. | Pas de `tpeIdempotency.spec.js` dans `tests/js` à HEAD (tâche T13 seulement) |

## 10. Dette technique identifiée (NIVEAU SÉVÉRITÉ)

| ID | Sévérité | Description | Fichier:ligne |
|----|----------|-------------|---------------|
| F01 | **P0** | Commandes **kiosk** sans `fiscal_sequence_no` → **exclues du Z** signé | `ZReportService.php:226-228` ; absence dans `FrontendOrderService.php` |
| F02 | **P0** | Pas de **`OrderService::pay()`** atomique multi-tenant : incohérence avec cible architecture | — |
| F03 | **P0** | **Pas de multi-tender** (impossible 30€ CB + 5€ cash + 10€ TR comme 3 lignes) | `KioskPaymentComponent.vue:28-118` ; schéma |
| F04 | **P1** | `changePaymentStatus` : assignation directe, **pas** de machine d'états / txn / idempotence | `OrderService.php:1617-1668` |
| F05 | **P1** | `payment-confirm` : pas d'**Idempotency-Key** ; docblock « même transaction_id » non reflété en contrainte DB | `OrderController.php:72-115` |
| F06 | **P1** | POS : **nouvelle clé idempotence** à chaque ouverture modal → double commande si double parcours | `PosComponent.vue:1506-1508` |
| F07 | **P1** | **finalizePaidKioskOrder** + events **après** commit partiel possible (état intermédiaire) | `OrderController.php:101-122` ; `FrontendOrderService.php:788-843` |
| F08 | **P1** | Remboursement **POS** / fiscal : pas de `Transaction` systématique | `PaymentService.php:13-28` vs `VERIFY_02` |
| F09 | **P2** | `myOrderStore` : `OrderCreated` post-commit OK ; **`changeStatus` client** sans transaction (chemins auth) | `FrontendOrderService.php:592-602` ; `OrderService.php:1472-1506` |
| F10 | **P2** | Logs **non** corrélés TPE↔API (pas de `correlation_id` payment) | `OrderController.php` |

## 11. Risques business concrets

1. **Double encaissement client** : deux commandes POS pour un panier (double clé idempotence) `PosComponent.vue:1506-1508`.
2. **Client débité TPE, cuisine silencieuse** : échec `payment-confirm` après charge → erreur `payment_sync_failed` `KioskPaymentComponent.vue:558-572`.
3. **Z / URSSAF / contrôle** : **sous-déclaration** des ventes kiosk vs caisse réelle (F01).
4. **Tiroir** : ouverture **après** réponse API POS ; si échec réseau après encaissement réel, drawer peut ne pas s'ouvrir (opérationnel).
5. **Remboursement** : retour produit sans ligne `transactions` → pas de piste `cashBack` / audit symétrique (F08).

## 12. Recommandations correctives (impact LOC + zones)

1. **Unifier le « pay »** : introduire une façade transactionnelle unique (ou documenter le remplacement du concept `pay()`) — **OrderService + FrontendOrderService + OrderController**, ~200–400 LOC + tests.
2. **Fiscal kiosk** : appeler `FiscalSequenceService::next` au moment **business** approprié (création payée ou au `payment-confirm`) — **FrontendOrderService / finalizePaidKioskOrder**, migration fillable si besoin, ~50–120 LOC.
3. **Schéma multi-tender** : table `order_payments` + validation Σ = total — **migration + OrderService + API + POS + Kiosk**, **>500 LOC**.
4. **Idempotence `payment-confirm`** : header + store `(branch_id, order_id, key)` ou contrainte unique `transaction_id` — **middleware + migration**, ~150–250 LOC.
5. **Machine à états `payment_status`** + audit unique — **OrderService::changePaymentStatus**, ~100–200 LOC.
6. **POS idempotence** : clé stable **par panier** jusqu'à succès ou reset explicite — **PosComponent.vue**, ~20–40 LOC.
7. **Observabilité** : `Log::channel('fiscal')` / `correlation_id` sur TPE + confirm — **OrderController + bridge**, ~30–80 LOC.

## 13. Tests sentinelles à créer

1. `test_kiosk_paid_order_has_fiscal_sequence_and_appears_in_z_aggregate()` — **FAIL attendu** tant que F01 non corrigé.
2. `test_payment_confirm_idempotent_under_concurrency()` — deux workers, une seule promotion ACCEPT / un seul `OrderCreated`.
3. `test_pos_double_modal_same_cart_same_idempotency_key_single_order()` — simule double parcours UI (selon stratégie clé).
4. `test_multi_tender_split_sum_matches_total()` — **skipped** jusqu'à schéma.
5. `test_change_payment_status_rejects_duplicate_paid_transition()` — **FAIL** sur code actuel.
6. Vitest : **`queryTpeStatus` uniquement si réponse ambiguë** (requis T13 K-4).

## 14. Décisions humaines requises (input GATE_BRIEF)

1. **Moment légal du ticket fiscal kiosk** : à la création PENDING ou au `payment-confirm` ? (impact séquence NF525.)
2. **Multi-tender** : périmètre V1 (borne seule / POS inclus) et délai ?
3. **Réconciliation** : exiger table `payment_attempts` + workflow manuel TPE ↔ Laravel ?
4. **POS navigateur** : faut-il bloquer la prod sans agent Electron pour TPE réel ? (cf. `docs/PROJECT_CONTINUITY_AND_VISION.md`.)
5. **Arbitrage** : accepter un **Z « POS only »** temporaire ou **bloquer** la clôture fiscale tant que le kiosk n'alimente pas `fiscal_sequence_no` ?

---

**Références lecture obligatoire croisée** : `tasks/audit-orchestration/07_TASK_IDEMPOTENCY_BRANCH_2026-04-20.md` ; `reports/review/VERIFY_09_PAYMENTS_IDEMPOTENCY_2026-04-20.md` ; `reports/review/VERIFY_02_P2_MULTI_TENDER_2026-04-20.md`.

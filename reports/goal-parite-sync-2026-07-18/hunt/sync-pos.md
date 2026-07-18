# Hunt READ-ONLY — Synchronisation unifiée BORNE + WEB → CAISSE (POS)

Date: 2026-07-18 · Mode: READ-ONLY (aucune modif) · DB: foodking_e2e (tinker lecture seule) · Serveur :8000

Question owner: « synchronisation unifiée et connectée avec POS ». Où les commandes
borne et web N'arrivent-elles PAS de façon cohérente/unifiée à la caisse ?

---

## Verdict synthèse

La fondation est **majoritairement symétrique et saine**. L'encaissement, le temps-réel,
l'annulation/refund, la machine à états et l'idempotence traitent borne et web par la
**forme de paiement** (transaction / pos_payment_method / payment_status), pas par la
surface — donc identiquement à état égal. **P1-3 (web PENDING_COUNTER incollectable) est
bien healé : 0 résidu vivant.** Les seuls écarts trouvés sont **2 gaps latents (P3)** — dont
un est le **jumeau non-COD du bug P1-3** — plus 1 différence de workflow **par design**.

Aucun P0/P1/P2 confirmé. Aucune instance vivante des gaps latents (0 en DB).

---

## Ce qui est CONFIRMÉ symétrique (pas de bug)

**Lens 1 — Files caisse.** Deux panneaux POS :
- `/pos/counter-collect/pending` (routes/api.php:804-873) « à encaisser » : borne (kiosk +
  KIOSK/TAKEAWAY), pos-différé, phone-différé, **web (COUNTER_DEFERRED)** + filet anti-NULL.
- `/pos/web-orders/pending` (routes/api.php:882-896) « commandes web à traiter » : web + status=PENDING.
- Les deux excluent bien CANCELED/REJECTED/RETURNED (counter-collect), branch-scope + cap 200 FIFO.
- Handoff propre : une commande web est soit PENDING (web-orders) soit ACCEPT+PENDING_COUNTER
  (counter-collect) — jamais durablement les deux (0 en DB, cf. repro).

**Lens 2 — Encaissement.** `confirmCounterPayment` (PaymentService.php:217-458) est le chemin
UNIQUE. `assertCounterDeferredOrder` (:844-873) accepte désormais `web` au même titre que
kiosk/pos/phone. Le sceau NF525 est **identique quelle que soit la source** : fiscal_sequence
alloué à l'encaissement (:364-375), `fiscal_dated_at` stampé, Transaction `payment`, AuditLog,
`OrderPaidAtCounter` → tiroir. Un web-takeaway-COD collecté = mêmes octets fiscaux qu'une borne-cash.

**Lens 3 — Temps réel.** `OrderCreated` est diffusé sur `private-branch.{branch_id}` pour
borne ET web (PersistOrderCreatedToOutbox.php:44) ; la dispatch-gate (FrontendOrderService.php:250)
émet toujours pour web (`!$isKioskOrderType = true`). Côté POS, `_subscribeEcho` (PosComponent.vue:3241)
+ `_schedulePanelsRefresh` (:2618) rafraîchissent **loadKioskCashOrders ET loadWebOrders** sur chaque
événement, avec fallback polling (:3216-3225). Le filtre son/badge `shouldNotifyPosRealtimeOrder`
(store/modules/posOrder.js) exclut seulement origin='pos' et order_type ∈ [15,20] → borne et web
sonnent tous deux. **Symétrique.**

**Lens 4 — Annulation/refund.** OrderService::changeStatus (:2293-2408) dérive le remboursement de
`$locked->transaction`, `pos_payment_method`, `payment_status` — **jamais de source_surface**. Une
borne-cash et un web collectés au comptoir portent tous deux une Transaction `payment` + pos_pm=CASH
→ cashBack → sortie tiroir identique + release stock (RefundCreated) + clawback fidélité. Symétrique.

**Lens 5 — Statuts.** Même table `orders`, même OrderStateMachine, même ValidStatusTransition.
AutoPrepareOnPaidPolicy (Domain/Order/AutoPrepareOnPaidPolicy.php:54-74) ne distingue pas web de
kiosk (promotion ACCEPT→PREPARING sauf counter-collect+cash) → symétrique.

**Lens 6 — Idempotence.** Création borne+web : même `myOrderStore` (lock Cache + UNIQUE
(branch_id, idempotency_key) + recovery 23000). Encaissement : même route counter-collect +
middleware `idempotency` + garde race multi-caissier `PaymentAlreadyCollectedException` (409).

Repro DB (tinker) : PENDING_COUNTER non-terminaux = 5, **tous visibles** en file (invisible=0) ;
web PENDING+PENDING_COUNTER simultané = 0 ; kiosk COUNTER_DEFERRED resté PENDING = 0.

---

## Findings

### [P3] OnlineOrderController.php:146-168 — Jumeau non-COD du bug P1-3 : web accepté non-(takeaway-COD) = board-released mais INCOLLECTABLE

Le heal **SYNC-WEB-KDS-01** bascule en `PENDING_COUNTER` **TOUTE** commande web UNPAID acceptée
(condition large : `status=ACCEPT && payment_status=UNPAID && order_type!=POS`, :146-149). Or le
marqueur collectable `COUNTER_DEFERRED` n'est posé que pour **TAKEAWAY + CASH_ON_DELIVERY** (:161-165).

Conséquence : un **web TAKEAWAY avec payment_method ≠ COD** (ou `null`) accepté devient
`ACCEPT + PENDING_COUNTER + pos_payment_method=NULL` →
- **board-released** vers le KDS (KitchenReleaseRule:105-107 admet PENDING_COUNTER) → la cuisine prépare ;
- **invisible** à `/counter-collect/pending` (clause web exige COUNTER_DEFERRED, routes/api.php:848-849) ;
- **absent** de `/web-orders/pending` (status devenu ACCEPT).
→ commande **préparée mais jamais encaissable au comptoir** = vente perdue + repas donné. C'est
**exactement la classe du P1-3** (le filet board-release est PLUS LARGE que le filet collecte).

Reachabilité : `OrderRequest.php:182` valide `payment_method` en `['nullable','numeric']` — **non
restreint à COD**. La DB prouve la forme : `SELECT ... WHERE source_surface='web'` → `pm=4 (CARTE)
otype=10` existe (1 ligne, status=19 REJECTED donc jamais acceptée). **0 instance vivante
collectable-manquée** aujourd'hui (le client web sanctionné n'envoie que COD, carte OFF). Donc
gap **défense-en-profondeur / latent** — mais devient **P1 le jour où la carte web est activée**
(roadmap V1.0.1) ou si un bug client envoie un pm non-COD/null. Miroir manquant : la clause web de
counter-collect + assertCounterDeferredOrder ne couvrent que COD.

Repro (tinker, READ-ONLY) : `Order::where('source_surface','web')->selectRaw('payment_method,order_type,count(*)')`
→ `pm=1 otype=10:69, pm=4 otype=10:1, pm=1 otype=5:2, pm=1 otype=2:3`.

### [P3] OnlineOrderController.php:167 — Accept web NON-ATOMIQUE (flip paiement hors transaction du changeStatus) vs borne atomique

Le flip `payment_status=PENDING_COUNTER (+COUNTER_DEFERRED)` est un `$order->save()` à la ligne
167, **AVANT et HORS** du `try` qui délègue ensuite à `OrderService::changeStatus` (status→ACCEPT,
:170-171). Deux `save()` séparés, sans transaction englobante. Le pendant borne (auto-accept
FrontendOrderService.php:633-637 + :284-296) fait status + payment_status **dans UNE seule
DB::transaction atomique**.

Si `OrderService::changeStatus` jette APRÈS le flip (garde cross-branch OrderService.php:2264-2268,
hoquet DB, race de transition), la commande web reste `PENDING + PENDING_COUNTER + COUNTER_DEFERRED` :
apparaît dans les DEUX files caisse simultanément, et un encaissement (confirmCounterPayment
n'exige pas ACCEPT) la passe `PAID + PENDING` → jamais sur le KDS (status PENDING < ACCEPT). Faible
probabilité (l'arête PENDING→ACCEPT ne jette pas normalement) → **P3**, mais c'est une couture
non-atomique absente côté borne. 0 instance vivante (repro : web PENDING+PENDING_COUNTER = 0).

---

## Observations (par design — pas des défauts, notées pour l'owner)

**O1 — Traitement « unifié » asymétrique par DESIGN (workflow).** Borne-cash : auto-accept →
KDS + file « à encaisser » **immédiatement, inline dans le POS**. Web : atterrit d'abord en panneau
« commandes web à traiter », et `openWebOrder` (PosComponent.vue:3616) **renvoie le caissier vers
`/admin/online-orders`** — pas d'accept/collecte inline dans le POS. C'est le mandat owner (client
distant ≠ client sur place, routes/api.php:874-881) : différence assumée, mais c'est la principale
divergence de « connexion unifiée » ressentie côté caissier.

**O2 — Web DELIVERY COD : board-released, encaissé au doorstep, hors file caisse.** Après accept,
un web DELIVERY = `PENDING_COUNTER + pos_pm=NULL` → cuisine prépare, mais aucune file caisse
(intentionnel : livreur `deliveryBoyOrderChangeStatus`). Si le flux livreur n'est pas utilisé
(mono-poste), la piste cash dépend de CASH-01 (déjà gated/exclu).

**O3 — Surfaces non câblées.** counter-collect ne connaît pas `mobile`/`app` ; conforme au mandat
(mobile standalone sans wireup). `surf=delivery` : 2 commandes actives UNPAID hidden-KDS (flux
livreur distinct, hors périmètre borne/web→POS).

---

## Écartés (raison)

- **Web PENDING_COUNTER incollectable (P1-3)** : HEALÉ, vérifié 0 résidu (5 PENDING_COUNTER
  non-terminaux, tous visibles en file). Seul le **jumeau non-COD** subsiste (Finding P3 ci-dessus).
- **Refund web sans gate (P2-e)** : gate `pos-refund` présent (OnlineOrderController.php:189-195). OK.
- **CASH-01 / REFUND-01 (cash-trail web/doorstep)** : exclus (gate owner en cours).
- **Files POS zombies + janitor (P2-n)** : exclu.
- **delivery_charge POS (S2-02), encaissement online sans session (S2-03)** : exclus.
- **Double-refund cashBack espèces (avoir wallet)** : déjà tracké/escaladé (PaymentService.php:146-155).

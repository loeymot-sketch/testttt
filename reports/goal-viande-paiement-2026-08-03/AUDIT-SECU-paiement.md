# AUDIT SÉCURITÉ — chemin paiement en ligne Mollie (READ-ONLY)

**Date** : 2026-08-03
**Périmètre** : backend `testttt` (`Mollie.php`, `MolliePaymentController.php`, `FrontendOrderService.php`, `routes/api.php`, `config/payment.php`) + web déployé `lecayenne-web-deploy/Site lecayenne` (`funnel.jsx`, `api.js`, `index.html`)
**Déclencheur** : P0 owner « annuler le 3DS laissait la commande validée » — corrigé (`failed|canceled|expired` → `cancelForFailedOnlinePayment`). Mission : trouver les AUTRES trous du même chemin.
**Méthode** : lecture intégrale du chemin + traçage des invariants NF525/argent. Chaque finding porte `file:line`, scénario reproductible et preuve. Aucun fichier modifié.

**Verdict global : 🔴 BLOCK sur le money-path.** Le correctif 3DS est **solide et bien gardé** (angles 1, 3, 4, 5 = SAFE, preuves ci-dessous). Mais l'audit met au jour **1 P0 NF525 structurel** — *toute vente carte encaissée en ligne est hors chaîne fiscale et hors Z signé* — plus **3 P1** dont deux font disparaître de l'argent client sans aucune alerte. Le P0 n'est pas une régression du fix 3DS : il préexiste, il est **documenté dans le code comme « gate owner G-W5 »**, et il est **encodé comme comportement attendu par un test vert** — or le drapeau est **déjà levé en production** (`MOLLIE_ENABLED=true`, profil Mollie **LIVE** `pfl_Ymr3Tb6vvp`, `testmode=0` sur le site déployé). La porte a été ouverte sans que le chemin fiscal ne soit élargi.

---

## Tableau de bord

| # | Sévérité | Titre | Fichier principal |
|---|----------|-------|-------------------|
| P0-1 | 🔴 P0 | Vente carte en ligne **PAYÉE hors chaîne NF525** (jamais dans le Z signé, irrattrapable) | `Mollie.php:438-441` |
| P1-1 | 🟠 P1 | Échec transitoire du webhook = **paiement perdu à vie** (aucun handler DLQ `mollie`) | `ProcessWebhookEventJob.php:77-91` |
| P1-2 | 🟠 P1 | Le client peut **auto-annuler sa commande DÉJÀ PAYÉE** en ligne — 0 remboursement, 0 alerte | `FrontendOrderService.php:827-839` |
| P1-3 | 🟠 P1 | **Double encaissement silencieux** espèces + carte : la branche `alreadyPaid` réécrit l'attribution sans un seul log | `Mollie.php:409-419` |
| P2-1 | 🟡 P2 | Webhook public = **amplificateur de requêtes** non authentifié (fetch 15 s AVANT dédup) | `Mollie.php:197-220` |
| P2-2 | 🟡 P2 | `checkout_url` suivie **sans validation d'origine** (open-redirect de 2ᵉ ordre) | `Mollie.php:144` → `funnel.jsx:484` |
| P2-3 | 🟡 P2 | Course annulation-client / paiement-abouti : argent encaissé sur une vente void, **remboursement 100 % manuel sans piste** | `Mollie.php:421-435` |
| P2-4 | 🟡 P2 | Commande web fantôme **jusqu'à 6 h** si le webhook n'arrive jamais (aucune réconciliation *pull*) | `CleanupStalePendingKioskOrders.php:188-212` |
| — | ✅ SAFE | Authenticité webhook · falsification `metadata.order_id` · course paid→canceled · montant/idempotence · `refundPoints` · release stock | voir §SAFE |

---

## 🔴 P0-1 — Vente carte en ligne PAYÉE mais JAMAIS scellée fiscalement (hors Z signé, irrattrapable)

**Fichiers** : `app/Http/PaymentGateways/Gateways/Mollie.php:438-441` et `:459-460` · `app/Services/FrontendOrderService.php:1334-1348`

### Scénario reproductible
1. Client commande sur `lecayenne.fr`, paie carte (Mollie Components, profil LIVE, `testmode=0`).
2. Webhook `paid` → `processFetchedPayment` → montant OK → `payment_status = PAID`, `transaction_id = mollie:tr_x`.
3. **L'argent est encaissé. La commande n'a AUCUN `fiscal_sequence_no`, et n'en aura jamais.**
4. Clôture Z du soir : la vente **n'y figure pas**. Elle n'est dans aucun Z, ni ce soir ni jamais.

### Preuve (chaîne complète)
- `Mollie.php:438-441` — le scellement se fait **en écriture directe sur le modèle**, sans passer par `changePaymentStatus` :
  ```php
  $locked->payment_status = PaymentStatus::PAID;
  $locked->transaction_id = $transactionId;
  $locked->card_type = 'mollie';
  $locked->save();
  ```
  → aucun appel à `FiscalSequenceService::next()` sur ce chemin.
- `Mollie.php:459-460` délègue à `finalizePaidKioskOrder()`, seul candidat à l'allocation… qui **no-ope pour toute commande web** :
  - `FrontendOrderService.php:1334` : `$isKioskMachineOrder = KioskMachine::where('user_id', $frontendOrder->user_id)->exists();` — sur une commande web, `user_id` est **le client**, jamais une borne → `false`.
  - `FrontendOrderService.php:1346-1348` : `if (!$isKioskOrderType || !$isDeferredPaymentMethod) { return false; }` → sortie immédiate, avant le bloc d'allocation de `:1404-1410`.
- **Le code l'admet lui-même** — `Mollie.php:463-475` émet `mollie.webhook.fiscal_finalize_noop` avec la note *« PAID sans fiscal_sequence_no — gate kiosk finalizePaidKioskOrder (activation G-W5) »*.
- **Exclusion du Z** : `ZReportService.php:425` → `->whereNotNull('fiscal_sequence_no')`.
- **Aucun rattrapage possible** :
  - `RetryFiscalAllocCommand.php:68` → `->whereNotNull('fiscal_alloc_error_at')` ; ce drapeau **n'est jamais posé** sur le chemin Mollie → le cron ne verra jamais la ligne.
  - `PaymentService.php:323-355` → si `payment_status === PAID`, l'encaissement comptoir lève `PaymentAlreadyCollectedException` (409) → **le caissier ne peut pas non plus la sceller à la main**.
- **Un test vert encode le bug** — `tests/Feature/Payment/MollieStructureTest.php:379-405`, nommé `test_webhook_paid_web_order_marks_paid_and_leaves_fiscal_gap_flagged_for_gw5`, se termine par `$this->assertNull($fresh->fiscal_sequence_no);`. C'est très exactement le piège « un test qui passe peut encoder un bug ».

### Pourquoi c'est un P0 maintenant, et pas un backlog
Le commentaire de code présente l'écart comme *différé au gate owner G-W5*. Mais le gate est **déjà levé sur le terrain** :
- `.env:108` → `MOLLIE_ENABLED=true`
- `config/payment.php:114-121` → `enabled` piloté par cet env, `api_base` = `https://api.mollie.com/v2` (production)
- web déployé `index.html:30-31` → `mollie-profile-id = pfl_Ymr3Tb6vvp`, `mollie-testmode = 0` (le commentaire `index.html:28` confirme « profil LIVE vérifié … E.DELICE / Le Cayenne »)

Le codebase a **déjà corrigé trois fois cette exacte classe de faille** — `OrderService.php:2062-2069` (COD livraison), `OrderService.php:2757-2765` (« marquer payé »), `PaymentService.php:380-390` (encaissement comptoir), toutes documentées « vente PAYÉE hors chaîne NF525 ». Le webhook Mollie est le **4ᵉ point de scellage** et le seul resté non traité.

**Atténuation partielle** : `ZReportService.php:730-760` (`warnOnOrphanedPaidOrders`) compte bien ces lignes à la clôture et loggue `z_report.close.orphan_paid_orders_in_window` — mais c'est de l'observabilité en canal `fiscal`, la note attribue à tort la cause au « retry cron kiosk », et **la vente reste exclue du Z signé**.

### Reco
1. **Décision owner explicite** : le paiement carte en ligne est-il activé en prod ? Si oui, G-W5 est activé de fait → le chemin fiscal DOIT suivre. Si non → couper `MOLLIE_ENABLED` **et** le profil Mollie du site tant que le scellage n'existe pas.
2. Aligner `Mollie.php:437-441` sur le miroir déjà validé 3× : allouer `fiscal_sequence_no` + `fiscal_dated_at` **dans la même transaction**, avec les mêmes exclusions (statut terminal, `source_surface !== 'uber_eats'`), et poser `fiscal_alloc_error_at` en cas d'échec pour rendre la ligne rattrapable par `foodking:fiscal:retry-alloc`.
3. Retourner le test `MollieStructureTest.php:379-405` : il doit asserter `assertNotNull($fresh->fiscal_sequence_no)`.
4. Zone NF525 → **gate owner obligatoire** (CLAUDE.md §8/§10). Ne pas patcher sans LOCK.

---

## 🟠 P1-1 — Un échec transitoire du webhook perd le paiement définitivement (aucun handler DLQ `mollie`)

**Fichiers** : `app/Jobs/ProcessWebhookEventJob.php:77-91` · `app/Http/PaymentGateways/Gateways/Mollie.php:269-277`, `:279-292`

### Scénario reproductible
1. Webhook `paid` arrive. `WebhookEvent` créé (`tr_x:paid`, `status=pending`).
2. `processFetchedPayment` lève (deadlock InnoDB sur `lockForUpdate` en concurrence avec une annulation client, coupure DB, etc.) → `Mollie.php:288` `markFailed()` → HTTP 500 → Mollie **rejouera**.
3. **Le rejeu Mollie est avalé** : `Mollie.php:250-261` `firstOrCreate` retrouve la ligne, `:269` `!$event->wasRecentlyCreated` → `duplicate_ignored` 200. Le contrôle de dédup est **avant** tout traitement → aucune tentative de re-drive.
4. Filet de secours théorique = `foodking:webhook:retry-failed` (`Kernel.php:83`). Il dispatche `ProcessWebhookEventJob`, dont le routage est :
   ```php
   $handlerClass = match ($event->provider) {
       WebhookEvent::PROVIDER_STRIPE    => …Stripe::class,
       WebhookEvent::PROVIDER_SENANGPAY => …Senangpay::class,
       default                          => null,   // ← mollie tombe ici
   };
   ```
   `ProcessWebhookEventJob.php:83-91` → `markFailed('No DLQ handler for provider: mollie')`.
5. **Résultat** : client débité chez Mollie, commande jamais PAID, aucun re-drive possible. Au bout de 6 h le janitor l'annule (`CleanupStalePendingKioskOrders.php:188-212`) → **argent encaissé, commande annulée, zéro remboursement, zéro alerte opérationnelle**.

### Preuve
- `ProcessWebhookEventJob.php:77-81` : le `match` ne contient **pas** `WebhookEvent::PROVIDER_MOLLIE` (constante pourtant définie, `WebhookEvent.php:55`).
- `ProcessWebhookEventJob.php:83` exige aussi `method_exists($handlerClass, 'handleFromStoredEvent')` — méthode **absente de `Mollie.php`** (lecture intégrale du fichier : aucune occurrence). Double blocage.
- `Mollie.php:269-277` : le `duplicate_ignored` précède le traitement → un `status=failed` est un cul-de-sac vis-à-vis des rejeux du fournisseur.

### Reco
Ajouter `PROVIDER_MOLLIE` au `match` **et** implémenter `Mollie::handleFromStoredEvent(WebhookEvent $e)` (re-fetch de l'id + réexécution de `processFetchedPayment`, idempotent par construction). Alternative minimale : dans `handleWebhook`, si la ligne existante est `status=failed`, **re-driver au lieu de répondre `duplicate_ignored`**.

---

## 🟠 P1-2 — Le client peut annuler lui-même sa commande DÉJÀ PAYÉE en ligne (0 remboursement, 0 trace)

**Fichiers** : `app/Services/FrontendOrderService.php:820-839` · `app/Models/FrontendOrder.php:175-178`

### Scénario reproductible
1. Client paie en ligne → webhook `paid` → `payment_status = PAID`.
2. **Conséquence directe du P0-1** : `finalizePaidKioskOrder` no-ope, donc la commande **reste `status = PENDING`** (jamais promue ACCEPT).
3. Le client ouvre son suivi et clique « Annuler ma commande » → `POST /api/frontend/order/change-status/{id}` avec `status=16`.
4. Garde franchie : `FrontendOrderService.php:827` → `if ($locked->status >= $cancelableThreshold) throw`. Une commande web est `order_type = TAKEAWAY(10)` (`MollieStructureTest.php:473`), donc `:821-826` la classe `$isKioskOrder = true` → seuil `PREPARING(7)`. `PENDING(1) < 7` → **annulation autorisée**.
5. **`payment_status` n'est jamais consulté sur ce chemin.** La commande passe CANCELED.
6. Remboursement : `:831` `if ($locked->transaction) { … cashBack(…) }`. `transaction` est `hasOne(Transaction::class, 'order_id')` (`FrontendOrder.php:175-178`) — **le chemin Mollie ne crée aucune ligne `Transaction`** (lecture intégrale de `Mollie.php` : il n'écrit que la *colonne* `transaction_id`). → condition fausse → **aucun `cashBack`**.

**Résultat** : commande annulée, stock relâché, points remboursés… et **l'argent du client reste chez Mollie sans qu'aucun écran, log ou alerte ne signale qu'un remboursement est dû**. Chargeback assuré.

### Preuve
La garde système équivalente est pourtant bien écrite juste à côté : `FrontendOrderService.php:919-923` (`cancelForFailedOnlinePayment`) exige `payment_status === UNPAID` avant d'annuler. Le chemin **client** (`:801-880`), plus ancien, n'a pas cette symétrie.

### Reco
Ajouter au bloc `:820-830` la garde manquante : refus (422) si `payment_status === PaymentStatus::PAID` **et** `transaction_id` non vide → « commande déjà payée : demandez un remboursement au comptoir ». À défaut, router vers un chemin de remboursement explicite plutôt que vers un `cashBack` silencieusement inopérant.

---

## 🟠 P1-3 — Double encaissement espèces + carte, réécrit en silence par la branche `alreadyPaid`

**Fichier** : `app/Http/PaymentGateways/Gateways/Mollie.php:409-419`

### Scénario reproductible
1. Client commande en ligne, carte, 3DS lancé, puis **abandonne l'onglet** (commande PENDING + UNPAID).
2. Il se présente au comptoir. Le caissier encaisse **en espèces** → `PaymentService::confirmCounterPayment` → `payment_status = PAID`, `pos_payment_method = CASH`, `fiscal_sequence_no` alloué (`PaymentService.php:380-390`), mouvement de tiroir écrit. **`transaction_id` reste NULL** (aucune écriture sur ce champ dans tout le bloc `:380-400`).
3. Dans les ~15 min de validité du paiement Mollie, le client revient sur l'onglet resté ouvert et **finalise le 3DS**. Le webhook `paid` arrive.
4. `Mollie.php:409-419` :
   ```php
   if ((int) $locked->payment_status === PaymentStatus::PAID) {
       if (blank($locked->transaction_id)) {
           $locked->transaction_id = $transactionId;   // ← 'mollie:tr_x'
           $locked->card_type = 'mollie';              // ← sur une vente ESPÈCES
           $locked->save();
       }
       $alreadyPaid = true;
       $event->markProcessed($orderId);
       return;
   }
   ```
   `blank()` est vrai → la vente espèces est **estampillée carte Mollie**.

**Résultat** : le client est débité deux fois (espèces au tiroir + carte), et **cette branche ne loggue rien du tout** — c'est le seul embranchement de `processFetchedPayment` sans ligne `Log::channel('fiscal')`. Elle retourne `already_paid` 200 et enchaîne même sur `finalizePaidKioskOrder`. L'attribution du paiement dans la piste d'audit est corrompue (`card_type='mollie'` contre `pos_payment_method=CASH`).

### Preuve
- Écriture : `Mollie.php:410-414`.
- Absence totale de log sur cet embranchement : comparer avec `:363`, `:380`, `:425`, `:469` qui loggent tous.
- Le sens inverse est, lui, correctement protégé : `PaymentService.php:323-355` lève `PaymentAlreadyCollectedException` (409) si le caissier tente d'encaisser une commande déjà PAID. La dissymétrie est donc bien du côté webhook.

### Reco
Ne **jamais** réécrire l'attribution d'un paiement déjà scellé. Sur `alreadyPaid` avec `pos_payment_method` renseigné (ou `fiscal_sequence_no` déjà alloué), loguer `mollie.webhook.double_payment_detected` en **warning fiscal** avec `order_id` + `payment_id`, ne rien muter, et exposer l'anomalie en caisse (remboursement Mollie à opérer). Un double débit doit être bruyant.

---

## 🟡 P2-1 — Webhook public = amplificateur de requêtes sortantes non authentifié

**Fichiers** : `Mollie.php:197-220` · `routes/api.php:170-172`

**Scénario** : `POST /api/webhook/mollie` est public (à raison — Mollie ne signe pas). La seule barrière avant l'appel réseau est la regex `Mollie.php:198` `^tr_[A-Za-z0-9]{5,64}$`, triviale à satisfaire. Chaque requête déclenche **un GET sortant vers l'API Mollie authentifié par notre clé**, avec `timeout(15)` (`:207-210`). Le dédup (`:250`) ne peut pas protéger : il a besoin du statut **déjà fetché** pour construire sa clé. Un attaquant force donc 60 GET/min/IP (`throttle:60,1`), multipliable par le nombre d'IP, chacun occupant un worker PHP jusqu'à 15 s → épuisement du pool FPM + consommation du quota API Mollie, sans aucun compte.

**Preuve** : ordre d'exécution `Mollie.php:197` (regex) → `:207` (fetch) → `:250` (dédup). Le 404 Mollie (`:222-232`) n'est pas mis en cache négatif : la même chaîne se rejoue à chaque requête.

**Reco** : cache négatif court (60 s) sur les `tr_` inconnus (404), `timeout(5)` au lieu de 15, et throttle resserré (`throttle:20,1`) — Mollie ne rejoue jamais à ce rythme.

---

## 🟡 P2-2 — `checkout_url` suivie sans validation d'origine (open-redirect de 2ᵉ ordre)

**Fichiers** : `Mollie.php:144` · `MolliePaymentController.php:105` · `funnel.jsx:483-486`

**Scénario** : `$checkoutUrl = $payload['_links']['checkout']['href']` est repris **brut** de la réponse API, transmis tel quel au client (`MolliePaymentController.php:105`), et le front l'exécute sans contrôle :
```js
React.useEffect(() => { if (!threeDs) return;
  const t = setTimeout(() => { window.location.href = threeDs; }, 1400); …
```
Aucun `startsWith('https://')`, aucun contrôle de host, nulle part dans la chaîne.

**Exploitabilité** : faible en l'état — l'URL vient d'une réponse authentifiée par notre clé. Mais la surface est réelle : `config/payment.php:117` rend `api_base` pilotable par `MOLLIE_API_BASE`, donc une compromission d'`.env`, un typosquat de valeur ou une réponse altérée transforme un flux « on ne quitte jamais le site » en redirection contrôlée par l'attaquant, **1,4 s après l'écran « Vérification sécurisée »** — c'est-à-dire au moment exact où le client s'attend à voir une page bancaire et va y saisir ses identifiants 3DS. Le phishing est parfaitement cadré.

**Reco** : allowlist d'hôtes côté backend avant de renvoyer `checkout_url` (`*.mollie.com`), et garde miroir dans `funnel.jsx` avant l'affectation de `window.location.href`. Coût nul, supprime toute la classe.

---

## 🟡 P2-3 — Annulation client pendant que le paiement aboutit : argent encaissé sur une vente void, sans piste

**Fichier** : `Mollie.php:421-435`

**Scénario** : le client annule sa commande (onglet A) pendant que son paiement aboutit chez Mollie (onglet B). L'annulation commit d'abord → le webhook `paid` trouve `status = CANCELED` → `terminal_order_refused`, aucun PAID (bon réflexe : pas de vente void dans le Z). Mais **l'argent est bien encaissé chez Mollie** et le commentaire `:427-428` l'assume : *« Remboursement = geste manuel ops (dashboard Mollie) en V1 »*.

**Verrouillage : correct.** Les deux chemins verrouillent la même ligne (`FrontendOrderService.php:810` `lockForUpdate` vs `Mollie.php:357-360` `lockForUpdate`) → sérialisation garantie, pas de double side-effect. **L'angle 2 est donc SAFE sur le plan concurrence** ; le trou est purement opérationnel.

**Le problème** : la seule trace est un `Log::warning('mollie.webhook.paid_on_terminal_order')` en canal fiscal. Rien en caisse, rien dans le dashboard. Personne ne saura qu'un remboursement est dû.

**Reco** : matérialiser la dette. Une ligne dédiée (ou `webhook_events.status='failed'` avec un code stable `REFUND_DUE`) + une pastille en caisse « remboursement Mollie à effectuer ». Même remarque que P1-2 : l'argent qui doit revenir au client ne peut pas vivre uniquement dans un fichier de log.

---

## 🟡 P2-4 — Commande web fantôme jusqu'à 6 h si le webhook n'arrive jamais

**Fichier** : `app/Jobs/CleanupStalePendingKioskOrders.php:188-212`

**Scénario** : client ferme l'onglet avant de payer (ou avant d'annuler). Le paiement Mollie reste `open`. Nominalement Mollie enverra `expired` (~15 min) → le nouveau `cancelForFailedOnlinePayment` fait le ménage : **le cas nominal est couvert par le fix owner**. Mais si le webhook ne parvient jamais (P1-1, panne réseau prolongée, URL webhook mal enregistrée), **aucun mécanisme *pull* ne va interroger Mollie**. La commande reste PENDING/UNPAID, visible en caisse comme une commande à traiter, **stock décrémenté**, jusqu'au filet du janitor à **6 h** (`config('kiosk.stale_web_collect_ttl_minutes', 360)`, `:188`).

**Preuve** : la lane web existe bien et est correcte (`:190-212`, garde NF525 `whereNull('fiscal_sequence_no')` + `payment_status IN (UNPAID, PENDING_COUNTER)` → une commande payée n'est jamais fauchée). Aucune commande/cron ne fait de `GET /v2/payments` de réconciliation : `grep -rn "mollie" app/Console/` → 0 résultat.

**Reco** : une commande `foodking:mollie:reconcile-pending` (toutes les 15 min) qui, pour les commandes web carte PENDING+UNPAID de plus de 20 min portant un `payment_id`, interroge Mollie et applique le statut réel. C'est aussi le vrai filet de sécurité du P1-1. Prérequis : persister le `payment_id` sur la commande — il n'est aujourd'hui **stocké nulle part** côté backend (seulement renvoyé au client, `MolliePaymentController.php:106`), ce qui est en soi une faiblesse de traçabilité.

---

## ✅ SAFE — angles attaqués sans résultat (preuves)

**1. Authenticité du webhook — SAFE.** Le POST n'est jamais cru. `Mollie.php:207-210` re-fetch `GET /v2/payments/{id}` authentifié ; **tout** ce qui est utilisé ensuite (`status` `:245`, `amount` `:337-338`, `metadata.order_id` `:300`) provient de `$fetch->json()`, jamais de `$request`. Seul `id` est lu du body (`:197`), et uniquement comme clé de lookup. Un POST forgé `{"id":"tr_fake","status":"paid"}` → 404 chez Mollie → `unknown_payment_ignored` 200 (`:222-231`). **Impossible de marquer PAID sans avoir réellement payé.**

**Falsification de `metadata.order_id` — SAFE.** Le champ est posé par NOUS à la création (`Mollie.php:127-129`), et la création est verrouillée par propriété : `MolliePaymentController.php:50-52` renvoie 403 si `frontendOrder->user_id !== authenticatedUserId`. Un attaquant ne peut donc pas fabriquer un paiement pointant vers la commande d'autrui. Et il ne peut pas non plus deviner le `tr_` d'un tiers (aléatoire, renvoyé uniquement au propriétaire). **Pas d'annulation croisée par faux webhook.**

**Dédup par statut — SAFE et bien conçu.** `webhook_id = "{payment_id}:{status}"` (`Mollie.php:253`) sur `UNIQUE(provider, webhook_id)` (`2026_05_09_120000_create_webhook_events_table.php:88`). Un attaquant ne peut pas « pré-brûler » la clé `tr_x:paid` pour étouffer le vrai paiement : il faudrait que le fetch retourne `paid`, donc avoir payé. Le `catch` de violation d'unicité (`:262-267`) couvre la course TOCTOU.

**3. Course paid → canceled (webhook retardataire) — SAFE.** L'ordre des branches est correct : `cancelForFailedOnlinePayment` (`FrontendOrderService.php:919-923`) exige simultanément `source_surface='web'` + `payment_method=CARD` + `status===PENDING` + `payment_status===UNPAID`. Une commande PAID (ou ACCEPT) retourne `false` sans mutation. Idempotence au rejeu via `:914-916`. Couvert par `MollieStructureTest.php:364` (`test_webhook_canceled_never_touches_paid_order`) et `:350` (commande acceptée intacte). Le cas réel « 2 paiements pour 1 commande, le `expired` du premier arrive après le `paid` du second » est donc bien absorbé.

**4. Montant — SAFE.** Impossible de payer moins que le total. Le montant envoyé à Mollie est le total scellé backend (`Mollie.php:121` `number_format((float) $order->total, 2)`), jamais une valeur client. À la confirmation, double garde *amount echo* : `Mollie.php:377-392` compare en **centiers entiers** et exige `EUR`, refus strict sinon (`amount_mismatch_refused`, jamais PAID). Le `expected_total` du funnel (`FrontendOrderService.php:580-589`) n'est qu'un témoin `>1 centime` — il ne facture rien, le prix reste 100 % `PricingService`. **Idempotency : pas de rejeu avec un ancien montant** — la clé `web-mollie-<orderId>` (`api.js:706`) est scopée à une commande dont le `total` est immuable ; et le middleware compare le hash du payload (`IdempotencyKeyMiddleware.php:76`, conflit 409 `:86-92`), donc une clé réutilisée avec un corps différent est rejetée, jamais rejouée.

**5. `refundPoints` — SAFE.** Pas de crédit négatif : `LoyaltyService.php:50-52` sort si `$totalPointsToRefund <= 0`, et le montant est `abs()` de la somme des lignes `redeem` réelles (`:41`). Pas de double crédit : détection préalable de la ligne `manual_add` (`:89-100`, NOOP idempotent). Remboursement par porteur réel issu du grand-livre (`:40`), pas via `loyalty_customer_code`. **Sur commande sans points : `:31-33` sort immédiatement (`$redeemTxns->isEmpty()`).**

**Release stock double — SAFE.** `OrderCanceled` est idempotent via `released_qty` (documenté `FrontendOrderService.php:949` et `:872`), et les deux chemins d'annulation sont sérialisés par le même `lockForUpdate`, avec early-return si déjà CANCELED (`:914-916` et `:813-815`).

**Contrôles d'accès du checkout — SAFE.** `MolliePaymentController.php:44-81` : authentification, propriété (403), méthode carte (422), déjà payée (409), statut terminal (422). Rien à redire.

---

## Synthèse pour l'owner

Le correctif 3DS livré est **bon** : les gardes de `cancelForFailedOnlinePayment` sont serrées, symétriques et testées, et les angles les plus payants pour un attaquant (faux webhook « paid », vol/annulation de la commande d'autrui, paiement partiel, rejeu) sont **tous fermés**. L'audit ne trouve aucune régression introduite par ce fix.

Ce qu'il révèle est ailleurs, et suit un motif unique : **le webhook Mollie mute la commande en écriture directe, hors des chemins canoniques du projet.** De là découlent le P0 (pas de scellage NF525, parce que `changePaymentStatus` — qui alloue — est court-circuité), le P1-3 (pas de log, parce que la branche `alreadyPaid` n'a été alignée sur aucun modèle existant) et le P1-1 (pas de DLQ, parce que le provider n'a jamais été enregistré dans l'orchestrateur). Le P1-2, lui, est l'exact symétrique manquant : la garde `payment_status === UNPAID` a été écrite pour le chemin *système*, jamais rétro-appliquée au chemin *client*.

**Deux points appellent une décision owner immédiate**, car ils touchent zone NF525 et argent client :
1. **P0-1** — le paiement carte en ligne est-il activé en production ? Les indices disent oui (`MOLLIE_ENABLED=true`, profil LIVE `pfl_Ymr3Tb6vvp`, `testmode=0`). Si oui, chaque vente web carte encaissée depuis l'activation est hors Z signé. À trancher : élargir le chemin fiscal (gate G-W5) **ou** couper le flux.
2. **P1-2 / P1-3** — trois scénarios distincts font aujourd'hui disparaître de l'argent client sans qu'aucun opérateur ne puisse le savoir. Le dénominateur commun est l'absence de matérialisation de la *dette de remboursement* : elle ne vit que dans `storage/logs`.

Aucun fichier n'a été modifié (audit read-only). Les corrections P0-1 touchent une zone NF525 → **LOCK + gate owner obligatoires** avant tout patch.

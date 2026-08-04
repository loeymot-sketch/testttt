# RED — Paiement en ligne + annulation (Mollie)

**Mission** : réfuter les correctifs posés à `ba4d16a2a` et trouver ce qui reste.
**Posture** : READ-ONLY. Aucun fichier applicatif modifié. Toutes les preuves sont
rejouables (PHPUnit sur le repo, `grep`/`Read` sur les deux dépôts).

- Backend : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt` — HEAD `ba4d16a2a`
- Web déployé : `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne`
- Sondes red-team (scratchpad, NON commitées) :
  `…/scratchpad/RedMollieProbeTest.php`, `RedMollieCancelProbeTest.php`,
  `RedMollieRetryProbeTest.php`, `RedR1BypassProbeTest.php`
  → lancées avec `php vendor/bin/phpunit <chemin absolu>` depuis la racine backend.

---

## Verdict global

**BLOCK.** Le socle « le webhook re-fetché est la seule vérité » tient, les gardes
montant / forgerie / branch-scope tiennent, et 4 des 7 correctifs annoncés tiennent
réellement. Mais **2 P0 monétaires** et **6 P1** restent ouverts, dont **trois qui
prouvent qu'un correctif annoncé est incomplet** :

| Correctif annoncé | Verdict |
|---|---|
| webhook failed/canceled/expired → `cancelForFailedOnlinePayment` | ✅ tient — **mais** crée le P0-1 (annule une commande dont un AUTRE paiement est en vol) |
| carte web PAID → `fiscal_sequence_no` + auto-cuisine | ✅ tient (prouvé : `status=7`, `fiscal_seq=1`) — ferme bien l'angle 6 en config par défaut |
| inline = paid seulement | ✅ tient (`reason:'refused'`, jamais « payé ») |
| R1 accept-guard carte web UNPAID | ❌ **MAL CORRIGÉ** → P1-3 (contournable par `pos-order/change-status`, HTTP 200) |
| DLQ Mollie branché | ✅ tient (le cron n'a pas de filtre provider) |
| idempotency `mollie-checkout` | ❌ **MAL CORRIGÉ** → P1-4 (sentinelle CI **ROUGE**, bypass par omission d'en-tête) |
| écran retour « rien débité » | ❌ **MAL CORRIGÉ** → P1-5 (affiché sur une commande ANNULÉE, avec QR + « Envoyée en cuisine ») |

Compte : **2 P0 · 6 P1 · 3 P2 · 6 REFUTED**.

---

# P0

## [P0-1] Deux paiements pour une commande : le premier annulé TUE la commande, le second payé est REFUSÉ → client débité, commande morte, aucun remboursement

**Fichiers**
`app/Http/Controllers/Frontend/MolliePaymentController.php:62-81` (aucune garde « un paiement est déjà en vol »)
`app/Services/FrontendOrderService.php:908-956` (`cancelForFailedOnlinePayment` — annule sur le statut d'UN paiement, sans jamais regarder les autres)
`app/Http/PaymentGateways/Gateways/Mollie.php:427-441` (`terminal_order_refused`)

**Scénario (aucune concurrence exotique requise)**
1. Le client clique « Payer » → paiement Mollie **A** créé sur la commande N.
2. Il abandonne / clique « Annuler » sur l'écran Mollie, ou revient en arrière.
   La commande N est toujours `PENDING` + `UNPAID` → `checkout()` **ré-autorise** un
   second paiement (`MolliePaymentController.php:62-74` ne teste que `payment_status`,
   il n'existe aucune garde « paiement déjà ouvert pour cette commande »).
3. Il repaie → paiement **B** créé, puis **payé pour de vrai**.
4. Le webhook `canceled(A)` arrive (immédiat sur annulation explicite, ou à l'expiration
   15 min de l'intention ouverte) → `cancelForFailedOnlinePayment(N)` : la commande est
   `PENDING`+`UNPAID` → elle passe **CANCELED**.
5. Le webhook `paid(B)` arrive → `Mollie.php:427` voit `status ∈ [CANCELED…]` →
   `terminal_order_refused`. **La commande reste UNPAID + CANCELED. L'argent reste chez
   Mollie. Aucun remboursement n'est déclenché, aucune tâche n'est créée.**

Le commentaire `Mollie.php:431-434` assume ce trou (« Remboursement = geste manuel ops
(dashboard Mollie) en V1 ») — mais il n'existe **aucune surface** qui le signale à
l'exploitant (cf. P2-11) : la seule trace est une ligne `mollie.webhook.paid_on_terminal_order`
dans le canal `fiscal`.

**Variante encore plus probable — UN SEUL paiement, zéro concurrence** : le client paie,
le webhook est en retard (worker down, blip réseau, rejeu Mollie), l'écran client montre
la commande en attente ; il clique « Annuler ma commande ». `PENDING`+`UNPAID` → annulée.
Le webhook `paid` arrive ensuite → même `terminal_order_refused`.

**Preuve (sortie réelle de la sonde)**
```
[ATTACK1] apres canceled(A): status=16 payment_status=10 resp={"status":"order_canceled_canceled"}
[ATTACK1] apres paid(B):     status=16 payment_status=10 transaction_id=NULL fiscal_seq=NULL
                             resp={"status":"terminal_order_refused"}
[ATTACK6] cancel client http=200 status=16
[ATTACK6] webhook paid TARDIF: resp={"status":"terminal_order_refused"} status=16 payment_status=10 fiscal_seq=NULL
```
`RedMollieProbeTest::test_ATTACK1_cancel_then_paid_leaves_canceled_order_and_kept_money` (vert)
`RedMollieCancelProbeTest::test_ATTACK6_client_cancel_during_delayed_paid_webhook`

**Impact NF525 / Z ≠ payout** — `app/Services/Fiscal/ZReportService.php:426` filtre
`payment_status != UNPAID` et `:443` `whereNotIn('status', [CANCELED, REJECTED, RETURNED])` :
cette vente n'entre **dans aucun Z**, ni en positif ni en négatif. Le virement Mollie du
lendemain contient un encaissement qui n'existe nulle part dans la comptabilité.

**Repro manuelle (prod/sandbox)** — commande carte web ; ouvrir le checkout Mollie ;
revenir sans payer ; recliquer « Payer » (2ᵉ paiement) ; annuler le 1ᵉʳ depuis le
dashboard Mollie ; payer le 2ᵉ. Constater : commande `CANCELED`, paiement B `paid`.

---

## [P0-2] Remboursement / chargeback Mollie totalement avalé : la commande reste PAID et scellée dans le Z (P1-A confirmé, toujours ouvert)

**Fichiers**
`app/Http/PaymentGateways/Gateways/Mollie.php:253-283` (clé de dédup `{payment_id}:{status}`)
`app/Http/PaymentGateways/Gateways/Mollie.php:415-425` (branche `alreadyPaid`)
Contraste : `app/Http/PaymentGateways/Gateways/Stripe.php:395-500` (cascade complète `charge.refunded`)

**Scénario** — chez Mollie API v2, un remboursement (total ou partiel) et un chargeback
**ne changent pas le `status` du paiement** : il reste `paid`, seuls `amountRefunded` /
`amountChargedBack` évoluent. Mollie rejoue son webhook avec **le même `id`**.
Notre clé d'idempotence est `tr_x:paid` → la ligne existe déjà → `Mollie.php:275-283`
répond `duplicate_ignored` et **rien n'est lu**. `grep -rn "amountRefunded|chargeback" app/`
ne retourne **aucun consommateur Mollie** (uniquement `Stripe.php:421-422`).

Même si la dédup était contournée, `processFetchedPayment` retomberait sur la branche
`paid` → `alreadyPaid` → `already_paid`, sans jamais toucher `payment_status`.

**Preuve**
```
[ATTACK3] webhook remboursement: resp={"status":"duplicate_ignored"} payment_status=5 fiscal_seq=1
```
`RedMollieProbeTest::test_ATTACK3_refund_webhook_is_deduplicated_order_stays_paid` (vert,
avec `amountRefunded = 11.80 / amountRemaining = 0.00` dans le payload re-fetché).

**Impact** — la commande garde `payment_status = PAID` **et** son `fiscal_sequence_no`,
donc elle compte en **revenu positif** dans le Z signé (`ZReportService.php:426,443`)
alors que l'argent est reparti. Aucune écriture miroir NF525, aucun clawback fidélité,
aucune libération de stock — là où le jumeau Stripe fait les quatre
(`Stripe.php:478-500` : `AuditLogService::write('order.refunded.stripe_dashboard')` →
`RefundCreated` → `payment_status=REFUNDED` + clawback + stock).
**Un remboursement fait au dashboard Mollie est invisible du système. Z > payout,
définitivement.**

**Repro** — encaisser une commande carte web ; rembourser depuis le dashboard Mollie ;
constater `orders.payment_status` toujours `5` et une seule ligne `webhook_events`
(`provider=mollie`).

---

# P1

## [P1-3] MAL CORRIGÉ — la garde R1 « le caissier ne peut plus accepter une carte web UNPAID » est contournable : `pos-order/change-status` accepte (HTTP 200) et recrée le zombie ACCEPT+UNPAID

**Fichiers**
`app/Http/Controllers/Admin/OnlineOrderController.php:139-152` (la garde, présente **uniquement** ici)
`routes/api.php:1117-1119` (`pos-order/change-status`, **sans** garde)
`routes/api.php:1149-1151`, `:1163-1165`, `:1311-1313` (jumeaux online/table/kds)

**Scénario** — le heal ferme la porte de `online-order/change-status` (celle que le CTA
« Accepter » du tracker emprunte, `PosOrdersTrackerComponent.vue:1300`), mais laisse
grande ouverte la route sœur `pos-order/change-status`, utilisée par tout le reste de
l'écran « Commandes » et accessible à tout utilisateur `permission:pos`. Une fois la
commande passée à `ACCEPT`, la garde du webhook (`FrontendOrderService.php:920` exige
`status === PENDING`) ne joue plus : **le client annule au 3DS, la commande reste
ACCEPT + UNPAID pour toujours** — exactement le piège que le commentaire
`OnlineOrderController.php:140-142` dit fermer.

**Preuve (sortie réelle, même commande web+CARD+UNPAID sur les 4 routes)**
```
[R1] online-order (gardé R1)      http=422  status_apres=1  payment_status=10
[R1] pos-order   (tracker caisse) http=200  status_apres=4  payment_status=10   ← BYPASS
[R1] kds-order   (cuisine)        http=422  (expected_status requis — contrat différent)
[R1] table-order                  http=403  (permission)
```
`RedR1BypassProbeTest::test_R1_guard_bypass_via_sibling_change_status_routes` (vert)

**Repro** — `POST /api/admin/pos-order/change-status/{id}` avec `{"status":4}` en tant
qu'Admin/Caissier sur une commande `source_surface=web`, `payment_method=4`,
`payment_status=10`.

---

## [P1-4] MAL CORRIGÉ — l'idempotence de `mollie-checkout` est optionnelle : sentinelle CI **ROUGE** à HEAD, et l'omission de l'en-tête désactive totalement la protection

**Fichiers**
`routes/api.php:1481-1483` (middleware `idempotency` posé)
`config/idempotency.php:36-100` (`required_routes` — **`mollie-checkout` absent**)
`app/Http/Middleware/IdempotencyKeyMiddleware.php:52-59` (`$key === ''` + route non requise ⇒ `return $next($request)` silencieux)

**Scénario** — le middleware ne protège que les clients qui *veulent bien* envoyer
`X-Idempotency-Key`. Le bundle web déployé l'envoie (`api.js:706`,
`idempotencyKey: 'web-mollie-' + orderId`), mais **n'importe quel appelant qui l'omet**
(bundle en cache d'une version antérieure, appel direct, futur client mobile, curl)
traverse la route sans aucune dédup → un retry sur timeout crée un **2ᵉ paiement réel**
(avec `card_token`, la création DU paiement **EST** l'encaissement — c'est le risque
que le commit `a40d7e617` prétend fermer).

**Preuve n°1 — la sentinelle CI est ROUGE à HEAD `ba4d16a2a`** :
```
$ php vendor/bin/phpunit tests/Feature/Idempotency/IdempotencyRequiredRoutesCoverageTest.php
1) …::test_every_idempotency_wired_route_appears_in_required_routes
Routes with `idempotency` middleware NOT in config('idempotency.required_routes'):
  - api/frontend/order/{frontendOrder}/mollie-checkout
FAILURES! Tests: 1, Assertions: 2, Failures: 1.
```
`git show a40d7e617 --stat` confirme que ce commit n'a touché **que** `routes/api.php` :
la sentinelle est rouge depuis le 2026-08-03 et n'a pas été vue.

**Preuve n°2 — sans en-tête, deux POST = deux paiements Mollie** :
```
[ATTACK2] payment_id#1=tr_ATT2_1 payment_id#2=tr_ATT2_2 sentCount=2
```
`RedMollieProbeTest::test_ATTACK2_no_header_creates_two_mollie_payments_for_one_order` (vert,
`idempotency.enabled=true`).

**Preuve n°3 — le test de non-régression existant ne couvre pas ce cas** :
`tests/Feature/Payment/MollieStructureTest.php:195-215`
(`test_checkout_same_idempotency_key_creates_single_mollie_payment`) envoie
explicitement `X-Idempotency-Key`. Il prouve le chemin où le client coopère, pas la garde.

**Repro** — `curl -X POST …/mollie-checkout` deux fois **sans** `X-Idempotency-Key`.

---

## [P1-5] MAL CORRIGÉ — l'écran « ta carte n'a pas été débitée / ta commande est bien envoyée » + QR + « ✓ Envoyée en cuisine » s'affiche sur une commande que le webhook vient d'ANNULER

**Fichiers**
`api.js:706` (clé d'idempotence **stable** `'web-mollie-' + orderId`)
`app/Http/Middleware/IdempotencyKeyMiddleware.php:85-92` (payload différent ⇒ **409**)
`funnel.jsx:646-652` (branche `refused` : reste sur la page, propose de réessayer)
`funnel.jsx:659-664` (tout throw ⇒ `cardFallback:true`) → `funnel.jsx:666` `onNext()`
`funnel.jsx:1053-1058` (bandeau « ta carte n'a pas été débitée … Ta commande est bien envoyée »)
`funnel.jsx:1032-1038`, `:1060-1066`, `:1083` (confettis, ✓, QR réel, badge vert « ✓ Envoyée en cuisine »)
`app/Services/FrontendOrderService.php:908-956` (le webhook `failed` annule la commande)

**Scénario déterministe (pas une course)**
1. Carte refusée en synchrone → serveur renvoie `reason:'refused'` (correct, le heal P1-B tient).
   Le client reste sur la page : « Carte refusée. Vérifie tes informations, réessaie… ».
2. Le client corrige sa carte → Mollie Components produit un **nouveau** `card_token`.
3. Il reclique « Payer » : `placeOrder` rejoue la **même** commande N, puis
   `mollieCheckout(N, nouveauToken)` part avec la **même** clé `web-mollie-N` mais un
   **payload différent** → le middleware répond **409 `IDEMPOTENCY_KEY_CONFLICT`**.
4. `api.js:203` lève `{kind:'http', status:409}` → attrapé par `funnel.jsx:659` →
   `cardFallback:true` → `onNext()` → **écran de confirmation**.
5. Pendant ce temps, le webhook `failed` du 1ᵉʳ paiement a **annulé la commande N**.

Le client repart avec un ticket QR, un badge vert « Envoyée en cuisine » et le message
« ta carte n'a pas été débitée — **ta commande est bien envoyée**, tu règles au comptoir »
pour une commande qui est `status=16 CANCELED`. À la caisse : rien.
**Le nouveau jeton carte est jeté ; une carte refusée ne peut pas être réessayée dans la
même session (seule issue : « Payer sur place » — sur une commande annulée — ou un reload).**

**Preuve**
```
[RETRY] 1er (carte refusee) http=200 body={"status":true,…,"inline":false,"reason":"refused"}
[RETRY] 2e (nouveau token)  http=409 body={"success":false,"message":"Idempotency key reused with different payload.",
                                            "code":"IDEMPOTENCY_KEY_CONFLICT"} conflictHeader='true'
[FAILED-WEBHOOK] resp={"status":"order_canceled_failed"} status=16 payment_status=10
```
`RedMollieRetryProbeTest` (2/2 verts)

**Repro** — carte de test Mollie « refusée » (`failed`) sur une commande < 30 € (pas de
3DS), puis retenter avec une carte valide sans recharger la page.

---

## [P1-6] Le client annule sa PROPRE commande carte web PAYÉE **et scellée** : HTTP 200, aucun remboursement, aucune écriture miroir NF525

**Fichiers**
`app/Services/FrontendOrderService.php:801-829` — le seuil ne teste **que** `status`,
jamais `payment_status` : `$cancelableThreshold = $isKioskOrder ? PREPARING : ACCEPT`
`app/Services/FrontendOrderService.php:831-839` — le remboursement est conditionné à
`if ($locked->transaction)` : la relation `FrontendOrder::transaction()`
(`app/Models/FrontendOrder.php:175-178`, `hasOne(Transaction::class)`) est **toujours
vide** pour un paiement Mollie — le webhook n'écrit que la colonne `transaction_id`
(`Mollie.php:444-447`), il ne crée **aucune** ligne `transactions`. `cashBack()` n'est
donc jamais appelé.

**État de la question posée** : *fermé en configuration par défaut, ouvert dans trois cas
réels.* Avec `pos.auto_prepare_on_paid=true` (défaut, `config/pos.php:141-145`), le webhook
promeut la commande jusqu'à `PREPARING(7)` et le seuil TAKEAWAY = `PREPARING` bloque
(vérifié : HTTP 422 « The order already accepted »). Restent ouverts :

1. **`ACCEPT(4)` + PAID + scellée** (si `POS_AUTO_PREPARE_ON_PAID=false`, prévu comme
   « rollback d'urgence sans redéploiement ») : `4 < 7` → annulation acceptée.
2. **`PENDING(1)` + PAID** : atteint quand `finalizePaidKioskOrder` ne promeut pas —
   échec d'allocation fiscale (`FrontendOrderService.php:1446-1478`, la commande reste
   PENDING+PAID avec `fiscal_alloc_error_at`), ou n'importe quelle exception avalée par
   le `catch (Throwable)` du webhook (`Mollie.php:482-489`).
3. **la fenêtre entre le commit du PAID (`Mollie.php:451`) et l'appel de
   `finalizePaidKioskOrder` (`Mollie.php:465`)** — hors transaction.

**Preuve**
```
[ATTACK6b PENDING+PAID+fiscal_seq=41] http=200 status=16 payment_status=5
                                       transaction_row_exists=false fiscal_seq=41
[ATTACK6d ACCEPT +PAID+fiscal_seq=42] http=200 status=16 payment_status=5 fiscal_seq=42
[ATTACK6c PREPARING+PAID]             http=422 status=7  « The order already accepted. »
[ATTACK6e chemin réel complet]        webhook paid → status=7 fiscal_seq=1 ; cancel → 422
```
`RedMollieCancelProbeTest` (5/5). Le jeton utilisé est celui du vrai client web —
`app/Http/Controllers/Auth/GuestSignupController.php:331` :
`createToken('auth_token', ['kiosk:order'], now()->addDays(30))` — donc
`OrderStatusRequest::authorize()` passe avec `status=16` + `reason=customer_request`.

**Impact NF525** — la commande finit `CANCELED` + `PAID` + `fiscal_sequence_no` alloué :
`ZReportService.php:443` l'exclut du chiffre positif et `:483` ne la compte que comme
`preZCancelCount` (compteur d'évidence, **sans** contre-écriture monétaire). Une vente
scellée est donc annulée sans remboursement client **et** sans écriture miroir.

---

## [P1-7] Coupon brûlé définitivement quand le paiement en ligne échoue ou est annulé (P1-D confirmé)

**Fichiers**
`app/Services/FrontendOrderService.php:661-668` — la ligne `order_coupons` est INSÉRÉE
à la **création** de la commande, avant tout paiement.
`app/Services/CouponService.php:439-448` (`limit_per_user`) et `:455-460`
(`max_uses_global`) — les deux comptent `OrderCoupon::where(...)->count()` **sans jointure
sur `orders`** et **sans exclusion** des statuts terminaux.
`database/migrations/2022_11_17_120625_create_order_coupons_table.php:16-27` — la table
pivot n'a **ni** colonne de statut **ni** `voided_at` **ni** soft-delete.
`app/Providers/EventServiceProvider.php:202-209` — la cascade `OrderCanceled` libère
stock / disponibilité / matières premières ; **le coupon n'y figure pas**.
`app/Services/FrontendOrderService.php:925` — `cancelForFailedOnlinePayment` rembourse
bien les points fidélité (`LoyaltyService::refundPoints`), mais ne touche pas au coupon.

**Preuve d'absence** — `grep -rn "OrderCoupon::" app/` retourne exactement
4 × `create`, 2 × `count`, 2 × relations : **zéro `delete`, zéro `decrement`, zéro update**.
`grep -rni coupon app/Observers/` : vide. `grep -rn "releaseCoupon|refundCoupon|revertCoupon"` : vide.

**Repro** — coupon avec `limit_per_user = 1` (ou `max_uses_global = N`) ; commande carte
web l'utilisant ; annuler le paiement sur l'écran Mollie ; le webhook annule la commande ;
recommander avec le même code → **422 `coupon_limit_exceeded`**. Le client a perdu son
coupon sans jamais avoir été servi ; une campagne plafonnée à N peut être épuisée par
N paiements abandonnés.

*Condition* : la garde ne se déclenche que si `limit_per_user > 0` **ou**
`max_uses_global > 0` (défaut `limit_per_user = 0` = illimité). Aucun test ne couvre la
réutilisation après annulation (`tests/Feature/Coupon/` : 7 fichiers, `grep -rn "cancel"` vide) ;
`CouponMaxUsesGlobalEnforcementTest.php:104-118` **encode** au contraire le comportement
actuel en comptant les lignes brutes sans notion de statut.

---

## [P1-8] Front : trois chemins mènent à l'écran de confirmation (confettis + ✓ + QR + « Envoyée en cuisine ») sans PAID serveur, et le cas `pending` ne sonde **jamais** le serveur

**Fichiers**
`funnel.jsx:653-658` (`pending` / `hosted` → `mollieReturn:'checking'`) puis `:666` `onNext()`
`funnel.jsx:659-664` (toute exception réseau/502/503/409 → `cardFallback`) puis `:666` `onNext()`
`index.html:440-446` (`onNext` ⇒ `setCart([])` + `setRoute('confirm')`)
`funnel.jsx:1020-1094` — `ConfirmationPage` : **aucun `wfvE`/`useEffect`, aucun `getOrder`,
aucun timer** (vérifié par lecture intégrale du corps du composant)
`funnel.jsx:1045-1049` (le ternaire) · `funnel.jsx:1083` (badge « ✓ Envoyée en cuisine », **sans aucune condition**)

**Constats**
1. `reason:'pending'` ⇒ « Vérification du paiement en cours… » **pour toujours** : il n'y
   a aucun polling sur ce chemin (le seul poll du site est celui du retour `?order=`,
   `index.html:294-315`). Panier déjà vidé, QR déjà affiché.
2. `reason:'hosted'` ⇒ **`co.checkout_url` est silencieusement jeté** : le client n'est
   jamais envoyé chez Mollie et voit quand même l'écran de succès.
3. Retour `?order=` après un **abandon/annulation** chez Mollie : le poll s'arrête à
   `tries < 8` (`index.html:309`) ≈ **10,5 s**. Si le webhook n'a pas encore basculé
   `status` à 16, `mollieReturn:'unpaid'` (ou `'unknown'` en cas d'erreur réseau/401)
   retombe sur la branche `else` de `funnel.jsx:1049` :
   « *Ta commande #N est envoyée. Présente ce QR à la caisse pour la récupérer.
   Tu paies sur place.* » — avec confettis, ✓, QR réel et badge vert.
   *(le commentaire `index.html:290` annonce « jusqu'à 5× » alors que le code fait 8 : commentaire périmé)*
4. `index.html:273-278` : `setCart([])` s'exécute sur la **simple présence** de `?order=`,
   avant toute lecture du snapshot et avant tout appel serveur ; puis `:282`
   `if (!pendingRaw) return;` — retour 3DS dans un autre onglet / sessionStorage évincé ⇒
   **panier détruit, aucune route, aucun message, aucun numéro de commande**.

---

# P2

## [P2-9] `ctx.paidOnline` / `ctx.mollieReturn` ne sont jamais réinitialisés → la commande suivante affiche « Paiement confirmé ✓ »

`index.html:447` (`onHome` de `ConfirmationPage`) et `:448` (`TrackingPage`) énumèrent les
champs à remettre à zéro et **omettent** `paidOnline`, `mollieReturn`, `cardFallback`,
`payCanceled`. `funnel.jsx:672` ne remet que `payCanceled`. `placeRealOrder` propage
l'ancien contexte (`...c`).
**Repro** : payer une commande par carte (succès) → « Retour à l'accueil » → nouveau panier
→ choisir **« Payer sur place »** (le bloc Mollie `funnel.jsx:634` est alors sauté) →
`funnel.jsx:1045` évalue `ctx.paidOnline === true` → « **Paiement confirmé ✓ — ta commande
#NOUVELLE est en préparation** » pour une commande UNPAID à régler au comptoir.

## [P2-10] Le refus `amount_mismatch` est terminal, silencieux et invisible (client débité, commande fantôme) — *aucun déclencheur connu à HEAD*

`Mollie.php:383-398` : si `amount` fetché ≠ `orders.total` au moment du webhook, le
paiement est refusé, `event->markFailed`, la commande reste `PENDING`+`UNPAID`. Elle est
alors **exclue de la file caisse** par la garde R1 (`routes/api.php:927-935`) : personne
ne la voit, alors que le client est débité. Le re-drive DLQ rejouera le même écart
jusqu'à expiration de la fenêtre 24 h (`Kernel.php:83`), puis plus rien.
**Partiellement réfuté** : `grep` sur `->total =` / `update(['total'` ne trouve **aucune**
mutation post-création du total (`expected_total`, `OrderRequest.php:170` +
`FrontendOrderService.php:574-581`, n'est qu'un contrôle croisé fail-loud, le prix reste
SSOT `PricingService`). Le trou est donc une fragilité de conception, sans déclencheur
connu aujourd'hui.
```
[ATTACK5] resp={"status":"amount_mismatch_refused"} status=1 payment_status=10
```

## [P2-11] Zéro surface d'exploitation pour les anomalies monétaires Mollie

`grep -rn "paid_on_terminal_order|amount_mismatch|fiscal_finalize_noop|mollie"` sur
`app/Http/Controllers/Admin/PosSystemHealthController.php` et `app/Console/Commands/`
ne retourne **rien**. Les trois états « argent chez Mollie / rien chez nous »
(`Mollie.php:431`, `:386`, `:475`) n'existent que sous forme de lignes dans le canal
`fiscal`. La pastille santé caisse (`/admin/pos/system-health`) ne les remonte pas, aucun
compteur, aucune file de réconciliation, aucun rapprochement payout ↔ Z.
*(cosmétique associée : `app/Console/Kernel.php:88` décrit encore le lane DLQ comme
« (Stripe/SenangPay) » alors que Mollie y est bien inclus — le commande ne filtre pas par
provider, `OutboxWebhookRetryFailedCommand.php:97-101`.)*

---

# REFUTED — attaques qui n'aboutissent pas

| # | Attaque | Pourquoi elle échoue |
|---|---|---|
| R-1 | **Race à 2 process sur `cancelForFailedOnlinePayment`** | `FrontendOrderService.php:910-923` : `DB::transaction` + `lockForUpdate` + court-circuit idempotent sur le `status` **frais** verrouillé. Deux webhooks concurrents sont sérialisés. |
| R-2 | **`paid` puis `canceled` concurrents (ordre paid-d'abord)** | La garde `payment_status !== UNPAID` (`:921`) bloque : une fois le PAID commité (`Mollie.php:444-447`), aucun webhook d'échec ne peut annuler. Seul l'ordre inverse est dangereux (→ P0-1). |
| R-3 | **`paid` ET `canceled` du MÊME `payment_id`** | Impossible chez Mollie (`paid` est terminal) et, même forgé, la clé de dédup est `{id}:{status}` → deux lignes distinctes, chacune passant les gardes de statut. |
| R-4 | **Faire diverger le montant Mollie du total DB depuis le client** | `Mollie.php:117-122` envoie `number_format($order->total,2)` — total scellé backend ; `:383-398` re-compare le montant **confirmé** au total en centimes + devise. `orders.total` est `decimal(19,6)` : pas de dérive d'arrondi. `expected_total` n'entre jamais dans le calcul. |
| R-5 | **Webhook forgé / POST non authentifié** | `Mollie.php:203-248` : format d'`id` strict, re-fetch authentifié obligatoire, 404 ⇒ `unknown_payment_ignored`. Un POST forgé ne peut rien muter. |
| R-6 | **DLQ Mollie non branché / dédup empoisonnée** | `ProcessWebhookEventJob.php:84` route bien `PROVIDER_MOLLIE` ; `handleFromStoredEvent` (`Mollie.php:549-587`) **re-fetche** l'état frais ; `OutboxWebhookRetryFailedCommand.php:97-101` ne filtre pas par provider. Le heal tient. |
| R-7 | **Annuler une commande carte web en PREPARING** | Bloqué 422 (`FrontendOrderService.php:826-829`) — cf. P1-6 pour les fenêtres résiduelles. |
| R-8 | **Carte refusée affichée « payée »** | `Mollie.php:170` + `MolliePaymentController.php:109-120` : `inline` exige `status === 'paid'`. Le heal P1-B tient (le problème est en aval, cf. P1-5). |

---

# Ordre de traitement suggéré

1. **P0-1** — une garde « un paiement Mollie est déjà en vol / déjà payé pour cette
   commande » dans `MolliePaymentController::checkout`, **et** un refus d'annuler dans
   `cancelForFailedOnlinePayment` tant qu'un autre `webhook_events` du même `order_id`
   n'est pas terminal. Sans quoi P1-5 et P1-8 restent des amplificateurs.
2. **P0-2** — lire `amountRefunded` / `amountChargedBack` dans le webhook et brancher la
   cascade `RefundCreated` existante (le patron Stripe est à copier tel quel).
3. **P1-4** puis **P1-3** — deux lignes de configuration/garde, et la sentinelle CI
   redevient verte (elle est le canari qui aurait dû sonner).
4. **P1-5 / P1-8 / P2-9** — le front doit refuser d'afficher un ticket tant que le
   serveur n'a pas confirmé `payment_status = 5` **ou** `PENDING_COUNTER`.
5. **P1-6 / P1-7** — `payment_status` dans le seuil d'annulation client ; jointure sur
   `orders` dans les deux `count()` de `CouponService`.

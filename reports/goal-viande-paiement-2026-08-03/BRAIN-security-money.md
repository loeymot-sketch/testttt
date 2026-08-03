# BRAIN — Audit sécurité + money-path (READ-ONLY) — 2026-08-03

Périmètre : 8 commits backend jamais brain-audités, candidats VPS.
Méthode : `git show` de chaque commit + relecture des fichiers vivants (Read/grep), tentative de réfutation.

## Synthèse des verdicts

| Commit | Sujet | Verdict |
|---|---|---|
| b6dfdfcf5 | Paiement DANS la page (Mollie Components) + identité Prénom Nom | **P1** (double-débit au retry) + 1 note de coordination deploy |
| d2ab26c48 | Fidélité : remboursement PAR PORTEUR (grand-livre) | **SAFE** |
| 97f6d1ed6 | Menu enfant marqueur « ENF » (KDS + ticket) | **SAFE** |
| d945570b0 | Composition lisible + tiroir « VRAIMENT tracé » | **SAFE** avec 1 P2 (« tracé » ≠ « mouvement écrit » si session fermée) |
| f1783ce5e | Zombies commandes à l'avance (plancher 48 h) | **SAFE** |
| aed9919ce | Badge 86 sur board V2 | **SAFE** |
| e4c7aebbb | Cayenne « Viande Hachée » caisse-only | **SAFE** |
| d4dd1ca49 | Déblocage borne (signature visible partout + garde-fou) | **SAFE** |

---

## 1. b6dfdfcf5 — mollie-checkout (card_token) — **P1**

### Ce qui tient (vérifié)
- **Authz / propriété** : `MolliePaymentController.php:44-52` — auth sanctum obligatoire (401 sinon) puis `if ((int)$frontendOrder->user_id !== $authenticatedUserId) → 403`. On ne peut pas payer la commande d'un autre.
- **Gardes d'état** : lignes 55-81 — payment_method=CARD only (422), PAID→409, non-UNPAID→422, CANCELED/REJECTED/RETURNED→422.
- **Montant scellé backend** : `Mollie.php:121` — `'value' => number_format((float) $order->total, ...)`. Le client n'envoie QUE `card_token` (validé regex `^[A-Za-z0-9_\-]{8,190}$`, controller:87). Aucun montant client.
- **PAID = webhook seul** : le controller ne mute jamais payment_status ; `handleWebhook` (Mollie.php:184-293) re-fetch authentifié chez Mollie, idempotence WebhookEvent UNIQUE (provider, webhook_id=`tr_x:status`), lock FOR UPDATE, **garde montant au centime** (Mollie.php:360-375 — mismatch → refus + log fiscal), garde transaction déjà rattachée à une autre commande (:381-390). Intact par ce commit.
- **Fuite d'info réponse** : `{inline, reason, checkout_url, payment_id}` — renvoyé uniquement au propriétaire authentifié de la commande ; `reason` ∈ {3ds, hosted, null}. Rien de sensible.
- Tests neufs réels : `tests/Feature/Payment/MollieStructureTest.php` — inline sans URL + 3DS surfacé, `Http::assertSent` vérifie method=creditcard + cardToken + montant scellé.

### P1 — double-débit possible au retry (nouveau risque introduit par le cardToken)
- **Preuve** : `routes/api.php:1469-1471` — la route `mollie-checkout` n'a QUE `throttle:10,1`. Pas de middleware `idempotency` (contrairement à `order/` store, `change-status`, `payment-confirm` juste au-dessus, lignes 1460-1466). Aucun verrou/état « paiement en cours » par commande.
- **Scénario** : timeout 15 s côté nous alors que Mollie a accepté le paiement (charge serveur-side immédiate avec cardToken) → controller répond 502 → le front fait re-saisir la carte → 2ᵉ POST avec un NOUVEAU token pendant que la commande est encore UNPAID (le webhook n'est pas passé) → **2ᵉ débit réel**. Le webhook du 2ᵉ paiement tombe sur `payment_status=PAID` → chemin `alreadyPaid` (Mollie.php:392-402) : ack silencieux, **l'argent du doublon reste chez Mollie, remboursement = geste manuel**.
- Avant ce commit le risque n'existait pas : créer 2 paiements « hosted » était bénin (le client n'en complétait qu'un). Avec cardToken, **la création EST l'encaissement**.
- **Reco (non appliquée — read-only)** : soit middleware `idempotency` sur la route + clé côté front, soit garde « paiement Mollie ouvert < N min pour cette commande » avant `createPayment`, soit refund automatique dans le chemin `alreadyPaid` du webhook.

### Note de coordination deploy (pas un bug de code)
`GuestSignupEmailOtpRequest.php` exige désormais `first_name`+`last_name` (`required|min:2`). Le SEUL appelant est le site web standalone (repo Vercel séparé) — grep : aucun appelant interne (`routes/api.php:214` seul point d'entrée ; borne/mobile passent par `/verify` SMS dont `VerifyPhoneRequest.last_name` reste **nullable**, vérifié dans le diff). **Si le backend part au VPS avant que le front web n'envoie ces 2 champs, toute inscription email-OTP web casse en 422.** Déployer les deux ensemble (mémoire : le front correspondant existe côté web repo).
- Détail mineur (pas un finding) : clé cache `email_otp_name:<phone>` utilise le phone brut posté, identique au pattern existant `email_otp_email:` — cohérent, pull au verify sur le même champ validé.

**Verdict : P1** (double-débit retry) — le reste du commit est sain.

---

## 2. d2ab26c48 — remboursement fidélité PAR PORTEUR — **SAFE**

- **Code utilisé 2× / 2 porteurs** : `LoyaltyService.php:40-42` — group by `loyalty_transactions.user_id` (grand-livre = SSOT), plus jamais `orders.loyalty_customer_code` (écrasable). Sentinelle `LoyaltyRefundOwnerAndStatusSentinelTest::test_two_loyalty_codes_each_get_their_own_points_back` prouve Alice 200 / Bob 100.
- **Double remboursement / retry** : double protection — pré-check `manual_add` existant par (user, order) (`LoyaltyService.php:89-100`, NOOP idempotent) + index UNIQUE `(user_id, order_id, type)` (migration 2026_03_26_075919). Test `test_refund_stays_idempotent_per_owner` : rejeu → soldes inchangés, exactement 2 lignes reversal.
- **Montants négatifs** : `refundPointsToOwner` garde `$totalPointsToRefund <= 0 → return` (:50-52) et la somme est `abs($t->points)` sur des lignes type=redeem — impossible de créditer du négatif.
- **Porteur supprimé** : `->where('id', $userId)->first()` null → log warning + return (:65-72). Pas de crash, pas de crédit fantôme.
- **withoutGlobalScope(BranchScope) sur User** (:59) : justifié — le porteur vient du grand-livre de CETTE commande (pas d'input client), et les clients sont branch_id=0 alors que l'annulation s'exécute en contexte staff branché. Sans le bypass, le refund raterait le client (= le bug qu'on répare).
- **Migration `2026_08_01_190000`** : périmètre strict (status=1 + loyalty_code non null + branch_id=0 + AUCUN rôle) — aucun staff réactivable ; status=1 est la valeur de création legacy de LoyaltyController, pas une valeur de bannissement. `down()` no-op assumé et documenté.
- ConcurrentOrderTest durci (2ᵉ commande refusée, jamais 5xx, EXACTEMENT 1 débit) — assertion renforcée, pas affaiblie.

**Verdict : SAFE.**

---

## 3. 97f6d1ed6 — marqueur ENF — **SAFE**

Display-only : `KitchenTicketSymbolicFormatter.php` (+10) et `resources/js/helpers/kdsSymbolic.js` (+9), appliqué à l'identique aux deux jumeaux (contrat parité ticket==écran), tests des deux côtés + assertion « jamais identique à l'adulte ». Aucun prix, aucun état de commande, aucune requête. La fixture de parité PHP a été régénérée par le commit suivant `0e2e45860` (déjà dans l'historique — piège mémoire couvert).

**Verdict : SAFE.**

---

## 4. d945570b0 — tiroir tracé + composition — **SAFE (1 P2)**

- **Aucun nouveau chemin d'écriture** : le commit ne touche QUE le frontend (`PosComponent.vue`, langues, 1 spec JS). Il appelle l'endpoint EXISTANT `POST admin/pos/cash-drawer/open` → `CashDrawerController::open` (`app/Http/Controllers/Admin/Pos/CashDrawerController.php:25-77`) : `permission:pos`, mouvement `TYPE_DRAWER_OPEN` **amount=0.0** rattaché à la session ouverte via `CashDrawerService::recordMovement` (qui écrit audit_logs — chaîne NF525). Rien n'écrit d'argent dans le tiroir ; cash-trail cohérent.
- Composition : `composedVariations/composedExtras` = rendu tolérant aux 2 formes de données, lecture seule.
- **P2** : `CashDrawerController.php:46-65` — si le hardware s'ouvre SANS session ouverte, la réponse reste `success=true` (le mouvement n'est PAS écrit, seul un `Log::warning` part) ; le front (`traced = data.status===true || data.success===true`) affichera alors « tracé » à tort. Fenêtre étroite (no-sale hors session), mais la promesse « VRAIMENT tracée » n'est vraie qu'en session ouverte. Reco : l'endpoint devrait renvoyer `movement_recorded: bool` et le front conditionner dessus.

**Verdict : SAFE**, P2 cosmétique/forensique ci-dessus.

---

## 5. f1783ce5e — plancher zombies — **SAFE**

- **Plancher présent** : `KitchenDisplaySystemOrderService.php` — `$advanceFloor = now($appTz)->subHours(config('oss.advance_stale_window_hours', 48))` appliqué à la branche advance (`->where('order_datetime', '>=', $advanceFloor)`). Le piège mémoire « branche sans plancher = zombies éternels » est exactement ce qui est réparé.
- **Pas d'exclusion légitime** : pour une commande advance, `order_datetime` = date de retrait (cf. condition existante `< $tomorrowStart` « Today or overdue past dates ») → une commande passée il y a 5 jours POUR aujourd'hui reste visible ; seul un retrait dépassé de >48 h sort de l'écran. Test `KdsAdvanceZombieFloorTest` scelle : retard J-1 visible, zombie 9 j absent, ligne toujours en base (rien supprimé).
- Configurable (`OSS_ADVANCE_STALE_WINDOW_HOURS`), défaut 48 h symétrique élargi du staleFloor 8 h des standards.

**Verdict : SAFE.**

---

## 6. aed9919ce — badge 86 V2 — **SAFE**

Rendu pur (`KdsOrderCard` + sentinelle `tests/js/kdsV2OosBadge.spec.js` qui teste le RENDU), lecture défensive du getter store (pas de crash si module absent), libellé i18n existant. Aucune requête, aucun état.

**Verdict : SAFE.**

---

## 7. e4c7aebbb + d4dd1ca49 — Cayenne viande hachée — **SAFE**

- **Money-path** : les 3 viandes sont des variations @0 (résurrection force `price => 0`, `EnsureCayenneMixteCommand.php`) ; le supplément payant reste l'ItemExtra « Viande supplémentaire » @2,50 INCHANGÉ. DATA-only, aucune commande scellée touchée (composition_snapshot immuable).
- **Idempotence** : ensureVariation par (item, attribut, nom) + résurrection de la ligne soft-supprimée au lieu d'un doublon ; « Viande Hachée » ajoutée au `whereNotIn` du nettoyage (sinon auto-suppression immédiate — piège vu et couvert par test).
- **d4dd1ca49** répare l'incident borne réel (étape obligatoire à 0 option) en rendant la signature `visible_on=null` (toutes surfaces) et ajoute `assertKioskHasAtLeastOneMeat()` qui **throw** si une étape min_select>=1 se retrouve sans option borne — la régression silencieuse devient un échec de déploiement. `down()` no-op assumé (re-cacher re-bloquerait la borne).
- Aucun withoutGlobalScope, aucun frozen file, aucune surface web/API modifiée. Effet de bord assumé et documenté : le site web voit désormais l'étape « Poulet mariné » (1 option) sur le #22 — parité déjà traitée côté web (mémoire 2026-07-31).

**Verdict : SAFE.**

---

## 8. Branch isolation & régressions transverses

- Nouveaux `withoutGlobalScope(BranchScope)` introduits par le lot : **1 seul** (`LoyaltyService.php:59`), justifié ci-dessus (§2). Ceux visibles dans les diffs de contexte (webhook Mollie :340, :381 ; GuestSignupController) sont antérieurs.
- Flux OTP invité : `/verify` (borne/SMS/legacy) inchangé — `VerifyPhoneRequest.last_name` **nullable** (diff b6dfdfcf5), fallback « Guest User » conservé. Seul le canal web `email-otp` durcit — voir note de coordination §1.
- Frozen zones : aucun des 8 commits ne touche un fichier §7 (vérifié sur les stats de chaque diff).

## Actions recommandées avant push VPS
1. **P1 b6dfdfcf5** : ajouter une protection anti double-création de paiement cardToken (idempotency middleware ou garde « paiement ouvert » par commande) — ou a minima décision owner assumée + procédure de refund manuel documentée.
2. **Deploy couplé** : backend b6dfdfcf5 + front web (first/last name) dans la même fenêtre.
3. **P2 d945570b0** : exposer `movement_recorded` dans la réponse drawer-open pour que « tracé » soit toujours vrai.

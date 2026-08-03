# REGISTRE — Parité borne↔web + Synchronisation unifiée →POS/KDS (2026-07-18)

HEAD `f8ef74027`. 3 finders adversaires (parité-logique, sync→POS, sync→KDS), exclusion des findings déjà healés aujourd'hui.

## Réponse directe à l'owner
**La borne et le site web SONT la même logique là où ça compte, et la sync vers POS/KDS EST unifiée — c'est prouvé, pas supposé :**
- Chemin backend PARTAGÉ : les deux → `POST /api/frontend/order` → `FrontendOrderService::myOrderStore` → `PricingService::calculateOrder(forKiosk)`. Le web est traité comme kiosk. Prix/TVA/arrondi/validation/composer/dispo/cross-item/idempotence/fidélité = **byte-identiques** (garanti par le chemin unique, pas par duplication).
- Encaissement caisse : traité par **forme de paiement**, pas par surface → borne et web encaissés à l'identique (fiscal_seq + tiroir + Z), file counter-collect couvre web (mon heal P1-3).
- KDS/OSS : `applyBoardReleaseFilter` **source-agnostique** → web PAID/PENDING_COUNTER libère le board exactement comme une borne ; OSS allowlist inclut web-takeaway ; temps-réel + recall en parité (prouvé DB : #5728 web et #5689 kiosk → KDS=YES OSS=YES à l'identique).

## Divergences RÉELLES (peu nombreuses, ciblées)

**S1 [P3→P1 futur · HEAL SAFE] Jumeau non-COD de P1-3** — `OnlineOrderController.php:146-165`. Le flip PENDING_COUNTER à l'Accept est LARGE (toute web UNPAID) mais le marqueur `COUNTER_DEFERRED` (collectabilité) n'est posé que pour TAKEAWAY+COD. Une web takeaway **non-COD** (carte/null) → board-released en cuisine MAIS invisible à l'encaissement = préparée jamais encaissable. 0 instance vivante (carte web OFF) → **P1 dès activation carte web (V1.0.1)**. Fix : aligner la condition de flip sur la collectabilité (ne board-release via PENDING_COUNTER que ce qui sera encaissable au comptoir).

**S2 [P2 · DÉCISION OWNER] La web n'atteint la cuisine que sur accept manuel caisse** — `FrontendOrderService.php:213-221`. L'auto-accept exige une KioskMachine → la borne va en cuisine automatiquement, la web attend le clic « Accepter » du caissier. V1 (paiement en ligne OFF) → 100 % des web exigent ce clic ; caisse absente = cuisine ne voit jamais la web. Semi-by-design (filet accept/reject en ligne). **C'est LA divergence « web ≠ borne ». Question : auto-accepter la web COD (cuisine instantanée comme la borne) ou garder le filet manuel ?** → escalade, pas de heal unilatéral.

**S3 [P2-symétrique · HEAL SAFE] Coupon surface-restreint contournable au commit** — `FrontendOrderService.php:505` + `OrderService.php:561/1080/1646`. Le pré-check `couponChecking` est surface-aware (correct), mais au commit `resolveCouponById($id,$subtotal,$userId)` est appelé à 3 args → surface=null/branch=null → `isUsableNow` saute les checks surface/branche. Un coupon `surfaces=["kiosk"]` ou `["web"]` devient redeemable depuis n'importe quelle surface en forçant `coupon_id`. Symétrique borne==web (pas une divergence de parité) mais défait la restriction admin. Atteignable (`manual_discount_enabled=true`). Fix : passer surface+branch au commit.

**S4 [P3 latent · HEAL SAFE] Impression serveur gatée `source_surface='kiosk'`** — `PrintKioskKitchenTicketOnOrderCreated.php:35` + `PrintKioskOrderToCounter.php:44`. La web n'est jamais imprimée côté SERVEUR (le pont KDS l'imprime toutes-sources aujourd'hui → dormant). Devient asymétrie live au câblage `PRINT_DRIVER` (borne = serveur+pont, web = pont seul). Fix : élargir la garde kiosk→web/online.

**S5 [P3 · HEAL SAFE] Accept web non-atomique** — `OnlineOrderController.php:167`. Le flip `save()` (PENDING_COUNTER) est hors du `try` qui délègue à `changeStatus` → 2 `save()` sans transaction englobante (la borne, elle, est atomique). Si `changeStatus` jette entre les deux → état incohérent (PENDING+PENDING_COUNTER). Faible probabilité, 0 instance. Fix : envelopper dans une transaction.

## Landmines / asymétries documentées (non-bloquantes)
- **D3** `visible_on=["pos","kiosk"]` sur 112 steps + 2 extras Bol Frites → exclut 'web' mais **jamais réalisé** (web traité surface=kiosk). Landmine si le web bascule un jour sur `surface=web`.
- **D1** projection composer branch-aware borne / NULL web (0 divergence aujourd'hui : 30 profils tous branch_id_scope=NULL ; web ignore le composer backend).
- **D2** `sealForCommit` borne-only (redondance ; les deux recalculent SSOT).

## Plan de heal (max reasoning)
- **HEAL SAFE (go)** : S1 (jumeau non-COD — complète la parité/sync), S3 (coupon surface), S4 (print garde), S5 (atomicité accept). Tous non-frozen.
- **ESCALADE OWNER** : S2 (auto-accept web COD ou filet manuel — décision produit).
- **DOCUMENTER** : D3 landmine (à câbler avec le web `surface=web` futur).

# NUIT Wave A2 — KIOSK flux commande bout-en-bout (na2-kiosk-flow)

HEAD 86e3eee22 · DB foodking_e2e · LIVE 127.0.0.1:8766 · token réel KioskMachine#1/User#1 (ability kiosk:order, branch 1).

## Verdict : SOLID (convergence confirmée). 0 P0/P1/P2. 1 P3 durabilité (candidat, overlap Wave A db-growth).

Posture refute-by-default. J'ai tenté de CASSER le flux borne (pairing → quote signé → compo multi-attributs → upsell → seal/commit). Tous les vecteurs sont tenus. Attestation held-green ci-dessous.

## Attaques exécutées + résultat (repro LIVE)

1. **Compo multi-attributs — max_select** : Tacos L (item 97) avec 2 options sous « Viande 1 » (max=1) →
   `422 "Sélectionnez au maximum 1 Viande 1 (actuel : 2)"`. HELD.
2. **Compo — attribut requis omis** : Tacos L sans « Viande 2 » →
   `422 "Sélectionnez au moins 1 Viande 2 (actuel : 0)"`. La parité quote↔store (assertVariationPresenceConstraints, OrderQuoteService:220) tient. HELD.
3. **Extra inactif (status=10)** : ajout extra #256 Salade (INACTIVE) →
   `422 "Supplément ID 256 introuvable"` (PricingService filtre status=ACTIVE). HELD.
4. **Quantité extra abusive** : « Viande supplémentaire » #393 ×5 → subtotal 20,40 = 7,90 + 5×2,50. Quantité honorée exactement. Pas de sous-facturation. (Absence de max sur extras = choix métier, pas un bug.)
5. **Injection cross-item variation** : item 97 avec une variation d'un autre item → rejetée (garde GAP-21-3 côté store `FrontendOrderService:397` + rule côté quote). HELD.
6. **Replay quote signé + items altérés** (cheap→expensive) : même quote_token/signature, items différents (ajout 5× Viande supp) →
   `401 "Order quote intent mismatch"` (resolveReplay recalcule intent_hash depuis la requête courante, OrderQuoteService:430). HELD.
7. **Signature forgée** : quote_token valide + signature 0×64 →
   `401 "Order quote signature mismatch"` (hash_equals sur HMAC APP_KEY, OrderQuoteService:426). HELD.
8. **Seal obligatoire au commit** (revue code) : `FrontendOrderService:550` — tout ordre borne (`$isKioskMachineOrder`) appelle `sealForCommit('kiosk', …)` qui EXIGE quote_token+signature (401 sinon, OrderQuoteService:114-118) puis compare `quote.total_ttc` au total recalculé serveur (409 sinon). Le total commit vient du recompute DB (SSOT, boucle manuelle FrontendOrderService:379-436, prix TOUJOURS DB). Forger un quote cheap → 409. HELD.
9. **Double-submit / idempotence quote** (revue code) : `resolveReplay` fait `lockForUpdate` ; `consume()` (OrderQuoteService:451) rejette 409 si déjà consommé par un autre order_id. + idempotency middleware sur la route store. Double-tap = 2ᵉ order rollback atomique. HELD.
10. **Divergence preview↔commit fidélité/promo** : la remise fidélité est appliquée dans le QUOTE (withKioskLoyaltyDiscount) mais BLOQUÉE au commit par `assertDiscretionaryDiscountAllowed` (F1/TVA-HT gate). → aperçu remisé mais commit 422. **DÉJÀ DOCUMENTÉ** (coupons/fidélité scopés morts, F1 déféré) + KioskPromo table VIDE (0 ligne) → chemin promo inerte. Non re-signalé.

## Constat structurel (attestation)
Le quote HMAC (APP_KEY) ne « protège » pas le prix en soi — c'est le **recompute DB au commit** (SSOT) doublé du **gate 409 total_ttc==total** qui rend toute falsification soit inefficace (prix recalculé) soit bloquante (409/401). Aucune combinaison testée ne produit de sous-facturation ni d'ordre incohérent commité. Les min/max/required sont validés de façon IDENTIQUE sur les 2 surfaces (quote & store) via MultiVariationConstraint. Convergence confirmée : ce 2ᵉ passage profond ne trouve AUCUN nouveau bug de correctness/security/idempotence dans le flux borne.

## Seul point ouvert (P3 durabilité — candidat, possible overlap Wave A)
`order_quotes` n'a **aucun prune programmé** (Kernel n'a que PruneOutbox / PruneWebhookEvents). Table = 3466 lignes depuis 2026-05-28 (~96/j), 591 non consommées. Croissance non bornée. Impact V1 LOCAL mono-poste = négligeable (~35k/an, index présents), donc P3. Si Wave A « db-growth » a déjà couvert cette table → ignorer.

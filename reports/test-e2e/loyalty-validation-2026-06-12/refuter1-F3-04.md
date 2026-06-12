# REFUTER n°1 — F3-04 (handler live modal POS sans filtre user_id)

## Verdict: NON RÉFUTÉ (refuted=false) — finding CONFIRMÉ, sev P2 maintenue. DEDUP interne: = F3-02 (même campagne, F3-sync-setup.md:39).

## Vérifications (toutes exécutées moi-même)

1. **file:line exact** — `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:209-216` lu (Read) :
   handler = `const after = Number(event?.balance_after); if (Number.isFinite(after)) this.customerBalance = after;`
   → AUCUNE comparaison user_id. `data()` (l.229-240) ne contient AUCUN champ customerUserId. CONFIRMÉ.

2. **Réponse redeem POS n'expose pas le user_id client** — `app/Http/Controllers/Admin/PosLoyaltyController.php:66-80` :
   data = `{discount_eur, balance_after, order{id,subtotal,discount,total,loyalty_customer_code}, transaction_id}`.
   Pas de customer user_id. CONFIRMÉ (loyalty_customer_code existe mais pas le user_id ; le payload wire ne porte pas le code → pas de clé de corrélation utilisable côté modal en l'état).

3. **Payload wire contient user_id** — `app/Listeners/PersistLoyaltyBalanceChangedToOutbox.php:47-49` + DB live foodking_e2e :
   `domain_events` #9205 event_type=loyalty.balance_changed payload=`{"delta":7,"reason":"earn","user_id":44,"branch_id":1,"balance_after":177}`. CONFIRMÉ.

4. **Masquage par F3-01 (latence)** — `eventContract.js:62-76` parseEvent retourne `{..., payload: raw.payload}` et `:385 handler(parsed)` → le handler reçoit l'enveloppe avec payload IMBRIQUÉ ; `event.balance_after` (top-level) = undefined → Number(undefined)=NaN → handler inopérant aujourd'hui. CONFIRMÉ.

5. **Abonnement actif (pas doublement latent)** — `PosOrderShowComponent.vue:354-357` passe bien `:branch-id="loyaltyBranchId"` → mounted() s'abonne réellement (le early-return branchId<=0 ne s'applique pas).

6. **Repro empirique (simulation déterministe, script `refuter1-f3-04-sim.cjs`)** avec l'enveloppe réelle (#9205, client B=44) contre un état modal "client A affiché, balance=500" :
   - Handler AS-WRITTEN aujourd'hui → balance reste 500 → **F3-04 LATENT** (masqué par F3-01). 
   - Handler avec F3-01 corrigé naïvement (`event.payload.balance_after`) → balance écrasée à **177 (solde du client B)** → **F3-04 ACTIF**, impossible à filtrer car le modal ne connaît pas le user_id du client lookupé.

## Sévérité

P2 maintenue. Pas un finding multi-tenant/cloud sur-coté : V1 mono-branche AGGRAVE le cas (toute la clientèle sur private-branch.1 → chaque earn borne pendant un modal ouvert collisionne). Display-only (serveur revalide INSUFFICIENT_BALANCE) et latent aujourd'hui, MAIS couplage de fix certain : guérir F3-01 seul ACTIVE ce bug — le P2 protège contre un heal partiel. Recommandation du finding (exposer customer user_id dans la réponse redeem + filtre `payload.user_id`) techniquement correcte.

## Dedup

- = **F3-02** dans `reports/test-e2e/loyalty-validation-2026-06-12/F3-sync-setup.md:39` (même campagne, même file:line, même masquage F3-01, même P2) → l'orchestrateur doit FUSIONNER F3-02/F3-04.
- PAS un dedup release/v1 lots A-H ni dashboard-deep 06-08 : le code en cause est postérieur (commit `e784f9353` loyalty L2 2026-06-11).

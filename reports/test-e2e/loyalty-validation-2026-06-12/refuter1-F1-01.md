# REFUTER n°1 — F1-01 (clone e2e seedé 10 pts/€ vs mandat 1 pt/€)

Date: 2026-06-12 ~03:1x · DB: foodking_e2e (:8767) · Rôle: réfutation adversariale indépendante

## Étape 1 — file:line
- `app/Listeners/AwardLoyaltyPointsOnDelivery.php:84` = `$rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_per_euro', 1);` — VÉRIFIÉ par grep (match exact, défaut code = 1). ✅

## Étape 2 — repro littérale (settings)
- `DB::table('settings')` group=loyalty_setup key=loyalty_points_per_euro → row id=73 payload `{"$cast": null, "$value": 1}`, **created_at = updated_at = 2026-06-12 02:40:05**.
- La repro littérale retourne **1, pas 10** → l'état COURANT du clone ne reproduit plus la valeur 10.
- MAIS : les 3 rows loyalty_setup (id 73/74/75 : per_euro=1, per_discount=100, min_redeem=50) ont TOUTES created_at=updated_at=02:40:05 → réécriture bulk `Settings::group('loyalty_setup')->set([...])` (signature exacte de `app/Console/Commands/SetLoyaltyRatesCommand.php`, delete+insert) survenue EN COURS DE RUN, APRÈS l'enregistrement du finding. Quelqu'un (autre lane / heal) a remis le barème à 1 pt/€ à 02:40:05.

## Étape 3 — preuve résiduelle immuable (ledger loyalty_transactions)
Le taux 10 pts/€ ÉTAIT actif dans le clone — prouvé par les earns antérieurs à 02:40:05 :
- tx#10 · 2026-06-10 23:27:38 · order 4489 total 1,50€ → **+15 pts** (=1,5×10) — cohérent avec le seed clone daté 2026-06-10 23:09:07 cité par le finding.
- tx#18 · 2026-06-12 02:31:50 · order 4520 total 9,00€ → **+90 pts** (orders.loyalty_points_awarded=90) — c'est EXACTEMENT l'évidence du finding, reproduite. ✅
- tx#22-27 · 02:34:40 · orders 4525-4530 ~8,44-8,50€ → 84/85 pts (taux 10 encore actif).
- tx#29 · 02:38:02 · fixture F2 « 1pt/eur » (insert manuel) puis réécriture settings à 02:40:05.

## Étape 4 — verdict
- **refuted = false** — le finding est CONFIRMÉ sur le fond : le clone tournait bien à 10 pts/€ (ledger immuable = preuve), divergent du mandat gate-D1/L1 (1 pt/€, commande `foodking:set-loyalty-rates` créée au GOAL L1 du 2026-06-11 ; clone du 06-10 = antérieur au seed, hypothèse du finding validée).
- Seule nuance (à logger, pas une réfutation) : l'état est DÉJÀ guéri dans le clone depuis 02:40:05 (rate effectif = 1 via facade, vérifié). La repro littérale est périmée, pas fausse.
- DATA flag, pas code : ligne 84 défaut=1 correct ; aucune divergence code. Pas un dedup des lots release/v1 A-H ni dashboard-deep 06-08 (ceux-ci = code/seed ; ici = état data du clone d'audit).
- **Sévérité P3 = juste** (clone jetable, V1 LOCAL mono-poste ; pas de sur-cote SaaS). La reco « vérifier foodking opérante par voie autorisée » reste valide et hors-scope lane — l'escalade conditionnelle « si 10 en prod → P2 data » est raisonnable.

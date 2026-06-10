# HEAL W4-W6 — Rapport de convergence (2026-06-11)
**Branche** : `heal/ultra-audit-w4-2026-06-11` (base `release/v1-2026-06-10` @ `25cb5dac1`)
**Validation live** : serveur :8769 (`APP_ENV=e2e`, DB jetable `foodking_e2e`), utilisateurs dédiés `ultraheal@`/`ultraheal-pos@` (les tokens admin partagés sont révoqués par les re-logins des autres sessions — piège documenté).

## FERMÉ dans cette vague (preuves live + tests)

| Finding | Sévérité | Fix | Preuve live :8769 | Test |
|---|---|---|---|---|
| RED-DASH-02 | **P0** | Guard off-book : → PAYÉ refusé sans trace tender (fiscal_seq/OrderPayment/Transaction), 422 FR + audit | flip 15→5 ordre 4331 → **422** « Encaissement requis… », statut intact | `ChangePaymentStatusOffBookGuardTest` 4/4 |
| CDASH-01 | P1 | `->only('salesReportOverview')` (nom dispatché) + sentinelle corrigée (elle assertait le mauvais nom = green-light du no-op) | caissier → **403**, admin → 200 | `SalesReportOverviewPermissionTest` 2/2 |
| CDASH-02 + RED-DASH-01 | P1+P2 | `resolvePaymentBucketKey` : pos tender = `pos_payment_method` enregistré OU type/source POS | — (couvert test) | `EodPaymentBucketTenderTest` 3/3 |
| CDV-01 | P1 | Fallbacks `attribute_name`/`variation_name`/`extra_name` (OnlineOrderShow, PosOrderShow, OrderDetails partagé) | screenshot ordre 4510 : « Viande 1: Poulet Mariné, Sauce… » affiché | visuel analysé |
| CC-01 / CENTRAL-VIS-P1-01 | P1 | `listGroupByAttribute` passe les modèles Eloquent (stdClass partiels supprimés) | GET 37 + 22 → **200** (avant 500) | `ItemVariationGroupAndUniquenessTest` 3/3 |
| RED-01 | P1 | `Rule::unique` scopée (item, attribut) | PATCH 37/144 nom jumeau → **200** (avant 422) | idem |
| CAISSE-01 + BIS | P1 | **Data-only** : `CaisseBillableUpgradesSeeder` (résolution PAR NOM — Grande Portion/Cheddar Fondu 1,00 € × 4 items frites) active le patch frozen dormant ; garde anti-orphelins sur l'ancien seeder mort (ids 361/402/403) | quote item 2 + 2 upgrades → **4,00 € facturé** (2+1+1) | `CaisseBillableUpgradesSeederTest` 4/4 |
| BORNE-PROMO-01 | P1 | Dormance `kiosk.promos_redeemable=false` (2 branches config — leçon RED-08) : validate refuse FR, bannière menu vide, preview plein tarif | validate → `valid:false` FR ; menu `promos: []` (après purge du cache redis PARTAGÉ chauffé par :8768 — piège documenté) | `KioskPromoDormancyGateTest` 3/3 |
| KDS-OSS-01/KDSVIS-P1-01 | P2 | `isReleasedToKitchen` aligné board (PENDING_COUNTER=released, décision W-D1) + `orderIsReleased` câblé au bump (row lockée) | — | `KdsUnreleasedOrderBumpGuardTest` 5/5 (Plan B préservé) |
| RED-KDS-01 + KDS-OSS-02 | P2+P3 | Même prédicat release sur items-board + feed sync | — | `KdsBoardsReleaseFilterTest` 2/2 |
| F-BV-01 | P2 | `normalizeCurrencyPosition` (enum 5/10 → left/right) | DOM borne : « 2,00 € / 7,00 € / 9,50 € » partout | `kioskFormatPriceFrLocale.spec` 5/5 |
| CDV-04 | P2 | `paymentStatusEnumArray` +PENDING_COUNTER/REFUNDED (6 composants) | — | Vitest global vert |
| CDV-05 | P2 | `total_orders` réel au centre du donut (séries = pourcentages) | — | Dashboard 28/28 |

## OUVERT — gates owner (structurellement non-autonomes)
- **RED-VIANDE-01 (P1)** : viande suppl +2,50 € affichée/encaissée mais non facturée — le wizard frozen mappe des **IDs de variations** vers les checkboxes d'extras (`pos-wizard.js:3883-3894`) : AUCUN seed ne peut l'activer. Fix = patch frozen LOCK_CAISSE-01 **v2** (étendre le pattern by-name du patch Grande/Cheddar) → **gate G2**. Consigne intérimaire caisse : encaisser le montant du MODAL post-quote (= ticket). Idem sauces supp (+0,50 €).
- **CDASH-03 (P1, design)** : ventes POS directes invisibles des Transactions/Vue Caisse — décision A/B owner (**G6**).
- **G5 URGENT** : triggers NF525 sur OVH prod (`SHOW TRIGGERS`) — la DB locale `foodking` est RANCE, le clone e2e n'a pas les triggers.
- **G7** : câblage réel promo borne (inclut RED-PROMO-TAX).

## DÉFÉRÉ (motivé)
- **F-SHARED-02/03 (outbox lane B doublons + storm 8404)** : zone partagée §6 (bus sync) — édition = LOCK + coordination inter-sessions (PARALLEL_PROTOCOL), 3 sessions actives ce jour → défère à une vague dédiée mono-session.
- CDV-02 (datepickers ×19), CDV-03, F-BV-03/04/05, contrastes KDS, 163 clés label.* : backlog W4-design (P2/P3 sans risque d'intégrité).
- F-BV-06 « Upsell item » : descriptions EN = data catalogue (data-ops owner).

## Suites
- PHPUnit ciblé : Order 50/50 · Dashboard 28/28 · Items 18/18 · Pos 90/90 · Kds 43/43 · sentinels payment 20/20.
- Vitest : **311 fichiers / 2128 tests verts** (bruit async pré-existant du KioskWizard frozen en jsdom, fluctuant, 0 échec).
- PHPUnit complet : 3182 tests — 1er run 6 erreurs/5 échecs sur DB test PARTAGÉE (suspicion flakes inter-sessions, PosSimulationHardware re-run isolé 2× vert) ; rerun complet en cours, verdict en annexe.

# REFUTER n°1 — F1-03 (addPoints hasTable guard = crédit solde sans ledger possible)

Date: 2026-06-12 — harnais :8767 / foodking_e2e (clone jetable). Rôle: réfutation adversariale indépendante.

## Verdict: NON RÉFUTÉ — CONFIRMÉ et même RENFORCÉ (repro empirique obtenue, le finding original n'était que lecture de code). Sévérité P3 confirmée.

## 1. file:line — VÉRIFIÉ (Read)
- `app/Http/Controllers/Frontend/LoyaltyController.php:273` — `User::where('id', $user->id)->increment('loyalty_points', $pointsToAdd);` INCONDITIONNEL, dans `DB::transaction` (:271).
- `:278` — `if (\Illuminate\Support\Facades\Schema::hasTable('loyalty_transactions')) {` — insert ledger :279-290 CONDITIONNEL.
- Seules 2 occurrences hasTable dans le fichier: :278 (writer addPoints) et :575 (lecture history, fallback orders — PAS un writer, ne contredit pas le claim "seul writer optionnel").

## 2. Claim "SEUL writer produit avec ledger optionnel" — VÉRIFIÉ (grep app/)
Writers ledger inconditionnels confirmés:
- `app/Listeners/AwardLoyaltyPointsOnDelivery.php:126` — insert ledger même transaction, sans garde (Read :115-140).
- `LoyaltyController.php:211` (welcome) et `:402` (redeem) — `LoyaltyTransaction::create` sans garde.
- `app/Services/LoyaltyService.php:99` (refund) et `:188` (clawback) — sans garde.
- `app/Services/Loyalty/PosRedemptionService.php:180` — sans garde.
- `app/Services/FrontendOrderService.php:920` — sans garde.
addPoints :278 est bien l'unique writer avec ledger conditionnel.

## 3. REPRO EMPIRIQUE (plus forte que la repro du finding original, qui était lecture-seule)
Endpoint réel: `POST /api/frontend/loyalty/add-points` (routes/api.php:1433, auth:sanctum + x-api-key + rôle staff contrôleur :248). Cible: user 44 code VICT1234, pts=165.
- **Contrôle (table présente)**: HTTP 200 "5 points ajoutés", pts 165→170, ligne ledger `manual_add` écrite (count 0→1). Invariant OK aujourd'hui.
- **Test (RENAME TABLE loyalty_transactions → _f103bak)**: `hasTable=false`, puis HTTP **200** "7 points ajoutés", pts 170→**177**, ledger bak **toujours 1 seule ligne** (AUCUNE écriture). → solde crédité SANS trace ledger, silencieusement, là où tout autre chemin aurait jeté une QueryException.
- **Restore**: table renommée en place (`hasTable=true`), ligne ledger de test supprimée, pts user 44 remis à 165, token `refuter-f103` révoqué. Clone propre.

## 4. Sévérité / contexte V1 LOCAL Le Cayenne
- P3 JUSTE: la précondition (table absente) n'existe sur aucun environnement migré — migration `database/migrations/2026_03_26_075918_create_loyalty_transactions_table.php` dans le repo, table présente sur foodking_e2e (21+ lignes). Le finding le déclarait honnêtement ("non reproductible en l'état" — en fait reproductible en sabotant le schéma, ce que j'ai fait sur le clone).
- PAS NF525 (ledger fidélité produit, pas audit_logs/z_reports). Pas multi-tenant/scale. Pure hygiène d'invariant: retirer la garde héritée rend ledger==solde structurel. Recommendation du finding valide.

## 5. Dedup — NÉGATIF
- `goal-2026-05-23/phase-k/K6-loyalty-cascade-findings.json` mentionne hasTable mais sur le sentinel BranchScope (sans rapport).
- `reports/test-e2e/loyalty-validation-2026-06-12/F1-ledger-earn.md:23` = la lane d'ORIGINE de ce finding (même run), pas un lot antérieur. Pas dans release/v1 A-H ni dashboard-deep 06-08.

## Conclusion
refuted=false, corrected_sev=P3 (inchangée). Le mécanisme est réel et désormais prouvé empiriquement; l'impact reste théorique en environnement migré → P3 exact.

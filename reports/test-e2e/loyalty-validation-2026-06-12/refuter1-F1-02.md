# REFUTER n°1 — F1-02 (asymétrie welcome bonus lazy-mint vs register)

Date: 2026-06-12 — Harnais :8767 / foodking_e2e. Mission: réfuter F1-02.

## Vérification file:line (grep/Read)
- `app/Http/Controllers/Frontend/LoyaltyController.php:194-224` — CONFIRMÉ. `$isNewLoyaltyAccount = !$user->loyalty_code` (:194), welcome `loyalty_welcome_points` défaut 25 (:204-206), ledger 'Bonus de bienvenue' (:210-219), event LoyaltyBalanceChanged (:221-223). UNIQUEMENT dans register().
- `routes/api.php:1443` — CONFIRMÉ. `Route::get('/balance', ...)` ; commentaire route :1440-1442 admet lui-même « /balance shares check()'s derivation AND lazy-mints a loyalty_code on lookup ».
- `balance()` = pur délégué à `check()` (LoyaltyController.php:439-442) ; lazy-mint dans check() :108-112 (`if (!$user->loyalty_code) { $user->loyalty_code = strtoupper(...); $user->save(); }`) — AUCUN welcome, AUCUNE ligne ledger sur ce chemin.

## Reproduction INDÉPENDANTE (fixtures fraîches, pas celles du rapporteur)
1. Créé user 72 « Refuter F102 Customer » status=5, phone 0699888777, loyalty_code NULL (tinker e2e) + token sanctum `refuter-f102` (owner-of-account passe le guard IDOR de check()).
2. `GET /api/frontend/loyalty/balance?code=0699888777` (Bearer + x-api-key) → **HTTP 200 `{points:0, discount_value:0, loyalty_code:"6D267EFF"}`** — code minté, PAS de +25.
3. DB post-mint: user 72 `loyalty_points=0`, **ledger VIDE** (0 ligne LoyaltyTransaction).
4. `POST /api/frontend/loyalty/register {phone:"0699888999"}` (x-api-key, route publique throttle:5,1) → **HTTP 200 `{loyalty_code:"C3497AA4", points:25}`**.
5. DB: user 73 `loyalty_points=25`, ledger = exactement 1 ligne `earn +25 'Bonus de bienvenue'` (id 38).

→ Asymétrie REPRODUITE à l'identique avec des données neuves. Le finding N'EST PAS réfutable.

## Cohérence comptable (confirme la nuance du rapporteur)
- u72: sum(ledger)=0 == balance 0 ✓ ; u73: sum(ledger)=25 == balance 25 ✓. Pas de défaut ledger, pas d'impact fiscal/NF525 (points fidélité uniquement).

## Sévérité
- P3 JUSTE: décision produit / cohérence promesse « +25 à l'inscription » pour le client enrôlé au comptoir (mint via lookup). Pas d'impact argent/fiscal, ledger cohérent, V1 LOCAL mono-poste. Pas un finding multi-tenant/cloud sur-coté. Pas P2 (aucune incorrection comptable), pas NONE (l'asymétrie est réelle et prouvée).

## Dedup
- Pas trouvé dans les lots connus (release/v1 A-H, dashboard-deep 06-08). Le welcome +25 lui-même = livraison GOAL Loyalty L1 T-L1.4 2026-06-11 ; l'asymétrie lazy-mint est une conséquence non couverte par ce GOAL (le commentaire L1 ne mentionne que register()). Première occurrence = lane F.1 elle-même (F1-ledger-earn.md Étape 3 note).

## VERDICT: refuted=false, sev confirmée P3.
Artefacts clone jetable: users 72/73, token sanctum 'refuter-f102'.

# Vérificateur adversaire — CAISSE / Tiroir-caisse / cash / Z

## Finding sous revue
[P3] config/pos.php:37 — `simulation_hardware=true` bypasse l'ancrage cash-trail (mouvements non créés) — dev V1-local only, prod boot-guardée.

## VERDICT : REFUTED (en tant que défaut V1-LOCAL) — description factuelle CONFIRMÉE exacte

Le comportement décrit existe réellement, mais ce n'est PAS un défaut actionnable pour V1-LOCAL :
la garde existe déjà (boot-guard prod + surfaçage TRAP-3 au caissier), le SSOT fiscal NF525
est intact, et le trou cash-trail est borné au mode dev mono-poste sans tiroir physique —
exactement comme l'auteur l'a caractérisé. Reco de l'auteur (« AUCUNE action ») = correcte.

## Repro / re-vérification (chaque claim re-testé)

1. **config/pos.php:37** — `'simulation_hardware' => filter_var(env('POS_SIMULATION_HARDWARE', false), ...)`.
   Défaut = `false`. .env dev box = `POS_SIMULATION_HARDWARE=true` (ligne 93), `APP_ENV=local` (ligne 2). ✓ Read confirmé.

2. **Boot-guard prod PROUVÉ LIVE** — AppServiceProvider.php:172-178 `throw new RuntimeException(...)` si `config('pos.simulation_hardware')===true` ET `app()->environment('production')`.
   Commande : `APP_ENV=production POS_SIMULATION_HARDWARE=true php -r '...bootstrap...'`
   → Sortie : `BOOT REFUSED: RuntimeException` / `MSG: POS_SIMULATION_HARDWARE must be false in production (NF525 compliance)`.
   La simulation est donc **physiquement impossible en prod** (refuse de booter, pas de bypass silencieux).

3. **PaymentService.php:481-483** — downgrade strict→soft UNIQUEMENT si `config('pos.simulation_hardware')===true`.
   `recordCashOrderMovement` (:472-548) : sans session ouverte + non-strict → `flagCashMovementSkipped($order)` (:519) puis `return` (pas de CashMovement TYPE_ORDER_PAYMENT écrit). ✓ Read confirmé.

4. **flagCashMovementSkipped PaymentService.php:573-576** — pose un attribut TRANSIENT (`$order->cash_movement_skipped = true`), JAMAIS persisté (aucune colonne DB).
   Surfacé au caissier via OrderDetailsResource.php:80-81 (`cash_movement_skipped` + message FR). C'est le TRAP-3 : le trou est VISIBLE, pas avalé. ✓ Read confirmé.

5. **CashDrawerController** (chemin RÉEL = `app/Http/Controllers/Admin/Pos/CashDrawerController.php`, le finding citait `Admin/` — chemin imprécis mais la plage :46-65 et la substance matchent) :
   drawer-pop sans session ouverte → `Log::warning('[F-7] Hardware drawer pop without OPEN session — forensic gap')` (:60), ne bloque jamais la réponse hardware. ✓ Read confirmé.

6. **Test PosSimulationHardware4ScenariosTest** — `php artisan test ...` → **6 passed** (S1 cash overpay, S2 card, S3 split equal, S4 split mixed, Z KDS, simulation-off).
   NOTE : le finding annonçait « 30/30 » — **count INEXACT** (le fichier contient 6 méthodes de test). Le test existe et passe ; surtout `test_simulation_off_still_requires_open_drawer_for_cash` prouve que **simulation OFF → 422 `CASH_NO_OPEN_SESSION`** (la garde fonctionne dès que le flag retombe à false).

## Evidence DB live (foodking_e2e) — le SSOT fiscal n'est PAS corrompu par le flag

- `cash_movements` type=`order_payment` : **350 rows** (2026-05-28 → 2026-06-25). Le trail cash existe et s'écrit en pratique.
- `orders` CASH payées (pos_payment_method=1, payment_status=1) **SANS** cash_movement : **0**. Pas de gap réel observé sur la branche canonique.
- `fiscal_sequence_no` branche 1 : allocated=2570, min=1, max=2573 → **GAP-FREE** (le petit delta = ordres à allocation différée, normal NF525). Le SSOT fiscal (séquence + audit_logs HMAC) est intact et indépendant du cash-trail.

## Lentille
forensic / cash-reconciliation secondaire. Le flag touche UNIQUEMENT la précondition hardware
(session tiroir ouverte) ; il ne touche ni pricing, ni `fiscal_sequence_no`, ni la chaîne
audit_logs HMAC — qui restent le SSOT NF525. Le cash_movement est un ledger forensique
secondaire, pas le SSOT fiscal.

## Pourquoi pas d'escalade (tentative adversaire de monter la sévérité → échouée)
- Argent/NF525 V1 : aucun. SSOT fiscal prouvé intact (code + DB live gap-free + chaîne HMAC).
- Perte de commande : aucune — la vente passe PAYÉE, fiscal alloué.
- Prod : impossible (boot-guard refuse, prouvé live).
- Silence : non — TRAP-3 surface explicitement le trou au caissier (cash_movement_skipped + message).
La seule « perte » est une sous-estimation du ledger forensique cash en mode dev sans tiroir —
borné, visible, intentionnel, prod-guardé.

## Reco
AUCUNE action V1 (reco de l'auteur confirmée). Le défaut est REFUTED pour V1-LOCAL : toutes les
gardes nécessaires (boot-guard prod + surfaçage TRAP-3 + test simulation-off) existent déjà.
Heal non-frozen : N/A. Racine éventuelle (config flag + PaymentService non-strict) = comportement
intentionnel documenté (config/pos.php:11-16), pas un bug. Corriger « 30/30 »→« 6/6 » dans tout
résumé qui reprend ce finding (détail mineur d'exactitude).

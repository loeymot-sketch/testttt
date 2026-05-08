# PLAN_AUDIT_F010 — BranchScope in Queue Worker Context
**Severity:** P2 — Cross-branche silencieux possible dans jobs
**Owner agent:** Agent E (Architecture)
**Sprint:** S5

## THINK

[app/Models/Scopes/BranchScope.php:27](app/Models/Scopes/BranchScope.php:27) :
```php
if ((!App::runningInConsole() || App::runningUnitTests()) && Auth::check()) {
```

En queue worker (`php artisan queue:work`), `runningInConsole() = true`, `runningUnitTests() = false`, donc le scope ne s'applique pas. Tout `Order::where(...)` dans un Job lit cross-branche silencieusement.

Risque : si un Job mal codé (ex. `Listeners\AwardLoyaltyPointsOnDelivery`) compile des stats par user en utilisant `Order::where('user_id', X)`, il peut additionner les orders du user sur **toutes** les branches, créant une fuite cross-branche dans les analytics ou loyalty totals.

## PLAN

1. Audit tous les jobs/listeners qui touchent `Order` ou `FrontendOrder` :
   ```bash
   grep -rn "Order::\|FrontendOrder::" app/Jobs app/Listeners --include="*.php"
   ```
2. Pour chaque touche : déterminer si l'opération est intentionnellement cross-branche (admin, system) OU branch-scoped.
3. Pour les branch-scoped : forcer `->where('branch_id', $expectedBranchId)` explicitement. Ne pas compter sur le scope auto.
4. Optionnel : créer trait `WithBranchContext` pour injecter le branch_id dans les jobs serialisés.

## BUILD / TEST / SHIP

Détaillé après audit complet (~1 j).

## Contraintes
- ❌ Pas de modification de `BranchScope.php` (changement de comportement large surface).
- ✅ Approche surgical : filtrer explicitement dans chaque job concerné.

## Decision
`continue` après audit listé.

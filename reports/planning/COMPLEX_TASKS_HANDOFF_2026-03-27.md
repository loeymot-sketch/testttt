# Passation Claude — tâches complexes restantes (audit 2026-03-27)

## Déjà traité côté implémentation récente

- `OrderService::tableOrderStore` : champs JSON optionnels (`discount`, `instruction`, variations/extras) alignés sur le flux POS (PHP 8.2 / `stdClass`).
- Migrations « emergency menu » : sortie console silencieuse en `APP_ENV=testing`.
- Tests : `x-api-key` par défaut dans `Tests\TestCase`, permissions Spatie POS/Chef + `dashboard`, `AuthComprehensiveTest` aligné sur `LoginController` (201, statuts 400, `Status::ACTIVE`, `landing_url` rôles).
- Logout API : `Auth::guard('web')->logout()` après login JSON pour ne pas laisser une session web ; `logout()` révoque aussi le PAT via `PersonalAccessToken::findToken($bearer)` si `currentAccessToken()` n’est pas un modèle Eloquent ; même garde sur `KioskMachineLoginController::logout`.

## P1 — À confier à Claude (architecture / périmètre large)

1. **Suite PHPUnit complète (~70+ échecs restants)**  
   - Catégoriser : tests avec `x-api-key` hardcodée `123456` vs `MIX_API_KEY` (`KioskLoginApiTest`, `KioskFrontendComprehensiveTest`, `LoyaltyApiTest`, `OSSReadOnlyTest`, etc.).  
   - Décider : unifier sur `config('app.api_key')` + `TestCase`, ou documenter une clé de test dédiée dans `phpunit.xml`.

2. **`whereRaw("… REGEXP …")` (MySQL) vs SQLite**  
   - `AppServiceProvider::registerSqliteRegexpIfNeeded()` compense en CI ; en production multi-DB, prévoir une abstraction (scope / `when` driver / expression portable) dans `OrderService` + `FrontendOrderService` pour retirer la dépendance au hack PDO.

3. **Migrations emergency (purge menu)**  
   - Elles s’exécutent à chaque `migrate:fresh` : risque prod / onboarding. Décider : archiver dans `database/migrations/disabled`, ou `no-op` si `APP_ENV=production` + flag explicite.

4. **Login web vs API**  
   - Politique explicite : session web jamais ouverte pour les réponses JSON login (déjà `logout()` post-attempt) ; vérifier effets de bord sur tout flux utilisant `Auth::id()` dans la même requête après logout.

5. **Documentation tests**  
   - Mettre à jour `docs/TEST_PLAN.md` / `MASSIVE_TEST_PLAN` : URL admin valide (`/api/admin/dashboard/total-orders` et non `/dashboard`), codes HTTP login (201/400), nom du token Sanctum (`auth_token`).

## Type de tests recommandé pour les prochains lots

- **local-validation** : corrections ciblées de Feature tests + alignement clé API.  
- **Claude** : décision sur REGEXP portable + stratégie migrations emergency + revue sécurité logout / Sanctum stateful si `EnsureFrontendRequestsAreStateful` est réactivé un jour.

# Anti-Gravity Report 006

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Résumé du Cycle (Sprint 4.1)
L'utilisateur a ajouté des clefs de configuration additionnelles dans `TestCase.php` (`order_setup_food_preparation_time`, `order_setup_takeaway`, `order_setup_delivery`) pour tenter de contourner d'éventuels crashs lors de la création / transition des commandes.

**Résultat : 14 réussites, 4 échecs. Le score global stagne, mais les erreurs ont légèrement "bougé".**

## Passed (14)
✅ T02, T03, T04, **T07**, T08, T09, T10, T11, T12, T14, T18, T20, T22, T23.
- **NOUVEAU** : Le test **T07 (Kiosk cannot read pos orders)** PASSE ! L'ajout récent du header `x-api-key` ou des permissions strictes a bien verrouillé cet endpoint contre un accès illegitime par le Kiosk. La faille de sécurité n'est plus.

## Failed (4) - Erreurs Métier et Régressions

1. ❌ **[REGRESSION] T01 (Kiosk login valid)** :
   - Attendu 200, Reçu **400 BadRequest**.
   - **Diagnostic** : C'est nouveau. Le login Kiosk renvoie désormais une erreur 400. Cela arrive souvent si l'API exige un paramètre supplémentaire apparu implicitement, ou si le `branch` ou l'état attendu en base n'est plus compatible avec la récente modification des settings globaux.

2. 💥 **T05 (Kiosk cannot access admin) & T06 (Kiosk can create order)** :
   - Échec / Erreur fatale (Assert false is true dû à l'exception catchée ou erreur 500 ignorée). 
   - La trace complète du testdox précédent indique formellement : `Attempt to read property "faviconLogo" on null`.
   - **Diagnostic confirmée** : L'ajout des settings `theme_favicon_logo` et consorts à `null` en base de données *confirme* que le code actuel n'est pas "null-safe". L'application essaie littéralement d'accéder à `->faviconLogo` en présumant qu'il existe et n'est pas instancié. L'ajout des `order_setup_*` n'a pas solutionné ce problème d'affichage/envoi.

3. ❌ **T13 (Pending to Accept transitions)** :
   - Échec.
   - **Diagnostic**: L'action d'accepter une commande déclenche toujours un crash ou un rejet HTTP non prévu. L'ajout des settings de temps de préparation (food_preparation_time) n'a pas suffi à débloquer ce flux.

## Suggested Next Tasks (For Claude Planning - Sprint 4/5)
La tactique "Data Seeding" atteint ses limites. Il faut maintenant **corriger le code d'application** :
1. **[Claude/Kimi] Fix `faviconLogo`** : Trouver l'utilisation de `faviconLogo` dans les ressources API (ex: `App\Http\Resources\SettingResource`, ou les helpers `app(ThemeSetting::class)`) et appliquer `?->faviconLogo`.
2. **[Claude] Audit de la régression T01** : Tracer pourquoi `POST /api/kiosk/auth/login` renvoie soudainement 400. Y a-t-il une validation stricte sur la machine_id, le branch_id ou l'ip status ?
3. **[Claude] Audit T13 (ACCEPT)** : Explorer le contrôleur `PosOrderController@changeStatus`. L'erreur 500 vient probablement d'un événement `OrderAccepted` qui fail (email, socket, SMS).

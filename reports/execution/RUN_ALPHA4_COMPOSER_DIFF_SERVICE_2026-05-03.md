# RUN_ALPHA4_COMPOSER_DIFF_SERVICE_2026-05-03

## Fichiers créés/modifiés
- `app/Services/Composer/ComposerDiffService.php` — nouveau service de diff draft vs projection/snapshot publié.
- `app/Http/Controllers/Admin/ComposerProfileController.php` — handler `diff`.
- `routes/api.php` — route `GET /api/admin/composer/profiles/{profile}/diff`.
- `tests/Feature/Composer/ComposerDiffServiceTest.php` — 6 cas feature ciblés.

## Validation
- `php artisan test tests/Feature/Composer/ComposerDiffServiceTest.php` : PASS, 6 tests passed.
- `php artisan route:list --path=admin/composer` : BLOQUÉ par une `ReflectionException` hors périmètre, `Class "App\Http\PaymentGateways\Gateways\Senangpay" does not exist`.
- Vérification directe du routeur Laravel via `php artisan tinker --execute=...` : `GET|HEAD api/admin/composer/profiles/{profile}/diff App\Http\Controllers\Admin\ComposerProfileController@diff`.

## Décisions techniques
- Appariement stable par `step_key`.
- Comparaison limitée à la whitelist demandée : `label`, `source_type`, `source_ref`, `source_item_attribute_id`, `min_select`, `max_select`, `allow_repeat`, `visible_on`, `stockable_choices`, `position`, `is_active`, `addon_role`.
- Fallback projection : si aucun snapshot/projection publié exploitable n'est disponible, le service compare contre les steps courants et renvoie `is_clean=true`, conformément au fallback demandé.
- Le modèle actuel ne persiste pas de snapshot publié versionné ; les tests utilisent un attribut transitoire `published_steps_snapshot` pour couvrir les cas add/remove/modify sans migration DB.

## Divergence documentée
- `ComposerProfileProjection` ne retourne pas `source_item_attribute_id` actuellement. Le diff normalise ce champ à `null` quand il est absent d'un snapshot/projection publié ; aucune correction silencieuse du contrat de projection n'a été faite.

## Escalations
- Aucune escalation FoodKing.
- Invariants : pas de pricing frontend/backend, pas d'OrderStatus, pas de modification `branch_id` isolation, pas de dispatch, pas de `OrderService` / `FrontendOrderService`, pas de migration DB.

EXECUTE_DELEGATION: foodking-complex-implementer

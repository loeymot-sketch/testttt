# RUN β-PRE-2 — Backend 409 Version Conflict — 2026-05-03

## Sommaire
Ajout du contrôle optimiste `version` sur update Composer Profile avec réponse HTTP 409 structurée en cas de version périmée, sans casser la rétro-compatibilité des payloads sans `version`.

## Fichiers Touchés
- `app/Http/Requests/ComposerProfileRequest.php`
- `app/Services/Composer/ComposerProfileService.php`
- `tests/Feature/Composer/ComposerProfileVersionConflictTest.php`

## Résultat Tests Composer
- Commande : `php artisan test tests/Feature/Composer/`
- Résultat : PASS — 53 passed, 2 skipped.
- Nouveaux tests : 3/3 PASS.

## Hook Post-Execute
- Commande : `bash .cursor/hooks/post-execute.sh`
- Résultat : FAILED sur la suite globale `php artisan test --stop-on-failure`.
- Échec observé hors périmètre β-PRE-2 : `Tests\Feature\Items\ItemPhotoUploadAtomicityTest::atomic_upload_when_storage_throws_keeps_original_image` attend 500 et reçoit 200.
- Impact β-PRE-2 : aucun échec Composer ; suite Composer verte.

## Évidence Retour 409
Test : `ComposerProfileVersionConflictTest::test_update_with_stale_version_returns_409_with_expected_body`

```json
{
  "message": "Profile version conflict",
  "expected": 3,
  "got": 1
}
```

## Notes Invariants
- I4 dispatch after commit préservé : le check 409 est exécuté avant `DB::transaction(...)`; les dispatchs existants ne sont pas déplacés.
- I3 branch_id : inchangé, aucune logique de scope branche modifiée.
- I6 frozen zones : aucune zone gelée touchée.
- `publish()` n'accepte pas de payload HTTP dans le contrôleur actuel ; le service accepte désormais un payload optionnel pour appliquer le même check si appelé avec `version`, sans impact rétro-compatible.

## Délégation
EXECUTE_DELEGATION: foodking-complex-implementer

## Statut
PASS

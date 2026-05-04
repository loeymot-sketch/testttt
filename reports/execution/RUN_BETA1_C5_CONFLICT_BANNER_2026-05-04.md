# RUN_BETA1_C5_CONFLICT_BANNER_2026-05-04

## Composant `ComposerVersionConflictBanner`

- Créé `resources/js/components/admin/items/composer/ComposerVersionConflictBanner.vue`.
- Props : `isVisible`, `currentVersion`, `expectedVersion`.
- Émission : `reload`.
- A11y : `role="alert"` + `aria-live="assertive"`.
- UI : banner sticky rouge soft avec bouton reload et détail `v{currentVersion} → v{expectedVersion}`.

## Modifications Zone I `ProductComposerEditorComponent.vue`

### `data()`

```js
version: 0,
conflictDetected: false,
expectedVersion: null,
```

### `saveDraft()`

```js
const payload = {
    ...this.profilePayload(),
    version: this.version,
};
```

```js
if (error?.response?.status === 409) {
    this.conflictDetected = true;
    this.expectedVersion = error.response.data?.expected ?? null;
    return;
}
```

- `hydrateProfile()` met à jour `version` depuis le payload API et reset `conflictDetected` / `expectedVersion`.
- `reloadProfile()` reset l’état de conflit puis relance `loadProfile()`.
- `admin-composer-publish` est désactivé par `conflictDetected || publishing`.
- Zone H préservée : `diffModalOpen`, bouton diff et modal slot inchangés fonctionnellement.

## Shape API confirmée

`ComposerProfileController::update()` retourne `new ComposerProfileResource($this->profiles->update(...))`.

`ComposerProfileResource` inclut :

```php
'version' => $this->version,
```

`ComposerProfileService::assertVersionMatches()` retourne un 409 :

```php
[
    'message' => 'Profile version conflict',
    'expected' => (int) $profile->version,
    'got' => (int) $payload['version'],
]
```

## Tests

- `npx vitest run tests/js/composerVersionConflictBanner.spec.js tests/js/composerEditorVersionConflict.spec.js` : PASS, 2 files, 6 tests.
- `npx vitest run` : PASS, 168 files, 1080 tests passed, 2 skipped.

## Statut

PASS.

EXECUTE_DELEGATION: foodking-complex-implementer

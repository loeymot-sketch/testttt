# RUN BETA1 C4 DIFF MODAL — 2026-05-04

## Composant cree

- Chemin : `resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue`
- Props : `profileId`, `isOpen`
- Emits : `update:isOpen`, `confirm-publish`
- Structure : modal accessible `role="dialog"` / `aria-modal` / `aria-labelledby`, fermeture backdrop + Escape, etats loading / error retry / no changes / sections added-removed-modified.
- API : `axios.get('/admin/composer/profiles/{profileId}/diff')`, avec support de payload direct ou encapsule sous `data`.

## Contrat backend confirme

`app/Http/Controllers/Admin/ComposerProfileController.php::diff()` retourne directement :

```php
return response()->json(app(ComposerDiffService::class)->diff($profile));
```

Shape attendue cote Vue : `{ is_clean, added, removed, modified }` sans wrapper obligatoire. Le composant conserve le fallback `response.data?.data ?? response.data`.

## Integration zone H

- `ProductComposerEditorComponent.vue` importe et declare `ComposerPublishDiffModal`.
- `data()` ajoute uniquement `diffModalOpen: false` pour le lot H.
- Footer composer : ajout du bouton `data-testid="admin-composer-show-diff"` a cote de `admin-composer-publish`.
- Template : ajout de `<ComposerPublishDiffModal>` avec `:profile-id="profile.id"`, `:is-open="diffModalOpen"`, `@update:is-open`, `@confirm-publish="publish"`.
- Zone I non touchee : pas de modification de `version`, `loadProfile()`, `saveDraft()`, `publish()` ou banner conflit.

## Tests

- `npx vitest run tests/js/composerPublishDiffModal.spec.js` : PASS, 6/6.
- `npx vitest run` : PASS, 166 files, 1074 passed, 2 skipped.
- Diagnostics IDE : aucun lint error sur les fichiers modifies.

## Trace

EXECUTE_DELEGATION: foodking-complex-implementer

## Statut

PASS

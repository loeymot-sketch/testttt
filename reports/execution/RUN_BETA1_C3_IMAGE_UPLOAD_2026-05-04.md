# RUN_BETA1_C3_IMAGE_UPLOAD_2026-05-04

## Sommaire

Lot J PASS : composant Vue standalone `ItemPhotoUpload` créé avec upload multipart, 5 états UI et validation client miroir backend.

## Composant créé

- Chemin : `resources/js/components/admin/items/ItemPhotoUpload.vue`
- Props : `itemId`, `currentImage`, `maxSizeBytes`, `acceptedMimes`
- Émissions : `upload-success` avec la réponse backend, `upload-error` avec le message d'erreur
- États : `idle`, `dragover`, `uploading`, `success`, `error`
- API : `POST /admin/items/{itemId}/photo`, multipart `photo`, progress via `onUploadProgress`
- Validation client : taille max 4 Mo et MIME `jpg/jpeg/png/webp`, rejet sans appel HTTP si invalide

## Décision intégration

`CatalogStudioComponent.vue` n'a pas été modifié. Le quick-create conserve son `<input type="file">` existant, car cette image part avec la création d'item via `FormData`; le composant standalone est prêt pour une intégration future sur un item déjà existant dans le composer.

## Tests

- `npx vitest run tests/js/itemPhotoUpload.spec.js` : 8/8 PASS
- `npx vitest run --reporter=line` : non exploitable localement (`Failed to load custom Reporter from line`)
- `npx vitest run` : 165 files PASS, 1068 tests PASS, 2 skipped

## A11y

Le composant expose `role="button"`, `tabindex="0"`, `aria-label`, déclenchement clavier Enter/Space et focus ring visible. L'input fichier reste accessible via la zone bouton.

## Statut

PASS

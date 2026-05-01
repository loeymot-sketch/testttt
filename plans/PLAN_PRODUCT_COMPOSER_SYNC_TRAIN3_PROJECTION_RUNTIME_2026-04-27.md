# TRAIN 3 - Projection Runtime POS/Kiosk

TASK_ID: PRODUCT-COMPOSER-SYNC-03-PROJECTION-RUNTIME  
MODE: execute after dashboard contract  
GOAL: POS et kiosk lisent le meme payload composer, designs separes.

## 1. Principe

`MenuProjectionService` devient la projection canonique.

Il doit inclure:

- categories,
- items,
- photos,
- attributes constraints,
- variations,
- extras + group_label,
- addons,
- composer profile,
- composer steps,
- availability,
- stock status si disponible,
- `snapshot_version`.

## 2. Adapters

### POS

- Ne pas refaire le wizard POS.
- Ajouter un adapter qui transforme `composer.steps` vers la structure attendue par `ItemComponent.vue`.
- Si le composer n'existe pas, fallback categorie/legacy.

### Kiosk

- Ne pas casser le design Claude/borne.
- Les steps kiosk utilisent `composer.steps` pour savoir quoi afficher.
- Les composants step gardent leur style mais ne devinent plus par nom si `composer.steps` est present.

## 3. Events sync

Introduire ou standardiser:

- `CatalogChanged`
- payload: `event_id`, `correlation_id`, `branch_id|null`, `entity_type`, `entity_id`, `change_type`, `version`.
- outbox after commit.
- Echo channel conceptuel: `private-branch.{branch}.catalog`.

## 4. Tests

- `CatalogProjectionComposerParityPosKioskSentinelTest`
- `CatalogChangedVersionBumpSentinelTest`
- `KioskComposerStepsRuntimeSpec`
- `PosComposerAdapterSpec`
- `CatalogStaleRefreshSpec`

## 5. Exit

- Ajouter/modifier produit composer depuis dashboard force une nouvelle version catalogue.
- POS et kiosk voient les memes choix.
- Design et navigation restent differents.
- Prix final backend identique.

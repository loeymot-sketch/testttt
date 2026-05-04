# RUN_BETA_PRE_S1_SENTINELS — CV1-V2-CATALOG-REWORK-001 β-PRE-S1

**Date:** 2026-05-03  
**Scope:** Sentinelle i18n `all.studio` + contrat POST création item (quick Catalog Studio vs payload étendu legacy).

## Stratégie i18n

- **Choix : PHPUnit** (`tests/Feature/I18n/StudioKeyParityTest.php`), chargement direct de `lang/{locale}/all.php` via `require` et comparaison des clés du sous-tableau `studio` (flatten récursif en chemins pointés, préfixe `studio.` dans les messages d’échec).
- **Vitest :** non retenu (pas nécessaire ; PHP natif évite un parse regex fragile).

## Résultat parité i18n

- **Statut : PASS**
- Les 5 locales (`fr`, `en`, `de`, `ar`, `bn`) contiennent la clé `studio` et le même ensemble de clés imbriquées au moment du run.
- **Commande :** `php artisan test tests/Feature/I18n/StudioKeyParityTest.php`

## Résultat ItemCreate contract

- **Statut : PASS** (aucun skip)
- **Quick :** POST `/api/admin/item` avec payload aligné sur `CatalogStudioComponent::buildQuickProductPayload` (équivalent JSON, sans image) → `201` + `Item` en base.
- **Full / legacy :** POST avec champs cœur façon `ItemCreateComponent::save` + extensions `ItemRequest` (`channels`, drapeaux, `tax_id`, `kiosk_emoji`, etc.) ; le tiroir « liste » réutilise `ItemCreateComponent` plutôt que `ItemListComponent` pour l’envoi effectif.
- **Commande :** `php artisan test tests/Feature/Items/ItemCreateContractTest.php`

## Statut global

- **PASS :** les deux sentinelles sont en place et exécutables ; parité i18n courante OK ; contrat création item validé sur les deux formes de payload.

## Fichiers livrés (allowlist)

| Fichier | Action |
|--------|--------|
| `tests/Feature/I18n/StudioKeyParityTest.php` | créé |
| `tests/Feature/Items/ItemCreateContractTest.php` | créé |
| `reports/execution/RUN_BETA_PRE_S1_SENTINELS_2026-05-03.md` | créé |

Aucune modification des fichiers `lang/*` (comportement volontaire : détection seule).

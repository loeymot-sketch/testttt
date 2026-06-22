# RUN — Vision cleanup formulaire catégorie (Lot S)

**TASK_ID:** CV1-V2-CATALOG-VISION-CLEANUP-001-S  
**Date:** 2026-05-04  
**Statut:** PASS

## Refactor appliqué

| Avant | Après |
|--------|--------|
| Les 4 champs techniques (`wizard_template`, `has_menu`, deux flags borne upsell/skip) étaient au même niveau que nom / image / statut / description. | Même bloc champs après description, regroupés dans une section « Paramètres avancés » (`$t('studio.advanced_settings')`) repliable via bouton (`showAdvanced: false` par défaut), `form-row` interne pour conserver la grille 2 colonnes. |
| — | Footer Fermer / Enregistrer inchangé, hors section avancée. |
| — | `reset()` remet `showAdvanced` à `false`. |

## Defaults backend vérifiés

| Champ | Source | Défaut / nullabilité |
|--------|---------|---------------------|
| `wizard_template` | `2026_03_12_080617_add_wizard_config_to_item_categories.php` | `default('simple')`, colonne NOT NULL avec défaut SQL |
| `has_menu` | idem | `default(false)` |
| `kiosk_upsell_include` | `2026_03_27_120000_add_kiosk_upsell_flags_to_item_categories_table.php` | `default(true)` |
| `kiosk_upsell_skip_after_cart` | idem | `default(false)` |

Le formulaire côté client envoie déjà les valeurs par défaut en `save` / `reset` ; pas de champ bloquant sans valeur si l’utilisateur ne ouvre pas la section avancée.

## Tests

- Fichier : `tests/js/itemCategoryCreateAdvancedSection.spec.js` — **5 PASS** (drapeau `showAdvanced`, toggle testid, `v-show` + body testid, champs essentiels avant toggle, quatre champs avancés dans le slice body → footer).
- **Vitest globale** : `npm test` — **PASS**, `175` fichiers, `1109` tests passés, `2` ignorés (`skipped`).

## Hors périmètre (cycle suivant)

- Il n’existe pas de fichier `ItemCategoryEditComponent.vue` dans `ItemCategory/` (autres fichiers : `ItemCategoryShowComponent`, `ItemCategoryComponent`, liste, upload). Si l’édition duplique ce formulaire ailleurs, aligner là-bas dans un lot dédié.

## Synthèse

Refactor UX conforme au plan : section repliable + styles scoped + données `showAdvanced` ; backend conforme avec defaults migration ; aucun fichier hors allowlist.

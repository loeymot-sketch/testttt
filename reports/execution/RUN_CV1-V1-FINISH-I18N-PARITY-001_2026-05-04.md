# RUN — CV1-V1-FINISH-I18N-PARITY-001 — 2026-05-04

**EXECUTE_DELEGATION:** `foodking-routine-implementer`

## Scope

- i18n parity: 5 keys × 5 locales (`fr`, `en`, `de`, `bn`, `ar`).
- Sentinel `tests/js/labelKeyParityFrontend.spec.js`: scan dirs extended from 2 → 5 Vue roots.

## Keys added (full strings per locale)

| Key | FR | EN | DE | BN | AR |
| --- | --- | --- | --- | --- | --- |
| `studio.category_wizard_hint` | Ce wizard s'applique à TOUS les produits de cette catégorie. | This wizard applies to ALL products in this category. | Dieser Assistent gilt für ALLE Produkte dieser Kategorie. | এই উইজার্ড এই ক্যাটাগরির সমস্ত পণ্যের জন্য প্রযোজ্য। | ينطبق هذا المعالج على جميع منتجات هذه الفئة. |
| `studio.category_wizard_button` | Wizard de la catégorie | Category wizard | Kategorie-Assistent | ক্যাটাগরির উইজার্ড | معالج الفئة |
| `label.composer.category_context` | Wizard de la catégorie | Category wizard | Kategorie-Assistent | ক্যাটাগরির উইজার্ড | معالج الفئة |
| `label.composer.loading_category` | Chargement du wizard de la catégorie… | Loading category wizard… | Kategorie-Assistent wird geladen… | ক্যাটাগরির উইজার্ড লোড হচ্ছে… | جاري تحميل معالج الفئة… |
| `message.composer.category_inheritance_scope` | Tous les produits de cette catégorie hériteront automatiquement de ce wizard. | All products in this category will automatically inherit this wizard. | Alle Produkte dieser Kategorie erben diesen Assistenten automatisch. | এই ক্যাটাগরির সমস্ত পণ্য স্বয়ংক্রিয়ভাবে এই উইজার্ড উত্তরাধিকারসূত্রে পাবে। | سترث جميع منتجات هذه الفئة هذا المعالج تلقائيًا. |

## Sentinel — directories added

1. `resources/js/components/admin/demo/`
2. `resources/js/components/layouts/backend/`
3. `resources/js/components/admin/settings/`

(Existing: `resources/js/components/admin/items/`, `resources/js/components/admin/ingredients/`.)

## Vitest — parity spec (tail)

```
 ✓ tests/js/labelKeyParityFrontend.spec.js  (1 test) 24ms

 Test Files  1 passed (1)
      Tests  1 passed (1)
```

## Vitest — full suite (tail)

```
 Test Files  191 passed (191)
      Tests  1149 passed | 2 skipped (1151)
   Duration  ~21s
```

## Build — `npm run dev` (Laravel Mix `development`, tail)

```
✔ Mix: Compiled successfully in 10.60s
webpack compiled successfully
```

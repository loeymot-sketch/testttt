# Menu Heal-Light V2 — Apply Report

**Date** : 2026-05-14 00:48 CEST
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**Backup** : `backup/pre-menu-heal-v2-2026-05-14` + DB dump `storage/backups/menu-heal-v2-2026-05-14/foodking-pre-heal.sql` (md5 a1e8a10ae000ba4d5225301c6d82edf0)
**Spec source** : `reports/audit/menu-spec-vs-db-2026-05-13.md` (38 drifts, owner-validated 2026-05-13)
**Command** : `app/Console/Commands/MenuHealLightV2Command.php` (artisan `menu:heal-light-v2 --dry-run --force`)

---

## §1 Statistiques appliquées

| Metric | Count |
|---|---|
| price_updates | 15 |
| items_renamed | 3 |
| items_created | 13 |
| items_archived | 6 |
| categories_created | 2 |
| categories_renamed | 0 |
| variations_renamed | 21 |
| variations_created | 136 |
| variations_archived | 14 |
| extras_updated | 44 |
| extras_created | 92 |
| profiles_created | 8 |
| steps_created | 32 |
| addons_created | 17 |
| events_fired | 21 |

---

## §2 Détail par section spec

### A. PRIX UPDATES (P0 financial — 5 items)
- Sandwich Cayenne (id 474) : 7.00 → **7.50€**
- Sandwich Classique (id 477) : 6.50 → **7.00€**
- Tacos (id 478) → **Tacos M @ 6.90€** (rename + −1.60)
- Big Tacos (id 479) → **Tacos L @ 7.90€** (rename + −3.60)
- Menu addon (id 360) : 3.00 → **2.50€**

### B. NEW SANDWICH ITEMS
- **Big Cayenne** (id 488) @ 9.50€ — 2 viandes, sauce Cayenne LOCKED, INCLUS cheddar+œuf+jambon (description)
- **Big Classique** (id 489) @ 9.00€ — 2 viandes, sauce libre, INCLUS cheddar+œuf+jambon (description)

### C. NEW BURGERS CATEGORY (cat 349 sort=4)
- **Chicken Burger** (id 375, restored from old cat 308) @ 6.90€
- **Big Chicken** (id 490) @ 8.90€ — INCLUS cheddar+jambon+œuf

### D. NEW MENU ENFANT CATEGORY (cat 350 sort=11)
- **Menu Nuggets** (id 491) @ 6.00€ — bundle FIXE (no wizard)

### E. BOWLS RESTRUCTURE
- **5 old bowls archived** (ids 480-484, soft-delete) — preserved for receipt reprint
- **8 new bowls created** @ 8.90€ each :
  - id 492-495 : Bowl Frites × Poulet mariné/curry/tandoori/crispy
  - id 496-499 : Bowl Riz × Poulet mariné/curry/tandoori/crispy
- Each bowl gets composer_profile : sauce + suppléments + drink + gratiné option

### F. SUPPLÉMENTS UPDATE
- 9 suppléments génériques : 1.00 → **0.90€**
- Bacon (id 468) archived
- **Boursin** (id 487) ajouté @ 0.90€
- Oignons frits (id 471) renamed → **Oignon frais**
- Boule gratinée standalone (id 473) : 1.00 → 2.00€ (bol-specific per spec §F)
- Per-item extras : 36 rows price 1.00 → 0.90€, 4 rows renamed, 4 Bacon rows archived

### G. SAUCES VARIATIONS
- **Fromagère → Sauce fromagère maison** : 18 variations renamed
- **Pimentée → Spicy** : 18 variations renamed (covers "ADD Spicy" spec note)
- **Tandoori sauce** : 7 variations archived (it's a viande, not a sauce)
- **Cayenne sauce** : 7 variations archived (it's a sandwich, not a sauce — the "Sauce Cayenne maison" attr 331 still exists as locked-sauce for Sandwich/Galette Cayenne and Big Cayenne)

### H. VIANDE RENAME
- **Poulet classic → Poulet mariné** : 13 variations renamed (all sandwiches/galettes/tacos/burgers)

### I. CONFIG + I18N
- `config/menu.php` : categories block updated 9 → 11 entries with sort reorder
- `mobile/data/menu.js` : full restructure (categories 9→11, items 34→37, prix updates, viandes 4 renamed canonical, sauces 13→11 canonical, suppléments 10→9 canonical)
- **i18n keys** : VÉRIFIÉ no `kiosk.viande.*` / `kiosk.sauce.*` keys exist in `resources/js/languages/fr.json` — labels viande/sauce come from DB via API. Renaming `item_variations.name` (Step H + G) suffit. **Task §I i18n part is intentionally a no-op**.

### J. SYNC EVENTS
- **21 events fired** post-transaction :
  - CategoryCreated × 2 (burgers, menu-enfant)
  - ItemCreated × 13 (5 archived old bowls + 8 new bowls + Big Cayenne/Classique/Chicken + Menu Nuggets + Boursin)
  - ItemDeleted × 6 (5 old bowls + Bacon supp)
- `CatalogChanged` bridges auto-emit (per `CatalogChanged::fromMenuMutation`)

### K. FROZEN-ZONE
- Command writes **DATA-LAYER ONLY** — zero PHP file writes from the command
- 13 protected files (CLAUDE.md §7) untouched by this heal cycle
- `git diff main -- <13 frozen files>` baseline = 6782 lines (pre-existing legitimate edits documented in BRAIN — KioskWizardComponent had prior owner-gated changes pre-heal). **This heal cycle added 0 lines to any frozen file.**
- Verified via `git status --short` post-apply : only `config/menu.php`, `mobile/data/menu.js`, new command + report files modified

---

## §3 Tests verification

### PHPUnit (filter Menu|ItemCategory|PricingService)
- **Menu tests** : 146 passed, 10 skipped, 0 failed
- **PricingService tests** : 42 passed, 1 skipped, 0 failed

### Vitest (kioskMenu* + sentinels)
- **tests/js/kioskMenuBundledExtras.spec.js** : 7/7 PASS
- **tests/js/kioskMenuCache.spec.js** : 9/9 PASS
- **tests/js/kioskMenuStore.spec.js** : 7/7 PASS
- **tests/js/kioskCategoryOrder.spec.js** : 4/4 PASS
- **tests/js/sentinels/** : 119/121 PASS (2 failures pre-existing — `cspMigratedToHttpHeader` + `f008KioskPaymentReconcileQueue` unrelated to menu heal, confirmed via stash diff against HEAD).

### Bundle build
- `npm run development` : ✔ Compiled Successfully (8.64s)
- public/js/app.js, kiosk-shell.js, admin-shell.js, pos-app.js rebuilt

---

## §4 Bowls Flow Decision (Owner §20.5)

**Decision** : Option 8-item (4 viandes × 2 bases).

**Rationale** :
- Spec dit "bowls 2-page flow" : page 1 montre 2 bases, page 2 montre 4 cards (un par viande)
- Option A (2 items + viande step composer_profile) nécessiterait wizard rendering changes inside `KioskWizardComponent.vue` (frozen-zone breach)
- Option B (8 items, viande encoded in item name/slug) reste **data-layer only**, zero frozen wizard touch
- Each bowl gets its own composer_profile : Step 1 sauce (min=1 max=2) + Step 2 suppléments + Step 3 drink + Step 4 gratiné
- Trade-off : kiosk surface affiche 8 items au lieu de 2 — owner peut grouper visuellement via category page UI sans toucher logique

---

## §5 Historical Orders Safety

- **32 historical order rows** référencent items 474, 478, 479, 480, 485 (5 healed items)
- `composition_snapshot` JSON est frozen à la création de l'order — rename Tacos → Tacos M ET price 8.50→6.90 ne casse PAS les receipts existants (NF525 chain preserve §8)
- Soft-delete des 5 vieux bowls (480-484) préserve les rows DB → reprint OK
- NF525 invariants (audit_logs, z_reports, fiscal_sequence_no) NON touchés

---

## §6 Anomalies surfaced

1. **Sauce Cayenne attribute** : `Sauce Cayenne (incluse)` (attr id 331) reste avec 2 variations actives — utilisée par Sandwich/Galette Cayenne et nouveaux Big Cayenne. Cohérent avec spec (sauce locked = pas dans canonical sauce list).
2. **Cat 315 "Frites & Accompagnements"** : reste cachée (channels=`[]`), sort=10 — collision visuelle avec Boissons (sort=10) mais sans impact (rendu filtré par channels).
3. **2 sentinel failures pré-existantes** : `cspMigratedToHttpHeader` + `f008KioskPaymentReconcileQueue` — non liés au heal (vérifié via stash). À tracker séparément.
4. **i18n no-op** : aucune clé i18n `kiosk.viande.*` ou `kiosk.sauce.*` n'existe — labels sortent du DB via API. Spec §I "Update i18n keys" était partial-no-op (rename DB suffit).
5. **Menu enfant placement kiosk** : Cat "Menu enfant" (sort=11) est classé **tier 0** par `kioskCategoryOrder.js` (regex match sur `/menu /` & `/nugget/`). Sur la borne kiosk, Menu enfant apparaîtra avec les mains (position ≈ 7) au lieu de la fin (position 11) malgré sort=11. **Owner sign-off requis** pour décider :
   - Option A : laisser tier 0 (interprétation : "Menu" = main course en français = plat principal) — actuelle
   - Option B : ajouter `enfant|kid|child` à la regex desserts/extras pour tier 2 (placement final)
   - Note : Admin pos sort et POS sort respectent sort=11 sans override. C'est uniquement la borne kiosk qui réordonne.
6. **Frozen-zone verification** : `verifyFrozenZoneClean()` est informational only (log, pas guard) — le command ne fait jamais d'écriture PHP source, donc le risque réel est nul. À considérer pour rewrite en future iter avec stored baseline comparison.

---

## §7 Idempotency verification

**VERIFIED** : 2nd `--force` run produces all-zero stats :
```
price_updates: 0 | items_renamed: 0 | items_created: 0 | items_archived: 0
categories_created: 0 | variations_renamed: 0 | variations_created: 0 | variations_archived: 0
extras_updated: 0 | extras_created: 0 | profiles_created: 0 | steps_created: 0
addons_created: 0 | events_fired: 0
```
Patterns utilisés : `Item::withTrashed()->where('slug',...)->first()` → restore+update si trashed, `firstOrCreate` patterns, `where('price', '!=', ...)`, et `is_published=true + step_count===4` check pour bowl composer profiles.

---

## §8 RESUME_TOKEN_MENU_HEAL_V2_20260514-0048

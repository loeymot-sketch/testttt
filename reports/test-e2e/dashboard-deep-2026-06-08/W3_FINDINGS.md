# Wave 3 — Catalogue + Stock — CONSOLIDATED (static + visual + verified)
**Verdict: YELLOW** (every flow renders, core ops work; one systemic backend 500 + i18n cluster). Clone clean (all deletes cancelled, 1 toggle reverted+server-verified) → no reseed.

## Coverage (DEPTH CONTRACT, 7 pages)
items list ✅ · catalog studio ✅ · ingredients ✅ · item-attributes list ✅ · item-categories list+show-drill ✅ · stock/rupture ✅ (best-designed page) · /admin/stock → clean FR 404 (not a route; stock lives in /rupture).

## FINDINGS (verified)
- **[P1] W3-P1-01 Variante tab 500 (every product detail)** — `/admin/items/show/{id}` → `GET /api/admin/item/variation/group-by-attribute/{item}` (route api.php:735 → `ItemVariationController@listGroupByAttribute`) returns **500**, repro 3× (items 27, 48), deterministic. UI masks as "Aucune donnée disponible" empty-state → gérant can't distinguish no-variants from server-fail, can't manage variations from show page. **ROOT CAUSE (verified by code read):** the method's `catch (Exception $e)→422` does NOT catch a `\Error`/`\TypeError` thrown by `itemVariationService->listGroupByAttribute` (or `ItemVariationGroupByAttributeResource` serialization outside the try) → escapes as 500. **Fix:** catch `\Throwable` + surface an error-state; fix the underlying service/data. (Studio + items-list unaffected — they don't call this endpoint.)
- **[P2] English delete dialog (global)** — SweetAlert "Are you sure?/Yes, Delete it!/No, Cancel!" on every Supprimer (items, attributes, categories). Violates FR-only.
- **[P2] Ingredients caption promises a status it doesn't render** — caption "…avec leur statut de disponibilité" but no statut column / no toggle on the page (only read-only usage drawer). Internal inconsistency.
- **[P2] "N°" rendered for "Non" on Oui/Non radios (global)** — items create, item-attributes, item-categories. Degree-sign artifact; should read "Non".
- **[P2] Ingredient usage drawer raw token "attribute:8"** — literal instead of localized "Type: Attribut · 8 usages".
- **[P3]×6** — composer 404 console for profile-less items (UI empty-state correct); report-only CSP frame-ancestors (composer iframe embeds full app at `/`); "ARTICLE (LEGACY)" English badge; raw slugs as subtitles (`crudite`/`supplement_bol`, show-page Taxe="VAT"); accent-stripping (Apercu/meme/Portee/Rafraichi); 1 session-drop anomaly ~7.5min (didn't recur — flag, likely e2e token TTL).

## CONFIRM-KNOWN
- Allergens NOT editable in admin catalogue — CONFIRMED (no field on create, no Allergènes tab on show, no endpoint).
- itemAddon edit path dead/unreachable — CONFIRMED (add-ons = read-only usage entries).

## IMPROVEMENT LIST (gérant lens, priority)
1. (P1) Fix variation endpoint 500 + catch `\Throwable` + real error-state on Variante tab.
2. (P2) FR-localize the global delete dialog + fix "N°"→"Non" (one i18n pass = 2 P2s).
3. (P2) Reconcile ingredients page: add the availability toggle the caption promises (or fix caption).
4. (P2/P3) Replace raw tokens: `attribute:8`, "ARTICLE (LEGACY)", `supplement_bol`/`crudite`, "VAT".
5. (P3) Scope composer drawer iframe to builder only (kills CSP noise + duplicate sidebar).
6. (Polish) Per-row availability pill on items list; category-level bulk rupture on /rupture for service close-outs.

Counts (W3): P0=0 · P1=1 · P2=4 · P3=6. Clone integrity preserved.

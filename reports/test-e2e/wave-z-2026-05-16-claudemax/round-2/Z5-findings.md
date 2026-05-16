# Z5 — Admin Catalogue + Items (Findings, Round 2)

**Date** : 2026-05-16
**Auditor** : RED-team read-only sub-agent (Wave Z, Round 2 convergence)
**HEAD** : `56204f052`
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**Round-1 HEAD** : `c3ba89863`
**Heal commits between rounds** : `7fc62c066` (5a), `7e62f7bbc` (5b), `c9509b3ad` (Sprint 4 RBAC), `fe883b457` (HEAL doc), `d424f8402` (5c), `56204f052` (5d)
**Surfaces** : `/admin/items`, `/admin/categories`, item CRUD, variations, options, image upload, composer profiles

---

## Summary

**Round-2 verdict: Z5 unchanged from Round 1.** All 12 Round-1 findings (0 P0 / 4 P1 / 5 P2 / 3 P3) are still present at the **identical file:line anchors**. Wave-Z heal commits 5a→5d touched delivery / GDPR / cash / auth / outbox-parity / EN i18n — **zero overlap with the Z5 admin-catalogue surface** (admin form, ItemRequest validation, ItemAttribute RBAC, ItemListComponent FR labels).

RED-team scan confirms: no new issue introduced by heals into Z5 surface. The 3 `PersistItem*ChangedToOutbox` listeners modified by commit `d424f8402` are Z8 outbox-parity work that consumes the `ItemAvailabilityChanged` / `ItemExtraAvailabilityChanged` / `ItemVariationAvailabilityChanged` events already emitted by `ItemService` — they do **not** mutate admin form validation, controller guards, or i18n. They are downstream of Z5's domain boundary.

**Z5 is NOT a Wave-Z merge blocker.** All findings remain DEFERRED V1.0.1 per Round-1 disposition.

---

## P0 findings

_None_ — same as Round 1.

---

## P1 findings (all unchanged — DEFERRED V1.0.1)

### P1-Z5-01 — Admin item form has NO `channels` UI (CONFIRMED unchanged)
- `app/Http/Requests/ItemRequest.php:55-56` — `'channels' => ['nullable', 'array']` + `'channels.*' => ['string', 'in:kiosk,pos,web']` **still present, byte-identical**.
- `resources/js/components/admin/items/ItemCreateComponent.vue` — grep `channels` returns zero matches: **no UI field added**.
- Disposition: DEFERRED V1.0.1 (Round 1 verdict holds).

### P1-Z5-02 — `barcode` + `kds_station` not in ItemRequest rules (CONFIRMED unchanged)
- `app/Http/Requests/ItemRequest.php` — grep `barcode|kds_station` returns **zero matches**. No validation key added.
- `app/Models/Item.php` — `barcode` and `kds_station` still fillable + cast.
- Disposition: DEFERRED V1.0.1.

### P1-Z5-03 — Hardcoded FR raw labels in `ItemListComponent.vue` (CONFIRMED unchanged)
- `resources/js/components/admin/items/ItemListComponent.vue:6` still reads `Pilotage catalogue` (raw FR, no `$t()`).
- Lines 7-11 still display `Produits, catégories, offres et disponibilités` and `POS / borne` as raw FR.
- Disposition: DEFERRED V1.0.1. Note: heal `d424f8402` added EN keys to `lang/en/all.php` for Z1-NEW-001 — but **not** for the catalog control-plane strings flagged here. Scope was OSS / outbox / kiosk-quote, not admin catalogue.

### P1-Z5-04 — `ItemAttributeController::index` unguarded (CONFIRMED unchanged)
- `app/Http/Controllers/Admin/ItemAttributeController.php:21` — middleware **still** `permission:settings` with `->only('show', 'store', 'update', 'destroy')`. `index` remains exempt.
- Disposition: DEFERRED V1.0.1 (BRAIN §9 « FormRequest authz scattered → V1.0.1 refactor 88 endpoints » — this is in that backlog).

---

## P2 findings (all unchanged)

- **P2-Z5-05** — `currencyAmountFormat` reads `env('CURRENCY_SYMBOL')` — `app/Libraries/AppLibrary.php:271-277` byte-identical. DEFERRED.
- **P2-Z5-06** — Image upload rules diverge across `ItemRequest:69` / `ChangeImageRequest:27` / `ItemPhotoUploadRequest:17` / `ItemCategoryRequest:44`. No request class modified. DEFERRED.
- **P2-Z5-07** — `ItemCategoryService::destroy` dead FK-disable branch at `app/Services/ItemCategoryService.php:165-193` — file not in diff list. DEFERRED.
- **P2-Z5-08** — No restore route for soft-deleted items/categories — `routes/api.php:647-679` + `:341-351` — not modified. DEFERRED.
- **P2-Z5-09** — `ItemUpdated` event semantic abuse (catch-all via `ItemAvailabilityChanged`) — `app/Services/ItemService.php:336-345` not in diff. DEFERRED.

  Note (RED-team): Z8 outbox heal `d424f8402` adds listeners on `ItemAvailabilityChanged` (`PersistItemAvailabilityChangedToOutbox.php`, plus Extra/Variation siblings). This **reinforces** the design noted in P2-Z5-09 (using availability event as a catch-all data-change broadcast) rather than fixing it. Acceptable — the listeners are correctly idempotent and the semantic-clarity nit remains a V1.0.1 cleanup, not a correctness issue.

---

## P3 findings (all unchanged)

- **P3-Z5-10** — `/items/{item}/photo` URL pluralization inconsistency, `routes/api.php:680`. DEFERRED.
- **P3-Z5-11** — Admin item CRUD has no idempotency middleware. DEFERRED.
- **P3-Z5-12** — `ItemResource::toArray` calls `$this->orders->count()` per item, `app/Http/Resources/ItemResource.php:89`. DEFERRED.

---

## Healed-verified

_N/A — Z5 had no findings in heal scope (Wave-Z heals targeted Z1/Z4/Z6/Z8/Z9/Z10)._ No Z5 finding was scheduled for heal; therefore nothing to re-verify as healed.

---

## Open-from-sister

_None — Z5 was not covered by the sister verdict._

---

## NEW (introduced by heals between Round 1 and Round 2)

_None._

RED-team scope analysis: the diff `c3ba89863..56204f052` (1197 files) **does not modify a single Z5 surface file** — no admin item controller, no `ItemRequest` / `ItemCategoryRequest` / `ItemPhotoUploadRequest` / `ChangeImageRequest`, no `ItemListComponent.vue` / `ItemCreateComponent.vue` / `ItemShowComponent.vue`, no `ItemService.php`, no `ItemCategoryService.php`, no admin item route in `routes/api.php`. The three modified `Persist*Outbox` listeners are downstream Z8 consumers of the existing `ItemAvailabilityChanged` event chain and do not alter Z5 domain behaviour.

Cross-check on heal-introduced regressions in the admin catalog surface: none found.

---

## Notes (informational)

- **Z5 scope frozen between rounds** — no admin catalogue code modified by Wave-Z heals (5a / 5b / 5c / 5d). Round-1 findings remain stable evidence for the V1.0.1 backlog.
- **`PersistItem*ChangedToOutbox` listeners added in `d424f8402`** are correctly defensive: outbox writes are idempotent (UNIQUE on `(aggregate_type, aggregate_id, version)` per Z8 plan), so admin-side double-updates won't generate duplicate outbox rows. This does not change Z5's P3-Z5-11 (no idempotency on admin CRUD), but it does mean downstream consumers are safe even if admin replays.
- **Round-1 anchor spot-check** verified at HEAD `56204f052`:
  - `ItemRequest.php:55-56` channels rule — byte-identical.
  - `ItemRequest.php` — no `barcode` / `kds_station` keys.
  - `ItemAttributeController.php:21` — middleware exempts `index`.
  - `ItemListComponent.vue:6` — raw FR `Pilotage catalogue` still rendered.
- **Frozen-zone respect** — no Z5-surface frozen file touched between rounds.

---

## Verdict

**Z5 is NOT a Wave-Z merge blocker.**

All 12 findings remain DEFERRED V1.0.1 per the Round-1 disposition. The Wave-Z heal cycle (5a → 5d) had no overlap with the admin catalogue surface; no regression introduced, no new issue surfaced.

Convergence outcome for Z5: **stable — close round-2 with same verdict as round-1**.

---

**End Z5-findings.md** — round-2, 2026-05-16

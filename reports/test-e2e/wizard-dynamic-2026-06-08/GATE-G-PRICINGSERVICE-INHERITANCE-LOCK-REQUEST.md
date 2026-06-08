# GATE-G — Frozen-zone LOCK request : PricingService category-inheritance

**Date:** 2026-06-08
**Branch:** `heal/pre-cloud-exec-2026-06-05` (HEAD `cb8125869`)
**Frozen file:** `app/Services/Pricing/PricingService.php` (CLAUDE.md §7 — Pricing SSOT / NF525-critical)
**Requested by:** Claude (ultra-audit W7) — **owner countersign required before applying.**

---

## 1. The defect (CONFIRMED P1, adversarially verified)

`PricingService::assertComposerStepConstraints` (the **server-side** composer-validation guard,
run at `calculateOrder` line 110) resolves the wizard profile by **`item_id` only**:

```php
// app/Services/Pricing/PricingService.php:564-576  (current)
$profiles = ItemWizardProfile::query()
    ->with(['steps' => fn ($query) => $query->where('is_active', true)->orderBy('position')])
    ->whereIn('item_id', $itemIds->all())          // <-- item-owned ONLY, no category fallback
    ->where('is_published', true)
    ->where(function ($query) use ($req): void {
        $query->whereNull('branch_id_scope')
            ->when($req->branchId > 0, fn ($q) => $q->orWhere('branch_id_scope', $req->branchId));
    })
    ->get()
    ->groupBy('item_id')
    ->map(fn (Collection $profiles): ItemWizardProfile => $profiles
        ->sort(fn ($a, $b) => $this->compareComposerProfiles($a, $b))
        ->first());

if ($profiles->isEmpty()) {
    return;                                         // <-- inherited-only items: NO validation
}
```

This session's W7 work added **category-inheritance** to the **four render resolvers**
(`MenuProjectionService`, `KioskMenuService`, `ItemResource`, `NormalItemResource`, commit `0ad1906ff`)
so a published **category** wizard now renders on items that have no own profile. **PricingService was
not — and could not be without this gate — updated to match.** The result is an **asymmetry**:

| Path | Resolves inherited category profile? |
|---|---|
| Render (4 resolvers, non-frozen) | ✅ yes (W7) |
| **Order validation (`assertComposerStepConstraints`, FROZEN)** | ❌ **no — item_id only** |

So for an item that relies on an inherited category wizard, the composer step validation
(**membership** `assertComposerSelectionsBelongToPublishedProfile`, **min/max select**, **allow_repeat**)
is **silently skipped** — an invalid composition is accepted server-side.

### Proof (runnable, committed)
`tests/Feature/Services/Pricing/ComposerStepConstraintTest.php::test_GAP_category_inherited_composer_constraints_are_not_enforced_pending_gate_g`
— the **same invalid composition** (required step empty) is **rejected** on an item-owned profile
but **accepted** on a category-inherited one. 14/14 green (encodes the current/incorrect behavior;
flip to `expectException` when this gate lands).

## 2. Severity = P1, NOT a fiscal P0 (locked)

Price integrity is **intact**: the line price is computed from catalog (`PricingService.php:134-226`,
`$dbItem->price` / `$dbVar->price` / addon catalog price) and the only role-based price reduction —
the kiosk **menu-formula ratio** — is keyed on the **DB `addons.role` column + payload role**
(`CompositionSnapshotBuilder::resolveEffectiveAddonRole` / `menuRoleAdjustedAddonPrice`), which
**never reads `ItemWizardProfile`**. `assertVariationConstraints` (item-attribute min/max) and the
addon existence/availability/visibility guards fire **regardless** of the composer profile. So the
bypass cannot mis-price or breach NF525 — it is a **validation-completeness** gap.

**But "inert in production today" ≠ "not urgent":** the operating DB has **0 published category
profiles**, so nothing is broken right now. This gap (and the render fix) only matter once category
wizards are used — which is exactly GOAL deliverable #3 ("make the category wizard usable"). The
guard is therefore a **required co-shipment with the render fix before the category-wizard feature is
relied upon**, not a nice-to-have.

## 3. Proposed fix (minimal — mirrors the 4 proven render resolvers)

Replace the item-only lookup with item-owned-?? -category-owned resolution (item-owned wins,
same branch-scope predicate, batched, no N+1). Conceptually:

```php
$pickBest = fn (Collection $c): ItemWizardProfile =>
    $c->sort(fn ($a, $b) => $this->compareComposerProfiles($a, $b))->first();
$branchScope = function ($q) use ($req) {
    $q->whereNull('branch_id_scope')
      ->when($req->branchId > 0, fn ($x) => $x->orWhere('branch_id_scope', $req->branchId));
};
$withSteps = ['steps' => fn ($q) => $q->where('is_active', true)->orderBy('position')];

$itemOwned = ItemWizardProfile::query()->with($withSteps)
    ->whereIn('item_id', $itemIds->all())->where('is_published', true)
    ->where($branchScope)->get()->groupBy('item_id')->map($pickBest);

$items = Item::query()->whereIn('id', $itemIds->all())->get(['id', 'item_category_id']);
$categoryIds = $items->pluck('item_category_id')->filter()->unique()->values();
$categoryOwned = $categoryIds->isEmpty() ? collect() : ItemWizardProfile::query()->with($withSteps)
    ->whereNull('item_id')->whereIn('item_category_id', $categoryIds->all())->where('is_published', true)
    ->where($branchScope)->get()->groupBy('item_category_id')->map($pickBest);

$profiles = $itemIds->mapWithKeys(function (int $id) use ($itemOwned, $categoryOwned, $items) {
    $own = $itemOwned->get($id);
    if ($own) return [$id => $own];
    $catId = (int) optional($items->firstWhere('id', $id))->item_category_id;
    $cat = $categoryOwned->get($catId);
    return $cat ? [$id => $cat] : [];
});
```

**Cleaner long-term option (recommended, larger):** extract this resolution (which is now duplicated
across 5 sites) into a shared `ComposerProfileResolver` service; the 4 render resolvers + PricingService
all call it. That removes the drift risk permanently but is a bigger change — out of scope for a
minimal gate.

## 4. Required evidence on landing (the gate's acceptance criteria)
- [ ] Flip `test_GAP_..._pending_gate_g` inherited assertion to `expectException('minimum'/'Boisson')` → GREEN.
- [ ] `vendor/bin/phpunit --filter "ComposerStepConstraint|Composer|MenuProjection"` → all green (no regression on item-owned precedence).
- [ ] `php artisan fiscal:verify-chain --all` unchanged (no chain impact — validation-only change).
- [ ] Frozen-zone diff limited to `PricingService.php` `assertComposerStepConstraints` only.

## 5. Decision
**WHO:** owner (frozen-zone §7 / NF525-adjacent — Pricing SSOT).
**WHAT:** countersign this LOCK to authorize the `assertComposerStepConstraints` edit above.
**WHERE:** sign-off below + commit tag `LOCK-GATE-G-PRICING-INHERITANCE`.

> Owner sign-off: __________________________  Date: __________

Until signed, the gap is **documented + test-proven + render-fix shipped**; the category-wizard
feature should not be enabled for production use until this co-ships.

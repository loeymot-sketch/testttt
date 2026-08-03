<?php

namespace App\Services\Pricing;

use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use Illuminate\Support\Collection;

/**
 * [T07] Builds the immutable composition_snapshot persisted alongside each OrderItem.
 *
 * Used by OrderService and FrontendOrderService at order creation time.
 *
 * NF525 immutability contract: this snapshot must NEVER be re-written after the
 * initial insert. Reprint flows MUST read from this snapshot, never recompute.
 */
final class CompositionSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  object  $item  Decoded payload line (stdClass) with item_variations / item_extras / item_addons.
     * @param  Collection  $dbVariations  Keyed by id (ItemVariation models).
     * @param  Collection  $dbExtras  Keyed by id (ItemExtra models).
     * @param  Collection|null  $dbAttributes  Keyed by id (ItemAttribute models). If null, will be loaded.
     * @param  Collection|null  $dbAddons  Keyed by id (ItemAddon models). If null, will be loaded.
     * @return array snapshot ready to be JSON-encoded for mass insert
     */
    public function build(
        object $item,
        Collection $dbVariations,
        Collection $dbExtras,
        ?Collection $dbAttributes = null,
        ?Collection $dbAddons = null
    ): array
    {
        $lines = [];
        $extras = [];
        $addons = [];

        if (isset($item->item_variations) && is_array($item->item_variations)) {
            $attrIds = [];
            foreach ($item->item_variations as $v) {
                $varId = $v->id ?? null;
                if (! $varId) {
                    continue;
                }
                $dbVar = $dbVariations[$varId] ?? null;
                if (! $dbVar) {
                    continue;
                }
                $attrIds[] = (int) $dbVar->item_attribute_id;
            }
            $attrIds = array_values(array_unique(array_filter($attrIds)));
            $attrs = $dbAttributes ?? ($attrIds !== []
                ? ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id')
                : collect());

            foreach ($item->item_variations as $v) {
                $varId = $v->id ?? null;
                if (! $varId) {
                    continue;
                }
                $dbVar = $dbVariations[$varId] ?? null;
                if (! $dbVar) {
                    continue;
                }
                $qty = max(1, (int) ($v->quantity ?? 1));
                $unitPrice = (float) $dbVar->price;
                $attr = $attrs[(int) $dbVar->item_attribute_id] ?? null;
                $lines[] = [
                    'variation_id'   => (int) $dbVar->id,
                    'attribute_id'   => (int) $dbVar->item_attribute_id,
                    'attribute_name' => $attr?->name,
                    'variation_name' => (string) $dbVar->name,
                    'quantity'       => $qty,
                    'unit_price'     => round($unitPrice, 6),
                    'line_total'     => round($unitPrice * $qty, 6),
                ];
            }
        }

        if (isset($item->item_extras) && is_array($item->item_extras)) {
            foreach ($item->item_extras as $e) {
                $extId = $e->id ?? null;
                if (! $extId) {
                    continue;
                }
                $dbExt = $dbExtras[$extId] ?? null;
                if (! $dbExt) {
                    // [B1/RC2 2026-07-21] FAIL-LOUD au lieu de skip silencieux.
                    // Ancien `continue` = SEUL silent-drop du backend : un extra
                    // facturé par PricingService (fail-loud, 422 si absent) pouvait,
                    // si un futur appelant passait un $dbExtras plus étroit, être
                    // ABSENT du composition_snapshot → ticket/KDS « produit de base
                    // seul » alors que le client a payé le supplément (brèche NF525 §V,
                    // symptôme rapporté owner « suppléments annulés au paiement »).
                    // Les 2 appelants (PricingService:270, FrontendOrderService:455)
                    // passent le MÊME $dbExtras validé → ce throw ne se déclenche
                    // jamais en régime normal ; il scelle la cohérence snapshot↔prix.
                    throw new \InvalidArgumentException(
                        "Extra ID {$extId} facturé mais absent du snapshot (dbExtras). Commande rejetée pour cohérence NF525.",
                        422
                    );
                }
                $qty = max(1, (int) ($e->quantity ?? 1));
                $unitPrice = (float) $dbExt->price;
                $extras[] = [
                    'extra_id'   => (int) $dbExt->id,
                    'extra_name' => (string) $dbExt->name,
                    'quantity'   => $qty,
                    'unit_price' => round($unitPrice, 6),
                    'line_total' => round($unitPrice * $qty, 6),
                ];
            }
        }

        if (isset($item->item_addons) && is_array($item->item_addons)) {
            $addonIds = [];
            foreach ($item->item_addons as $addon) {
                $addonId = $addon->id ?? null;
                if ($addonId) {
                    $addonIds[] = (int) $addonId;
                }
            }

            $resolvedAddons = $dbAddons ?? ($addonIds !== []
                ? ItemAddon::query()->with('addonItem')->whereIn('id', array_values(array_unique($addonIds)))->get()->keyBy('id')
                : collect());

            foreach ($item->item_addons as $addon) {
                $addonId = $addon->id ?? null;
                if (! $addonId) {
                    continue;
                }
                $dbAddon = $resolvedAddons[$addonId] ?? null;
                if (! $dbAddon) {
                    continue;
                }
                $qty = max(1, (int) ($addon->quantity ?? 1));
                $catalogPrice = (float) ($dbAddon->addonItem?->price ?? 0);
                // [test-e2e/borne E-001 fix 2026-05-10] NF525 SSOT —
                // Snapshot the EFFECTIVE charged price, not the catalog price.
                // The payload-level role drives the kiosk menu-formula ratio
                // (see PricingService::menuRoleAdjustedAddonPrice). Persisting
                // the ratio'd value here ensures the immutable composition
                // snapshot (NF525 §V) matches the customer-facing line and
                // the order_items.total_price column.
                //
                // [HEAL-PLAN-D.1 / RED-Z4 P0-Z4-01 2026-05-19] Defense-in-depth:
                // the FormRequest layer (`ValidatesAddonRoles` trait) is the
                // primary gate; this is the snapshot-time backstop in case a
                // future internal caller (queue, console) bypasses the
                // FormRequest. Honor the kiosk menu-formula payload role
                // ('menu_full' / 'menu_frites' / 'menu_boisson') ONLY when
                // the DB addon row carries role='menu_component' (the single
                // DB role eligible for ratio reduction). For all other DB
                // roles (drink/side/dessert/upsell) the payload role MUST
                // match the DB role exactly. NULL DB role rejects any
                // payload role -> the catalog price is sealed in the snapshot.
                $payloadRoleRaw = $this->payloadValue($addon, 'role');
                $payloadRole = strtolower(trim((string) ($payloadRoleRaw ?? '')));
                $dbRole      = strtolower(trim((string) ($dbAddon->role ?? '')));
                $effectiveRole = $this->resolveEffectiveAddonRole($payloadRole, $dbRole);
                $unitPrice = $this->menuRoleAdjustedAddonPrice($effectiveRole, $catalogPrice);
                $addons[] = [
                    'addon_id'      => (int) $dbAddon->id,
                    'addon_item_id' => (int) $dbAddon->addon_item_id,
                    'addon_name'    => (string) ($dbAddon->addonItem?->name ?? ''),
                    'role'          => $effectiveRole !== '' ? $effectiveRole : null,
                    'quantity'      => $qty,
                    'unit_price'    => round($unitPrice, 6),
                    'line_total'    => round($unitPrice * $qty, 6),
                    // Persist the raw catalog price too so audits can trace
                    // any ratio applied (NF525 reprint reconciliation).
                    'catalog_price' => round($catalogPrice, 6),
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at'    => now()->toIso8601String(),
            'lines'          => $lines,
            'extras'         => $extras,
            'addons'         => $addons,
        ];
    }

    /**
     * [test-e2e/borne E-001 fix 2026-05-10] Mirror of
     * PricingService::menuRoleAdjustedAddonPrice — kept local to keep the
     * snapshot builder a pure transformer (no PricingService dep). Same
     * config source `config('kiosk.menu_pricing')` so frontend +
     * PricingService + snapshot stay in lock-step. Any drift here would be
     * an NF525 audit-chain mismatch (line_total ≠ recomputed addon price).
     */
    private function menuRoleAdjustedAddonPrice(string $role, float $fullPrice): float
    {
        $role = strtolower(trim($role));
        if ($role === '' || ! str_starts_with($role, 'menu_')) {
            return $fullPrice;
        }

        $ratios = (array) config('kiosk.menu_pricing', []);
        $ratio = match ($role) {
            'menu_full'    => (float) ($ratios['full_ratio']  ?? 1.0),
            'menu_frites'  => (float) ($ratios['fries_ratio'] ?? 0.6),
            'menu_boisson' => (float) ($ratios['drink_ratio'] ?? 0.4),
            default        => 1.0,
        };

        if (! is_finite($ratio) || $ratio < 0.0) {
            $ratio = 1.0;
        }

        return round($fullPrice * $ratio, 2);
    }

    /**
     * [HEAL-PLAN-D.1 / RED-Z4 P0-Z4-01 2026-05-19] Defense-in-depth role
     * resolution at snapshot-build time. Mirrors the semantic gate from
     * `App\Http\Requests\Concerns\ValidatesAddonRoles`. The FormRequest
     * layer remains the primary defense (rejects malformed payloads with
     * a 422); this method is the snapshot-time backstop ensuring any
     * forged "menu_*" role does NOT seal a ratio'd price into the NF525
     * composition_snapshot even if the FormRequest is bypassed by an
     * internal caller.
     *
     * Output contract:
     *  - returns one of {'', 'menu_full', 'menu_frites', 'menu_boisson',
     *    'drink', 'side', 'dessert', 'menu_component', 'upsell'}
     *  - returning '' = no ratio, full catalog price (the safe default)
     */
    private function resolveEffectiveAddonRole(string $payloadRole, string $dbRole): string
    {
        // Cheap path: no payload role -> follow DB. (NULL DB also fine
        // here; downstream `menuRoleAdjustedAddonPrice` no-ops on '' or
        // any non-`menu_*` role.)
        if ($payloadRole === '') {
            return $dbRole;
        }

        // Kiosk menu-formula ratio roles: honor ONLY if DB row is
        // menu_component. Otherwise fall back to the (safe) DB role —
        // catalog price seals in the snapshot.
        if ($payloadRole === 'menu_full' || $payloadRole === 'menu_frites' || $payloadRole === 'menu_boisson') {
            return $dbRole === 'menu_component' ? $payloadRole : $dbRole;
        }

        // Native DB-vocabulary payload role: must match the DB row.
        // Mismatch (incl. NULL DB) -> fall back to DB. This guarantees
        // the snapshot's `role` field reflects the catalog truth even
        // on bypassed FormRequest.
        return $payloadRole === $dbRole ? $payloadRole : $dbRole;
    }

    private function payloadValue($payload, string $key)
    {
        if (is_array($payload)) {
            return $payload[$key] ?? null;
        }

        if (is_object($payload)) {
            return $payload->{$key} ?? null;
        }

        return null;
    }
}

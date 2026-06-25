<?php

namespace App\Http\Requests\Concerns;

use App\Models\ItemAddon;
use Illuminate\Validation\Validator;

/**
 * [HEAL-PLAN-D.1 / RED-Z4 P0-Z4-01 — 2026-05-19] Bind payload `role` to the
 * DB-membership of each `item_addons.id` referenced in the order payload.
 *
 * ## Attack closed
 *
 * The kiosk wizard pushes a parent menu addon row tagged with one of three
 * "menu_*" payload roles ('menu_full' / 'menu_frites' / 'menu_boisson') so
 * `PricingService::menuRoleAdjustedAddonPrice` (frozen §7) applies the
 * matching config('kiosk.menu_pricing') ratio (full=1.0 / fries=0.6 /
 * drink=0.4). Before this trait wired in, an attacker could send the same
 * role on ANY addon id (e.g. a drink-category addon, or an addon with a
 * NULL DB role) and pay 60% less than the catalog price. Of 220 production
 * `item_addons` rows on Le Cayenne, 177 have NULL `role` + 23 have role
 * 'drink' — i.e. >90% of the catalog was attack-surface.
 *
 * ## Semantic gate (the rule that matters)
 *
 * DB role is an ENUM ['drink','side','dessert','menu_component','upsell']
 * (nullable) per migration 2026_04_27_143140_add_role_to_item_addons_table.
 * The kiosk menu-formula "menu_*" payload roles are NEVER persisted to DB —
 * they are wizard-context tags carried in the request body only. So an
 * exact-string match between payload and DB role would falsely reject the
 * legitimate kiosk menu flow (payload='menu_boisson' on a DB-`menu_component`
 * addon).
 *
 * The rule that captures the actual security invariant:
 *
 *   - Payload role ∈ {menu_full, menu_frites, menu_boisson}
 *     → REQUIRES dbRole === 'menu_component' (the only DB role eligible
 *       for ratio reduction). Reject otherwise.
 *
 *   - Payload role ∈ ItemAddon::ROLES (drink/side/dessert/menu_component/
 *     upsell) → REQUIRES dbRole === payloadRole (legacy boisson push +
 *     forward-compat). NULL DB role rejects a payload role in this family
 *     too — once an addon is tagged, the payload must agree; an untagged
 *     addon receives no payload-driven role at all.
 *
 *   - Payload role NULL/empty → no constraint (most addons go this path).
 *
 *   - Any other payload role string (incl. unknown `menu_*` variants) →
 *     reject as unknown.
 *
 * ## Defense in depth
 *
 * `CompositionSnapshotBuilder` applies the same gate when persisting the
 * NF525 immutable `composition_snapshot`. If a future internal caller
 * (queue job, console command) bypasses the FormRequest, the snapshot
 * builder still refuses to honor a forged "menu_*" payload role on a
 * non-menu_component addon — the catalog price is sealed in the receipt.
 *
 * ## CLAUDE.md alignment
 *
 * §7 Frozen Zones — PricingService.php UNTOUCHED. Heal lives in the
 * non-frozen FormRequest + non-frozen CompositionSnapshotBuilder layers.
 *
 * §8 NF525 invariants — composition_snapshot integrity preserved. The DB
 * lookup is read-only.
 *
 * §9 Multi-tenant — addon ids are tenant-agnostic (catalog-shared);
 * BranchScope does not apply to item_addons.
 */
trait ValidatesAddonRoles
{
    /**
     * Payload roles that trigger the kiosk menu-formula ratio reduction.
     * Mirrors `PricingService::menuRoleAdjustedAddonPrice` switch arms.
     */
    private static function kioskMenuPayloadRoles(): array
    {
        // [PHP-8.1-COMPAT 2026-06-25] Méthode au lieu d'une CONSTANTE DE TRAIT
        // (interdite avant PHP 8.2 → fatale « Traits cannot have constants » sur le
        // serveur PHP 8.1 → 500 à la création de commande). Valeur inchangée.
        return ['menu_full', 'menu_frites', 'menu_boisson'];
    }

    /**
     * DB role required when a KIOSK_MENU_PAYLOAD_ROLES is sent in the
     * payload — only `menu_component` addons (the parent "Menu (Frites +
     * Boisson)" row of a kiosk item) may receive the ratio reduction.
     */
    private static function menuEligibleDbRole(): string
    {
        return 'menu_component';
    }

    /**
     * Validate `items[].item_addons[].role` against the DB row's role
     * column. Adds 422 errors to `$validator` for every mismatch. No early
     * return on the first error — surface every offending occurrence so
     * the client can fix all of them in one round-trip.
     *
     * Single DB query (one WHERE-IN over distinct addon ids referenced
     * with a non-empty payload role). Bulk-keyed by id. Bypasses
     * BranchScope explicitly — item_addons is not branch-scoped.
     */
    protected function validateAddonRolesAfter(Validator $validator): void
    {
        $raw = $this->input('items');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $items = is_array($decoded) ? $decoded : null;
        } elseif (is_array($raw)) {
            $items = $raw;
        } else {
            return;
        }

        if (! is_array($items) || $items === []) {
            return;
        }

        // Collect (addon_id => [{i, j, role}, ...]) occurrences for every
        // addon that arrived with a NON-EMPTY payload role. Empty/null
        // payload role is the no-constraint path; skip it entirely.
        $occurrencesByAddonId = [];
        foreach ($items as $i => $item) {
            $addons = $item['item_addons'] ?? null;
            if (! is_array($addons)) {
                continue;
            }
            foreach ($addons as $j => $addon) {
                if (! is_array($addon)) {
                    continue;
                }
                $aid = (int) ($addon['id'] ?? 0);
                if ($aid <= 0) {
                    continue;
                }
                $role = isset($addon['role']) ? strtolower(trim((string) $addon['role'])) : '';
                if ($role === '') {
                    continue;
                }
                $occurrencesByAddonId[$aid][] = ['i' => $i, 'j' => $j, 'role' => $role];
            }
        }

        if ($occurrencesByAddonId === []) {
            return;
        }

        // One bulk query for every addon referenced with a non-empty
        // payload role. `role` is nullable in DB — coalesce to empty so
        // the downstream compare is type-stable.
        $dbRoles = ItemAddon::query()
            ->whereIn('id', array_keys($occurrencesByAddonId))
            ->pluck('role', 'id')
            ->map(fn ($r) => strtolower(trim((string) ($r ?? ''))))
            ->toArray();

        $whitelist = array_merge(
            self::kioskMenuPayloadRoles(),
            array_map('strtolower', ItemAddon::ROLES)
        );

        foreach ($occurrencesByAddonId as $addonId => $occurrences) {
            $dbRole = $dbRoles[$addonId] ?? '';
            foreach ($occurrences as $occ) {
                $payloadRole = $occ['role'];
                $key = sprintf('items.%d.item_addons.%d.role', $occ['i'], $occ['j']);

                // Stage 1: unknown role strings are always rejected
                // (forward-compat: a future role MUST be whitelisted
                // explicitly).
                if (! in_array($payloadRole, $whitelist, true)) {
                    $validator->errors()->add(
                        $key,
                        sprintf(
                            'Le rôle "%s" n\'est pas reconnu pour cet addon.',
                            $payloadRole
                        )
                    );
                    continue;
                }

                // Stage 2: kiosk menu-formula payload role demands a
                // menu_component DB role. This blocks the P0-Z4-01
                // exploit on ALL non-menu addons (NULL OR 'drink' OR
                // 'side' OR 'dessert' OR 'upsell').
                if (in_array($payloadRole, self::kioskMenuPayloadRoles(), true)) {
                    if ($dbRole !== self::menuEligibleDbRole()) {
                        $validator->errors()->add(
                            $key,
                            'Ce rôle menu est réservé aux addons "menu_component" '
                                . '(rejet HEAL-D.1 / Z4 P0-01).'
                        );
                    }
                    continue;
                }

                // Stage 3: native DB-vocabulary payload role
                // (drink/side/dessert/menu_component/upsell). Require
                // exact match with the DB row. NULL DB role rejects
                // because the addon is untagged — the payload should
                // not be ascribing a role to an addon the catalog
                // hasn't declared.
                if ($dbRole === '' || $dbRole !== $payloadRole) {
                    $validator->errors()->add(
                        $key,
                        sprintf(
                            'Le rôle addon ne correspond pas à la donnée de référence (payload="%s", attendu="%s").',
                            $payloadRole,
                            $dbRole === '' ? 'aucun' : $dbRole
                        )
                    );
                }
            }
        }
    }
}

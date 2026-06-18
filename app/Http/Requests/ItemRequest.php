<?php

namespace App\Http\Requests;

use App\Models\Allergen;
use App\Rules\IniAmount;
use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ItemRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — ItemController middleware enforces
     * `permission:items_create` on store/import/duplicate and `permission:items_edit`
     * on update/changeImage; FormRequest accepts either since the same class is injected
     * on both verbs. Any future route bypass still authz-checks against the items family.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('items_create') || $user->can('items_edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        $allergenCodes = Allergen::query()->pluck('code')->all();

        return [
            'name'            => [
                'required',
                'string',
                'max:190',
                Rule::unique("items", "name")->whereNull('deleted_at')->ignore($this->route('item.id'))
            ],
            'item_category_id' => ['required', 'numeric', 'not_in:0'],
            'tax_id'           => ['nullable', 'numeric', 'not_in:0'],
            'item_type'        => ['required', 'numeric', 'not_in:0'],
            'price'            => ['required', new IniAmount()],
            'is_featured'      => ['required', 'numeric', 'not_in:0'],
            // [GAP-27-1] is_upsell — optional flag for Splash-style upsell suggestions on kiosk
            'is_upsell'        => ['nullable', 'numeric'],
            'is_chef_pick'     => ['nullable', 'boolean'],
            'is_new'           => ['nullable', 'boolean'],
            'is_available'     => ['nullable', 'boolean'],
            'is_spicy'         => ['nullable', 'boolean'],
            'is_vegetarian'    => ['nullable', 'boolean'],
            'is_pork_free'     => ['nullable', 'boolean'],
            'is_halal'         => ['nullable', 'boolean'],
            'is_gluten_free'   => ['nullable', 'boolean'],
            'chef_pick_order'  => ['nullable', 'integer', 'min:0', 'max:9999'],
            'channels'         => ['nullable', 'array'],
            'channels.*'       => ['string', 'in:kiosk,pos,web'],
            'allergen_flags'   => ['nullable', 'array'],
            'allergen_flags.*' => array_values(array_filter([
                'string',
                $allergenCodes !== [] ? Rule::in($allergenCodes) : null,
            ])),
            'kiosk_emoji'      => ['nullable', 'string', 'max:10'],
            // [v1-0-1-h5 Z5-P1-02 2026-05-17] barcode + kds_station — fields fillable on Item model but were
            // silently dropped when posted via admin form (FormRequest gatekeeping). POS scanners + KDS routing rely on them.
            'barcode'          => ['nullable', 'string', 'max:64', 'unique:items,barcode' . ($this->item ? ',' . $this->item->id : '')],
            'kds_station'      => ['nullable', 'string', 'max:32'],
            'description'      => ['nullable', 'string', 'max:5000'],
            'caution'          => ['nullable', 'string', 'max:5000'],
            'status'           => ['required', 'numeric', 'max:24'],
            'order'            => ['required', 'numeric'],
            'variations'       => ['nullable', 'json'],
            'extras'           => ['nullable', 'json'],
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V1: NoDangerousFileExtension
            // blocks .pht / double-extension polyglot attacks.
            'image'            => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()],
        ];
    }

    public function attributes()
    {
        return [
            'item_category_id' => strtolower(trans('all.label.item_category_id')),
            'tax_id'           => strtolower(trans('all.label.tax_id')),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateNestedModifierSurfaces($validator, 'variations');
            $this->validateNestedModifierSurfaces($validator, 'extras');
            // [abuse-heal 2026-06-18] The `variations`/`extras` blobs were typed only as JSON and
            // their nested objects fed ItemService::store/update -> createMany/create with ZERO
            // per-object validation. A nested price of -5 (or null / non-numeric) persisted and
            // then fed PricingService = wrong POS/Kiosk price. Mirror the canonical ItemVariation /
            // ItemExtra rules (name required + IniAmount, i.e. numeric >= 0) per nested row so the
            // WHOLE request 422s instead of silently storing a poisoned modifier.
            $this->validateNestedModifierPrices($validator, 'variations');
            $this->validateNestedModifierPrices($validator, 'extras');
        });
    }

    /**
     * [abuse-heal 2026-06-18] Validate the price + name of every nested variation/extra object
     * posted via the bulk `variations`/`extras` JSON blob. The dedicated POST /variation/{item}
     * and POST /extra/{item} endpoints already enforce this via FormRequest + IniAmount; this
     * closes the equivalent guard on the bulk item create/update path.
     */
    private function validateNestedModifierPrices(Validator $validator, string $field): void
    {
        $raw = $this->input($field);
        if ($raw === null || $raw === '') {
            return;
        }

        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                $validator->errors()->add("{$field}.{$index}", 'Each modifier must be an object.');
                continue;
            }

            // name — required non-empty string (matches ItemVariationRequest / ItemExtraRequest).
            $name = $row['name'] ?? null;
            if (! is_string($name) || trim($name) === '') {
                $validator->errors()->add("{$field}.{$index}.name", 'The modifier name field is required.');
            }

            // price — required, numeric, and never negative (canonical IniAmount contract, >= 0).
            $price = $row['price'] ?? null;
            if (! is_numeric($price)) {
                $validator->errors()->add("{$field}.{$index}.price", 'The modifier price must be a number.');
            } elseif ((float) $price < 0) {
                $validator->errors()->add("{$field}.{$index}.price", 'The modifier price must not be negative.');
            }
        }
    }

    private function validateNestedModifierSurfaces(Validator $validator, string $field): void
    {
        $raw = $this->input($field);
        if ($raw === null || $raw === '') {
            return;
        }

        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! array_key_exists('visible_on', $row) || $row['visible_on'] === null) {
                continue;
            }

            if (! is_array($row['visible_on'])) {
                $validator->errors()->add("{$field}.{$index}.visible_on", 'The visible_on field must be an array.');
                continue;
            }

            foreach ($row['visible_on'] as $surfaceIndex => $surface) {
                if (! in_array((string) $surface, ['kiosk', 'pos', 'web'], true)) {
                    $validator->errors()->add("{$field}.{$index}.visible_on.{$surfaceIndex}", 'The selected visible_on surface is invalid.');
                }
            }
        }
    }
}

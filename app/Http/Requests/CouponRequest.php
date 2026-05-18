<?php

namespace App\Http\Requests;

use App\Enums\DiscountType;
use App\Enums\Status;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class CouponRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — CouponController middleware enforces
     * `permission:coupons_create` on store and `permission:coupons_edit` on update;
     * FormRequest accepts either since the same class is injected on both verbs.
     * Any future route bypass still authz-checks against the coupons capability family.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('coupons_create') || $user->can('coupons_edit');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name'        => [
                'required',
                'string',
                'max:190',
                Rule::unique("coupons", "name")->ignore($this->route('coupon.id'))
            ],
            'description'      => ['nullable', 'string', 'max:900'],
            'code'             => ['required', 'string', 'max:24', Rule::unique("coupons", "code")->ignore($this->route('coupon.id'))],
            // [P9] Non-negative monetary / cap fields (symmetry with P5–P8).
            'discount'         => ['required', 'numeric', 'min:0'],
            // discount_type est cast en integer (cf. App\Enums\DiscountType: FIXED=5, PERCENTAGE=10).
            // On valide donc des entiers, pas des chaînes.
            'discount_type'    => ['required', 'integer', Rule::in([DiscountType::FIXED, DiscountType::PERCENTAGE])],
            'start_date'       => ['required', 'string',],
            'end_date'         => ['required', 'string',],
            'minimum_order'    => ['required', 'numeric', 'min:0'],
            'maximum_discount' => ['required', 'numeric', 'min:0'],
            'limit_per_user'   => ['nullable', 'numeric', 'min:0'],
            'image'            => $this->route('coupon.id') ? ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'] : ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],

            // [PROMO-DASH-2026-05-06] Advanced scoping fields
            'valid_days_of_week'   => ['nullable', 'array'],
            'valid_days_of_week.*' => ['string', Rule::in(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])],
            'valid_hours_start'    => ['nullable', 'string', 'date_format:H:i'],
            'valid_hours_end'      => ['nullable', 'string', 'date_format:H:i'],
            'branch_scope'         => ['nullable', 'array'],
            'branch_scope.*'       => ['integer', 'min:1'],
            'surfaces'             => ['nullable', 'array'],
            'surfaces.*'           => ['string', Rule::in(['pos', 'kiosk', 'web'])],
            'max_uses_global'      => ['nullable', 'integer', 'min:1'],
            'status'               => ['nullable', 'integer', Rule::in([Status::ACTIVE, Status::INACTIVE])],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {

            if (!$this->isNotNull(request('start_date'))) {
                $validator->errors()->add('start_date', 'The start date field is required');
            }

            if (!$this->isNotNull(request('end_date'))) {
                $validator->errors()->add('end_date', 'The end date field is required');
            }

            if ($this->isPercentage() && request('discount') > 100) {
                $validator->errors()->add('discount', 'Percentage amount can\'t be greater than 100.');
            }

            if (!$this->isPercentage() && request('minimum_order') < request('discount')) {
                $validator->errors()->add('minimum_order', 'Minimum order amount can\'t be less than discount amount.');
            }

            if ($this->isNotNull(request('start_date')) && strtotime(request('end_date')) < strtotime(request('start_date'))) {
                $validator->errors()->add('end_date', 'End date can\'t be older than Start date.');
            }

            if ($this->isNotNull(request('start_date')) && $this->checkToDate()) {
                $validator->errors()->add('end_date', 'End date can\'t be older than now.');
            }

            // [PROMO-DASH-2026-05-06] valid_hours cohérence : si l'un est défini,
            // l'autre doit l'être aussi (UI laisse la possibilité de les laisser
            // vides tous les deux pour signifier « pas de plage horaire »).
            $hStart = request('valid_hours_start');
            $hEnd = request('valid_hours_end');
            if ($this->isNotNull($hStart) && !$this->isNotNull($hEnd)) {
                $validator->errors()->add('valid_hours_end', 'Valid hours end is required when valid hours start is set.');
            }
            if ($this->isNotNull($hEnd) && !$this->isNotNull($hStart)) {
                $validator->errors()->add('valid_hours_start', 'Valid hours start is required when valid hours end is set.');
            }
        });
    }

    private function isPercentage()
    {
        return request('discount_type') == DiscountType::PERCENTAGE ? true : false;
    }

    public function checkToDate()
    {
        $today = strtotime(date('Y-m-d H:i:s'));
        if (strtotime(request('end_date')) < $today) {
            return true;
        }
    }

    private function isNotNull($value)
    {
        if ($value === 'null' || $value === null || $value === '') {
            return false;
        }
        return true;
    }
}

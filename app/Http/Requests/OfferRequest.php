<?php

namespace App\Http\Requests;

use App\Rules\IniAmount;
use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OfferRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * V1.0.2 BUILD-6 heal: defense-in-depth — OfferController middleware enforces
     * `permission:offers_create` on store and `permission:offers_edit` on update;
     * FormRequest accepts either since the same class is injected on both verbs.
     * Any future route bypass still authz-checks against the offers capability family.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }
        return $user->can('offers_create') || $user->can('offers_edit');
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
                Rule::unique("offers", "name")->ignore($this->route('offer.id'))
            ],
            'amount'     => ['required', 'numeric', 'max:100', new IniAmount()],
            'status'     => ['required', 'numeric', 'max:24'],
            'start_date' => ['required', 'string', 'max:190'],
            'end_date'   => ['required', 'string', 'max:190'],
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V1: NoDangerousFileExtension
            // blocks .pht / double-extension polyglot attacks.
            'image'      => $this->route('offer.id') ? ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()] : ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'The discount percentage field is required'
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (!$this->isNotNull(request('start_date'))) {
                $validator->errors()->add('start_date', 'Le champ date de début est obligatoire.');
            }

            if (!$this->isNotNull(request('end_date'))) {
                $validator->errors()->add('end_date', 'Le champ date de fin est obligatoire.');
            }

            if ($this->isNotNull(request('start_date')) && strtotime(request('end_date')) < strtotime(request('start_date'))) {
                $validator->errors()->add('end_date', 'La date de fin ne peut pas être antérieure à la date de début.');
            }
            if ($this->isNotNull(request('start_date')) && $this->checkToDate()) {
                $validator->errors()->add('end_date', 'La date de fin ne peut pas être déjà passée.');
            }
        });
    }


    private function checkToDate()
    {
        $today = strtotime(date('Y-m-d H:i:s'));
        if (strtotime(request('end_date')) < $today) {
            return true;
        }
    }

    private function isNotNull($value)
    {
        if ($value === 'null') {
            return false;
        }
        return true;
    }
}
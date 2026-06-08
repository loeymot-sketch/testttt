<?php

namespace App\Http\Requests;

use App\Rules\IniAmount;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ItemExtraRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
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
                Rule::unique("item_extras", "name")->whereNull('deleted_at')->ignore($this->route('itemExtra.id'))->where('item_id', $this->route('item.id')),
            ],
            'price'       => ['required', new IniAmount()],
            'status'      => ['required', 'numeric', 'max:24'],
            // Surface visibility: null = all surfaces; array of "kiosk", "pos", "web"
            'visible_on'  => ['nullable', 'array'],
            'visible_on.*'=> ['string', Rule::in(['kiosk', 'pos', 'web'])],
            'group_label' => ['nullable', 'string', 'max:50'],
            // [W1] Per-option catalog metadata (non-fiscal): description + stored image.
            // [W7 security] image_path is rendered unescaped into a frozen pos-wizard.js
            // img sink — reject the attribute-breakout / traversal charset at write-time
            // (first layer; CatalogImagePath::safeResolve is the authoritative output guard).
            'description' => ['nullable', 'string', 'max:5000'],
            'image_path'  => [
                'nullable', 'string', 'max:2048',
                'regex:/^[A-Za-z0-9._\/:?=&%~+-]+$/',  // no quotes, < > space backslash ( )
                'not_regex:/\.\./',                     // no path traversal
            ],
        ];
    }
}
<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;

class ChangeImageRequest extends FormRequest
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
    public function rules()
    {
        return [
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V1: NoDangerousFileExtension
            // blocks .pht / double-extension polyglot attacks.
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()]
        ];
    }

    public function messages(): array
    {
        return [
            'image.image' => 'The image must be an image of type: jpg, jpeg, png.',
            'image.mimes' => 'The image must be an image of type: jpg, jpeg, png.'
        ];
    }
}
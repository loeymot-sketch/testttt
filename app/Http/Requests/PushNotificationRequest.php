<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushNotificationRequest extends FormRequest
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
            'title'        => [
                'required',
                'string',
                'max:255',
            ],
            'description' => ['required', 'string', 'max:2000'],
            'role_id'     => ['nullable', 'numeric'],
            'user_id'     => ['nullable', 'numeric'],
            'branch_id'   => ['required', 'numeric'],
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V3 P1 fix:
            // OLD: ['image', 'mimes:jpeg,png,jpg|max:5098'] — Laravel's
            // explodeExplicitRule (ValidationRuleParser.php:86-101) only
            // splits the `|` separator on STRING rules; inside an array
            // element each item is treated as a single rule token. The
            // `|max:5098` suffix was therefore parsed as a phantom MIME
            // entry (`jpg|max:5098`) and silently dropped — 10 MB PNG
            // was empirically accepted.
            // NEW: proper array shape with each rule as its own element,
            // plus NoDangerousFileExtension to close the .pht gap.
            'image'       => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:5098',
                new NoDangerousFileExtension(),
            ],
        ];
    }
}

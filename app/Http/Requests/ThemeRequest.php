<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;

class ThemeRequest extends FormRequest
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
        // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V4 P1 fix:
        // OLD: no `max:` rule on any of the 3 logo fields — file size
        // bounded only by nginx `client_max_body_size` (25–32 MB in our
        // edge configs). 24 MB JPG upload would pass FormRequest.
        // NEW: add `max:2048` (2 MB hard cap — logos are typically <500 KB)
        // plus NoDangerousFileExtension to close the .pht polyglot gap.
        return [
            'theme_logo'         => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()],
            'theme_favicon_logo' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()],
            'theme_footer_logo'  => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048', new NoDangerousFileExtension()],
        ];
    }
}
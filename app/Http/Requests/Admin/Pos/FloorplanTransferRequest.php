<?php

namespace App\Http\Requests\Admin\Pos;

use Illuminate\Foundation\Http\FormRequest;

class FloorplanTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('pos');
    }

    public function rules(): array
    {
        return [
            'source_table_id' => ['required', 'integer', 'min:1', 'different:target_table_id'],
            'target_table_id' => ['required', 'integer', 'min:1', 'different:source_table_id'],
        ];
    }
}

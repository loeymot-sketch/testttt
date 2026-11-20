<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [FACTURE PRO 2026-11-14] Annulation d'une facture pro. Le numéro est conservé, le document
 * imprime « ANNULÉE ». Un motif est exigé : une annulation sans raison ne se relit pas.
 */
class ProInvoiceCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('cancel_reason');
        $this->merge(['cancel_reason' => is_string($reason) ? trim($reason) : $reason]);
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'cancel_reason.required' => 'Indiquez le motif de l\'annulation.',
            'cancel_reason.min'      => 'Le motif de l\'annulation est trop court.',
            'cancel_reason.max'      => 'Le motif de l\'annulation est trop long (255 caractères au plus).',
        ];
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('Vous n\'avez pas le droit d\'annuler une facture pro.');
    }
}

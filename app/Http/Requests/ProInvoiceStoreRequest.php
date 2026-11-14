<?php

namespace App\Http\Requests;

use App\Models\ProInvoice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * [FACTURE PRO 2026-11-14] Création d'une facture pro depuis la caisse.
 *
 * Porte : permission `pos` — qui tient la caisse peut faire la facture du client qui est devant
 * lui. Le montant est LIBRE (choix du propriétaire, hors chaîne NF525) ; HT et TVA sont calculés
 * côté serveur, jamais acceptés du navigateur.
 */
class ProInvoiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) ? trim($v) : $v;

        $siret = $this->input('client_siret');
        $vat = $this->input('client_vat_number');
        $amount = $this->input('amount_ttc');

        $this->merge([
            'client_name'       => $trim($this->input('client_name')),
            'client_address'    => $trim($this->input('client_address')) ?: null,
            'client_siret'      => is_string($siret) && trim($siret) !== '' ? preg_replace('/\s+/', '', $siret) : null,
            'client_vat_number' => is_string($vat) && trim($vat) !== '' ? strtoupper(preg_replace('/\s+/', '', $vat)) : null,
            'label'             => $trim($this->input('label')),
            'notes'             => $trim($this->input('notes')) ?: null,
            'amount_ttc'        => is_string($amount) ? str_replace(',', '.', trim($amount)) : $amount,
            'quantity'          => $this->filled('quantity') ? $this->input('quantity') : 1,
            // « 10 », « 10.00 », « 10,0 » → « 10 » ; ce qui n'est pas un nombre reste tel quel et sera refusé.
            'vat_rate'          => ! $this->filled('vat_rate') ? '10'
                : (is_numeric($r = str_replace(',', '.', (string) $this->input('vat_rate'))) ? (string) (float) $r : $r),
            'service_date'      => $this->filled('service_date') ? $this->input('service_date') : now()->toDateString(),
        ]);
    }

    public function rules(): array
    {
        return [
            'client_name'       => ['required', 'string', 'max:190'],
            'client_address'    => ['nullable', 'string', 'max:500'],
            'client_siret'      => ['nullable', 'string', 'regex:/^(\d{9}|\d{14})$/'],
            'client_vat_number' => ['nullable', 'string', 'regex:/^[A-Z]{2}[0-9A-Z]{2,13}$/'],
            'service_date'      => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:'.now()->subYear()->toDateString()],
            'label'             => ['required', 'string', 'max:190'],
            'quantity'          => ['required', 'integer', 'min:1', 'max:999'],
            'amount_ttc'        => ['required', 'regex:/^\d{1,5}(\.\d{1,2})?$/', 'numeric', 'gt:0', 'max:99999.99'],
            'vat_rate'          => ['required', 'in:'.implode(',', ProInvoice::VAT_RATES)],
            'payment_method'    => ['required', 'string', 'in:'.implode(',', ProInvoice::PAYMENT_METHODS)],
            'notes'             => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_name.required'      => 'Indiquez la raison sociale du client.',
            'client_name.max'           => 'La raison sociale est trop longue (190 caractères au plus).',
            'client_address.max'        => 'L\'adresse est trop longue (500 caractères au plus).',
            'client_siret.regex'        => 'Le SIRET doit compter 14 chiffres (ou 9 pour un SIREN).',
            'client_vat_number.regex'   => 'Le numéro de TVA doit commencer par le code pays (ex. FR12345678901).',
            'service_date.required'     => 'Indiquez la date de la prestation.',
            'service_date.date_format'  => 'La date de la prestation est invalide.',
            'service_date.before_or_equal' => 'La date de la prestation ne peut pas être dans le futur.',
            'service_date.after_or_equal'  => 'La date de la prestation remonte à plus d\'un an.',
            'label.required'            => 'Indiquez la désignation (ex. « 2 repas »).',
            'label.max'                 => 'La désignation est trop longue (190 caractères au plus).',
            'quantity.required'         => 'Indiquez la quantité.',
            'quantity.integer'          => 'La quantité doit être un nombre entier.',
            'quantity.min'              => 'La quantité doit être au moins 1.',
            'quantity.max'              => 'La quantité ne peut pas dépasser 999.',
            'amount_ttc.required'       => 'Indiquez le montant TTC.',
            'amount_ttc.regex'          => 'Le montant TTC est invalide (deux décimales au plus).',
            'amount_ttc.numeric'        => 'Le montant TTC est invalide.',
            'amount_ttc.gt'             => 'Le montant TTC doit être supérieur à 0.',
            'amount_ttc.max'            => 'Le montant TTC ne peut pas dépasser 99 999,99 €.',
            'vat_rate.required'         => 'Indiquez le taux de TVA.',
            'vat_rate.in'               => 'Taux de TVA non proposé (5,5 %, 10 % ou 20 %).',
            'payment_method.required'   => 'Choisissez le mode de règlement.',
            'payment_method.in'         => 'Mode de règlement inconnu (espèces, carte ou titre-restaurant).',
            'notes.max'                 => 'La note est trop longue (500 caractères au plus).',
        ];
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('Vous n\'avez pas le droit de faire une facture pro.');
    }
}

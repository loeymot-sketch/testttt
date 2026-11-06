<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [HORAIRES 2026-11-05] Réglage des horaires d'ouverture (Paramètres › Horaires). Voir App\Services\Horaires\HorairesService.
 */
class HorairesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings');
    }

    public function rules(): array
    {
        $hm = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

        return [
            'semaine'               => ['required', 'array', 'size:7'],
            'semaine.*.ouvert'      => ['required', 'boolean'],
            'semaine.*.ouverture'   => ['required', 'string', $hm],
            'semaine.*.fermeture'   => ['required', 'string', $hm],
            'fermetures'            => ['present', 'array', 'max:60'],
            'fermetures.*.du'       => ['required', 'date_format:Y-m-d'],
            'fermetures.*.au'       => ['required', 'date_format:Y-m-d', 'after_or_equal:fermetures.*.du'],
            'fermetures.*.message'  => ['nullable', 'string', 'max:160'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            foreach ((array) $this->input('semaine', []) as $iso => $j) {
                if (! in_array((int) $iso, [1, 2, 3, 4, 5, 6, 7], true)) {
                    $v->errors()->add('semaine', 'Jours attendus : 1 (lundi) à 7 (dimanche).');
                    break;
                }
                if (! empty($j['ouvert']) && ($j['ouverture'] ?? '') === ($j['fermeture'] ?? '')) {
                    $v->errors()->add("semaine.$iso.fermeture", 'L\'heure de fermeture doit être différente de l\'heure d\'ouverture.');
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'semaine.*.ouverture.regex' => 'Heure d\'ouverture au format HH:MM.',
            'semaine.*.fermeture.regex' => 'Heure de fermeture au format HH:MM.',
            'fermetures.*.au.after_or_equal' => 'La fin d\'une fermeture ne peut pas précéder son début.',
            'fermetures.*.du.date_format' => 'Date de début invalide.',
            'fermetures.*.au.date_format' => 'Date de fin invalide.',
        ];
    }
}

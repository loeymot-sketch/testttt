<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * IDENTIFIER LE CLIENT AU COMPTOIR — la requête d'entrée.
 *
 * ── LA PORTE ─────────────────────────────────────────────────────────────────────────────────
 * Permission `pos` : si vous pouvez tenir la caisse, vous pouvez identifier le client qui est
 * devant vous. Un droit plus fin serait une porte de plus à ouvrir pour chaque caissier, sans rien
 * protéger de plus — le résultat ne contient ni numéro ni e-mail en clair.
 *
 * On ne réutilise PAS `pos.redeem-loyalty` : identifier n'est pas débiter. Un caissier qui n'a que
 * le droit de faire cumuler des points doit pouvoir rattacher un client à sa commande.
 *
 * ── L'ÉNUMÉRATION ────────────────────────────────────────────────────────────────────────────
 * Cette route dit si un numéro a un compte : c'est un oracle. Trois barrières, et pas une seule :
 * l'authentification du personnel, la permission, et une limite de débit posée sur la route
 * (`throttle:pos-loyalty-lookup`). La réponse ne rend jamais le numéro ni l'e-mail en clair, donc
 * même une fuite de session ne donne pas un carnet d'adresses.
 *
 * Sentinelle : tests/Feature/Pos/PosLoyaltyLookupEndpointTest.php
 */
class PosLoyaltyLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    public function rules(): array
    {
        return [
            // Exactement un critère à la fois. Trois champs remplis, c'est une intention ambiguë :
            // mieux vaut refuser que deviner lequel le caissier voulait.
            'phone' => ['nullable', 'string', 'max:32'],
            'code'  => ['nullable', 'string', 'max:32'],
            'qr'    => ['nullable', 'string', 'max:512'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $fournis = collect(['phone', 'code', 'qr'])
                ->filter(fn ($cle) => trim((string) $this->input($cle, '')) !== '')
                ->values();

            if ($fournis->isEmpty()) {
                $v->errors()->add('phone', 'Indiquez un numéro, un code fidélité ou un QR.');

                return;
            }

            if ($fournis->count() > 1) {
                $v->errors()->add('phone', 'Un seul critère à la fois : numéro, code ou QR.');
            }
        });
    }

    /** Le critère retenu et sa valeur, décidés ici pour que le contrôleur n'ait pas à re-trier. */
    public function critere(): array
    {
        foreach (['qr', 'code', 'phone'] as $cle) {
            $valeur = trim((string) $this->input($cle, ''));

            if ($valeur !== '') {
                return [$cle, $valeur];
            }
        }

        return ['phone', ''];
    }
}

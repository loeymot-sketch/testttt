<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CRÉER LE COMPTE D'UN CLIENT AU COMPTOIR.
 *
 * [2026-08-10 · propriétaire : « je veux ajouter la section pour pouvoir créer un compte pour un
 * client »]
 *
 * ── CE QU'ON DEMANDE, ET CE QU'ON NE DEMANDE PAS ─────────────────────────────────────────────
 * Le téléphone SEUL est obligatoire : c'est la clé d'identification que l'exploitant préfère, et
 * c'est tout ce qu'on peut obtenir d'un client pressé dans une file. Le nom et l'e-mail sont
 * facultatifs — exiger une adresse au comptoir, c'est perdre l'inscription.
 *
 * Aucun mot de passe n'est demandé, et il ne faut JAMAIS en demander un : personne ne dicte son mot
 * de passe à un caissier. Le compte naît « invité », et le client en prendra possession lui-même à
 * sa première connexion par code.
 *
 * ── LA PORTE ─────────────────────────────────────────────────────────────────────────────────
 * `permission:pos` : inscrire un client fait partie du travail de comptoir.
 *
 * Sentinelle : tests/Feature/Pos/PosCustomerCreateTest.php
 */
class PosCustomerCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:9', 'max:32'],
            'name'  => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            // Le caissier lit ce message à voix haute devant le client : il doit être une phrase.
            'phone.required' => 'Le numéro de téléphone est nécessaire pour créer le compte.',
            'phone.min'      => 'Ce numéro est incomplet.',
            'email.email'    => 'Cette adresse e-mail ne semble pas valide.',
        ];
    }
}

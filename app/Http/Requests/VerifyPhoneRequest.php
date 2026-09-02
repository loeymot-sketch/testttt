<?php

namespace App\Http\Requests;


use App\Rules\ValidPhone;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPhoneRequest extends FormRequest
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
            // Indicatif pays (ex. +33, 33) — jamais « numeric » seul, sinon +33 est rejeté.
            // [APP MOBILE 2026-09-02 · e-mail d'abord] Le client qui se connecte par e-mail ne
            // connaît NI le téléphone NI l'indicatif du compte : le serveur les résout
            // (GuestSignupController::verify). `phone` et `code` ne sont donc requis qu'en
            // l'absence d'`email` — le canal SMS/borne, lui, envoie toujours les deux.
            'code'  => ['required_without:email', 'nullable', 'string', 'max:32'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:180', new ValidPhone()],
            'token' => ['required', 'max:180'],
            // [HEAL SIGNUP 2026-07-30] Canal EMAIL : email + prénom optionnels au verify →
            // persistance déterministe (fallback cache email) + prénom réel dans register().
            'email' => ['required_without:phone', 'nullable', 'string', 'email:rfc', 'max:190'],
            'first_name' => ['nullable', 'string', 'max:100'],
            // [OWNER 2026-08-01] Nom de famille : nullable ICI (le canal SMS/borne partage cet
            // endpoint) — l'exigence « nom complet » est portée par GuestSignupEmailOtpRequest
            // (canal web), et le nom saisi y est repris automatiquement au verify.
            'last_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
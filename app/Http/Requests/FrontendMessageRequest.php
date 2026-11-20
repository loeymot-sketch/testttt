<?php

namespace App\Http\Requests;

use App\Models\Message;

/**
 * [W7-D D-001 2026-11-04] Variante CLIENT de `MessageRequest`.
 *
 * `MessageService::store` crée le fil avec `user_id = $request->receiver_id` (jamais validé) et la ressource renvoie
 * le nom et le téléphone EN CLAIR du propriétaire du fil : côté admin c'est voulu (le caissier écrit à un client),
 * côté client c'était une énumération de tous les comptes (admin, caissier, livreur…) et une injection dans le fil
 * d'autrui via `message_id`. Ici, le fil appartient TOUJOURS à l'appelant authentifié, et un `message_id` qui n'est
 * pas le sien vaut 404 (pas 403 : on ne révèle pas qu'un fil existe).
 */
class FrontendMessageRequest extends MessageRequest
{
    protected function prepareForValidation(): void
    {
        $moi = (int) auth()->id();

        if ($this->filled('message_id')) {
            $fil = Message::find((int) $this->input('message_id'));
            abort_if(! $fil || (int) $fil->user_id !== $moi, 404);
        }

        $this->merge(['user_id' => $moi, 'receiver_id' => $moi]);
    }
}

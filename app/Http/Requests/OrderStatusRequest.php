<?php

namespace App\Http\Requests;

use App\Enums\OrderCancelReason;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;

class OrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();

        // Staff/admin roles can change any order status
        if ($user->hasAnyRole(['Admin', 'Branch Manager', 'Chef', 'POS Operator', 'Cashier'])) {
            return true;
        }

        // Kiosk machine users can only cancel their OWN orders.
        // The service layer enforces ownership + status constraints.
        if ($user->tokenCan('kiosk:order') && (int) $this->input('status') === OrderStatus::CANCELED) {
            return true;
        }

        return false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * [AUDIT-F-004] Reason is required for transitions to CANCELED, REJECTED, RETURNED
     * (ORDER_FLOW.md §49). Kiosk machine token actors must additionally pick from the
     * `OrderCancelReason` enum whitelist; admin/staff actors keep free-text capability
     * for back-compat with existing audit log call sites (PosOrderBL2*).
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'numeric'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:700'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $status = (int) $this->input('status');

            $terminals = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];
            if (!in_array($status, $terminals, true)) {
                return;
            }

            $reason = trim((string) $this->input('reason', ''));
            if ($reason === '') {
                $v->errors()->add('reason', 'Reason is required for cancel, reject or return transitions.');
                return;
            }

            // Kiosk-machine-originated transitions must use a whitelisted enum code so
            // analytics on `order_status_transitions.reason` carries a stable dimension.
            if ($this->actorIsKioskMachine() && !OrderCancelReason::isValid($reason)) {
                $v->errors()->add('reason', 'Reason code is not whitelisted for kiosk-originated transitions.');
            }
        });
    }

    private function actorIsKioskMachine(): bool
    {
        $user = auth()->user();
        if (!$user || !method_exists($user, 'currentAccessToken')) {
            return false;
        }

        // [CANCEL-FIX 2026-07-10] Ne PAS se fier à tokenCan('kiosk:order') : un admin en session
        // SPA porte un TransientToken dont can()/tokenCan() renvoie TRUE pour TOUTE ability, et un
        // token admin avec l'ability wildcard '*' passe aussi. Résultat (bug caisse) : l'admin qui
        // annule une commande borne était pris pour une borne → motif libre « Annulée » refusé
        // (« Reason code is not whitelisted for kiosk-originated transitions »).
        // Un VRAI acteur borne = PersonalAccessToken scoppé EXACTEMENT sur kiosk:order (createToken
        // ['kiosk:order']), donc SANS wildcard. Session/admin → free-text conservé.
        $token = $user->currentAccessToken();
        if (!$token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            return false;
        }

        $abilities = (array) ($token->abilities ?? []);

        return in_array('kiosk:order', $abilities, true) && !in_array('*', $abilities, true);
    }
}

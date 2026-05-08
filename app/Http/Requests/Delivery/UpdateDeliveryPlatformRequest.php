<?php

namespace App\Http\Requests\Delivery;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * [PARALLEL-TRACK-1.4 / Delivery Platform Integration — Phase 4]
 *
 * Validates payloads sent to the admin "update delivery platform"
 * endpoint. Two non-trivial constraints are baked in:
 *
 *   1. Authorization is gated by the `settings` Spatie permission
 *      (same gate as every other admin/setting/* mutation in the
 *      project — see KioskMachineController, MailController, …).
 *      Returning 403 here keeps the controller body clean.
 *
 *   2. Credentials are merge-only: the modal sends only the keys
 *      the operator actually changed (because secrets stay masked
 *      until "show" is toggled). The controller will merge() the
 *      submitted credentials onto the existing decrypted blob, so
 *      submitting `{}` keeps the current secrets intact.
 *
 * Note: we do NOT validate the platform enum here — `platform` is
 * immutable once a row exists (it's part of the unique key) and is
 * not part of the update payload. Same for branch_id.
 */
class UpdateDeliveryPlatformRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        // Admin Spatie role + `settings` permission match the rest of
        // the admin/setting/* surface. We accept either to allow ops
        // to run in a Spatie-permission-only deployment without role.
        if (method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('settings')) {
            return true;
        }
        if (method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
            return true;
        }

        return false;
    }

    public function rules(): array
    {
        return [
            'enabled'                     => ['sometimes', 'boolean'],
            'external_store_id'           => ['sometimes', 'string', 'max:128'],
            // Credentials are always optional — empty body keeps the
            // existing decrypted blob untouched. Each individual field
            // is bounded so the encrypted column never grows beyond
            // a reasonable size.
            'credentials'                 => ['sometimes', 'array'],
            'credentials.client_id'       => ['nullable', 'string', 'max:512'],
            'credentials.client_secret'   => ['nullable', 'string', 'max:1024'],
            'credentials.webhook_secret'  => ['nullable', 'string', 'max:1024'],
            'credentials.api_key'         => ['nullable', 'string', 'max:1024'],
            'credentials.refresh_token'   => ['nullable', 'string', 'max:1024'],
        ];
    }
}

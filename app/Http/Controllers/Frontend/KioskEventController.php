<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\KioskMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * [C6 / Phase 5] Kiosk observability endpoint.
 *
 * Receives structured events from the kiosk frontend and persists them
 * in ActionLog so operators can correlate offline failures, auth errors,
 * and other kiosk-specific issues without requiring Sentry.
 *
 * Auth: kiosk:order ability (Sanctum token from KioskMachineLoginController).
 * Route: POST /api/frontend/kiosk-event
 *
 * Payload contract (cf. docs/design/KIOSK_ANALYTICS_EVENTS.md) :
 *  - type        (string, required, whitelist) : catégorie technique.
 *  - event_name  (string, optional)            : nom sémantique (Phase 5).
 *  - payload     (array, optional)             : attributes structurés.
 *  - session_id  (string, optional)            : UUID client éphémère.
 *  - count       (int, optional)               : agrégation (legacy).
 *  - details     (string, optional, 500 max)   : texte libre legacy.
 *  - subtype     (string, optional)            : précision (Phase 3).
 *  - reason      (string, optional)            : motif (Phase 3).
 *  - order_ref   (string, optional)            : référence commande.
 *  - context     (array, optional)             : métadonnées arbitraires.
 *
 * Security invariants :
 *  - Aucun prix calculé / renvoyé par le frontend n'est ré-appliqué
 *    serveur — c'est pour observabilité uniquement.
 *  - `branch_id` est TOUJOURS lu depuis KioskMachine côté serveur ;
 *    le `branch_id` payload est ignoré pour le scope mais conservé
 *    pour le log afin de détecter une éventuelle tentative d'injection.
 *  - Tout event hors whitelist → 422 (strict mode).
 *  - Pas de PII (email/téléphone/nom) — le caller DOIT respecter le
 *    contrat DATA_CONTRACT.md ; le backend ne filtre pas le contenu
 *    de payload/details au-delà du max:500.
 */
class KioskEventController extends Controller
{
    /**
     * Whitelist de `type` (catégorie technique).
     * Élargie pour Phase 5 (analytics, hardware, consent, a11y).
     * Note : les analytics events (menu_viewed, add_to_cart, etc.)
     * utilisent `event_name` ET `type='analytics'`.
     */
    private const ALLOWED_TYPES = [
        // Legacy types (Phase 0)
        'order_abandoned',
        'sync_failed',
        'auth_error',
        'menu_cache_used',
        'admin_action',
        'printer_failure',
        'cash_drawer_failure',
        // Kiosk Design V1 — Phase 3 (écrans UX critiques)
        'cash_instruction_shown',
        'cash_instruction_ack',
        'error_shown',
        'error_retry',
        'error_call_staff',
        'error_back_home',
        'error_back_to_menu',
        'error_payment_retry',
        'error_payment_switch_cash',
        'error_payment_cancel',
        // Kiosk Design V1 — Phase 4 (a11y & locale)
        'a11y_settings',
        // Kiosk Design V1 — Phase 5 (hardware + observability + analytics)
        'analytics',
        'hardware_health',
        'hardware_event',
        'hardware_error',
        'consent_event',
        'idle_event',
    ];

    /**
     * Sous-ensemble d'event_name autorisés quand type === 'analytics'.
     * Aligné sur docs/design/KIOSK_ANALYTICS_EVENTS.md §3.
     */
    private const ALLOWED_ANALYTICS_EVENTS = [
        'menu_viewed',
        'category_selected',
        'search_performed',
        'item_opened',
        'wizard_step_entered',
        'wizard_step_completed',
        'wizard_step_abandoned',
        'wizard_abandoned',
        'filter_toggled',
        'filter_reset',
        'add_to_cart',
        'remove_from_cart',
        'quantity_changed',
        'upsell_shown',
        'upsell_accepted',
        'upsell_rejected',
        'checkout_started',
        'payment_method_selected',
        'payment_completed',
        'payment_failed',
        'order_completed',
        'order_cancelled',
        'a11y_mode_activated',
        'a11y_mode_deactivated',
        'locale_changed',
        'loyalty_scanned',
        'turbo_mode_activated',
        'idle_warning_shown',
        'idle_reset',
        'idle_dismissed',
        'consent_given',
        'hardware_error',
    ];

    private const HARDWARE_TYPES = [
        'printer_failure',
        'cash_drawer_failure',
        'hardware_health',
        'hardware_event',
        'hardware_error',
    ];

    /**
     * Clés interdites dans `payload` (protection RGPD / PII).
     * Si une clé ci-dessous est présente, l'événement est 422.
     */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'email', 'phone', 'telephone', 'first_name', 'last_name', 'name',
        'iban', 'card_number', 'pan', 'cvv', 'cvc', 'full_address',
        'customer_email', 'customer_phone', 'customer_name',
    ];

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'type'       => ['required', 'string', 'max:50'],
            'event_name' => ['nullable', 'string', 'max:50'],
            'payload'    => ['nullable', 'array'],
            'session_id' => ['nullable', 'string', 'max:64'],
            'count'      => ['nullable', 'integer', 'min:0'],
            'branch_id'  => ['nullable', 'integer'],
            'details'    => ['nullable', 'string', 'max:500'],
            'subtype'    => ['nullable', 'string', 'max:50'],
            'reason'     => ['nullable', 'string', 'max:50'],
            'order_ref'  => ['nullable', 'string', 'max:64'],
            'context'    => ['nullable', 'array'],
        ]);

        $type       = $request->input('type');
        $eventName  = $request->input('event_name');
        $payload    = $request->input('payload');
        $sessionId  = $request->input('session_id');

        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            return response()->json(['status' => false, 'message' => 'Unknown event type'], 422);
        }

        // Cas analytics : event_name requis et whitelisté.
        if ($type === 'analytics') {
            if (empty($eventName)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'event_name required when type=analytics',
                ], 422);
            }
            if (! in_array($eventName, self::ALLOWED_ANALYTICS_EVENTS, true)) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Unknown analytics event_name',
                ], 422);
            }
        }

        // Guard RGPD : payload ne doit contenir aucune clé interdite.
        if (is_array($payload) && $this->payloadHasForbiddenKeys($payload)) {
            return response()->json([
                'status'  => false,
                'message' => 'Payload contains forbidden PII keys',
            ], 422);
        }

        $user    = Auth::user();
        $machine = $user ? KioskMachine::where('user_id', $user->id)->first() : null;

        $extra = array_filter([
            'event_name' => $eventName,
            'session_id' => $sessionId,
            'payload'    => $payload,
            'subtype'    => $request->input('subtype'),
            'reason'     => $request->input('reason'),
            'order_ref'  => $request->input('order_ref'),
            'context'    => $request->input('context'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $details = sprintf(
            'type=%s | count=%s | branch=%s | machine=%s | %s%s',
            $type,
            $request->input('count', 1),
            $request->input('branch_id', $machine?->branch_id ?? 'unknown'),
            $machine?->username ?? 'unknown',
            $request->input('details', ''),
            $extra ? ' | meta=' . json_encode($extra, JSON_UNESCAPED_UNICODE) : ''
        );
        // Guard hard-cap 500 chars.
        if (mb_strlen($details) > 500) {
            $details = mb_substr($details, 0, 497) . '...';
        }

        try {
            ActionLog::create([
                'user_id'  => $user?->id,
                'action'   => 'Kiosk event: ' . ($eventName ?: $type),
                'resource' => 'Borne',
                'details'  => $details,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[C6] KioskEvent ActionLog failed: ' . $e->getMessage());
        }

        if (in_array($type, self::HARDWARE_TYPES, true)) {
            try {
                Log::channel('hardware')->warning($details);
            } catch (\Throwable $e) {
                Log::warning('[C6] Hardware log channel failed: ' . $e->getMessage());
            }
        }

        return response()->json(['status' => true], 200);
    }

    /**
     * Détecte récursivement la présence d'une clé interdite dans un
     * tableau (payload arbitrairement imbriqué). Case-insensitive.
     */
    private function payloadHasForbiddenKeys(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_PAYLOAD_KEYS, true)) {
                return true;
            }
            if (is_array($value) && $this->payloadHasForbiddenKeys($value)) {
                return true;
            }
        }
        return false;
    }
}

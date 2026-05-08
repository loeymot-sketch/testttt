<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Delivery\UpdateDeliveryPlatformRequest;
use App\Models\DeliveryPlatform;
use App\Services\Fiscal\AuditLogService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * [PARALLEL-TRACK-1.4 / Delivery Platform Integration — Phase 4]
 *
 * Admin-side CRUD-lite controller for the per-branch delivery
 * platform configuration created in Phase 1.
 *
 * Discipline:
 *   - Credentials NEVER leak to the index/show payload. The model
 *     cast `encrypted:json` decrypts them in memory, but every
 *     accessor in this controller masks them down to the last 4
 *     visible characters before serialising.
 *   - The `reveal()` endpoint is the *only* way to see plaintext,
 *     and it emits an `delivery.platform.credentials_revealed`
 *     audit row before returning anything. That row carries the
 *     authenticated user_id and IP — required for any post-mortem
 *     review of "who saw the secret".
 *   - `update()` and `toggleEnabled()` audit-log every change to
 *     the encrypted blob with a redacted diff (key names only,
 *     never values).
 *
 * Permissions: re-uses the existing `settings` Spatie permission
 * via the standard `permission:settings` middleware — the same gate
 * that protects MailController, NotificationAlertController, …
 *
 * Branch isolation: DeliveryPlatform applies BranchScope globally
 * (see model). For the admin UI we explicitly ignore that scope so
 * a head-office Admin can edit configs across branches; the
 * `withoutGlobalScopes()` call is therefore intentional and
 * branch_id remains in every payload so the UI knows what it's
 * touching.
 */
class DeliveryPlatformController extends AdminController
{
    private AuditLogService $audit;

    public function __construct(AuditLogService $audit)
    {
        parent::__construct();
        $this->audit = $audit;
        $this->middleware(['permission:settings'])->only([
            'index', 'show', 'update', 'toggleEnabled',
            'reveal', 'webhookUrl',
        ]);
    }

    /**
     * GET /api/admin/delivery-platforms
     *
     * Returns the full list across branches (head-office view).
     * Supports `?branch_id=` filter for the UI dropdown.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = DeliveryPlatform::query()->withoutGlobalScopes();

            if ($request->filled('branch_id')) {
                $query->where('branch_id', (int) $request->input('branch_id'));
            }

            $platforms = $query->orderBy('branch_id')
                ->orderBy('platform')
                ->get();

            return response()->json([
                'data' => $platforms->map(fn (DeliveryPlatform $p) => $this->presentMasked($p))->values(),
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/admin/delivery-platforms/{id}
     *
     * We resolve the row manually with `withoutGlobalScopes()` so a
     * head-office Admin whose `branch_id` does not match the row's
     * branch can still view / edit it. Route-model binding would
     * silently apply BranchScope and 404 cross-branch rows.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $deliveryPlatform = $this->resolveOrFail($id);
            return response()->json(['data' => $this->presentMasked($deliveryPlatform)]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * PUT /api/admin/delivery-platforms/{id}
     *
     * Merges submitted credentials onto the existing decrypted blob
     * so an operator can rotate one secret without resending the
     * whole set of keys.
     */
    public function update(UpdateDeliveryPlatformRequest $request, int $id): JsonResponse
    {
        $deliveryPlatform = $this->resolveOrFail($id);

        try {
            $changed = [];

            if ($request->has('enabled')) {
                $newEnabled = (bool) $request->boolean('enabled');
                if ($newEnabled !== (bool) $deliveryPlatform->enabled) {
                    $changed['enabled'] = ['from' => (bool) $deliveryPlatform->enabled, 'to' => $newEnabled];
                    $deliveryPlatform->enabled = $newEnabled;
                }
            }

            if ($request->has('external_store_id')) {
                $newStoreId = (string) $request->input('external_store_id');
                if ($newStoreId !== (string) $deliveryPlatform->external_store_id) {
                    $changed['external_store_id'] = [
                        'from' => $deliveryPlatform->external_store_id,
                        'to'   => $newStoreId,
                    ];
                    $deliveryPlatform->external_store_id = $newStoreId;
                }
            }

            if ($request->has('credentials')) {
                $existing = (array) ($deliveryPlatform->credentials ?? []);
                $submitted = (array) $request->input('credentials', []);
                // Merge: only the submitted keys overwrite, others stay.
                $merged = array_merge($existing, array_filter(
                    $submitted,
                    fn ($v) => $v !== null && $v !== ''
                ));

                $changedKeys = array_keys(array_filter(
                    $submitted,
                    fn ($v) => $v !== null && $v !== ''
                ));

                if (!empty($changedKeys)) {
                    $changed['credentials_keys'] = array_values($changedKeys);
                    if (in_array('webhook_secret', $changedKeys, true)) {
                        $deliveryPlatform->webhook_secret_rotated_at = now();
                    }
                    $deliveryPlatform->credentials = $merged;
                }
            }

            $deliveryPlatform->save();

            $this->audit->write([
                'branch_id'   => (int) $deliveryPlatform->branch_id,
                'user_id'     => Auth::id(),
                'action'      => 'delivery.platform.updated',
                'resource'    => 'delivery_platform',
                'resource_id' => (int) $deliveryPlatform->id,
                'payload'     => [
                    'platform' => $deliveryPlatform->platform,
                    'changed'  => $changed,
                ],
            ]);

            return response()->json(['data' => $this->presentMasked($deliveryPlatform->fresh())]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/admin/delivery-platforms/{id}/toggle
     */
    public function toggleEnabled(int $id): JsonResponse
    {
        $deliveryPlatform = $this->resolveOrFail($id);

        try {
            $previous = (bool) $deliveryPlatform->enabled;
            $deliveryPlatform->enabled = !$previous;
            $deliveryPlatform->save();

            $this->audit->write([
                'branch_id'   => (int) $deliveryPlatform->branch_id,
                'user_id'     => Auth::id(),
                'action'      => 'delivery.platform.toggled',
                'resource'    => 'delivery_platform',
                'resource_id' => (int) $deliveryPlatform->id,
                'payload'     => [
                    'platform' => $deliveryPlatform->platform,
                    'from'     => $previous,
                    'to'       => (bool) $deliveryPlatform->enabled,
                ],
            ]);

            return response()->json(['data' => $this->presentMasked($deliveryPlatform->fresh())]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /api/admin/delivery-platforms/{id}/reveal
     *
     * Returns plaintext credentials. Restricted to the Admin role
     * regardless of `settings` permission — operators with
     * `settings` can rotate secrets but never read them. Every call
     * is audit-logged with the requester id and IP.
     */
    public function reveal(int $id): JsonResponse
    {
        $user = Auth::user();
        $isAdmin = $user
            && method_exists($user, 'hasRole')
            && $user->hasRole('Admin');

        if (!$isAdmin) {
            return response()->json(['status' => false, 'message' => 'Forbidden.'], 403);
        }

        $deliveryPlatform = $this->resolveOrFail($id);

        try {

            $this->audit->write([
                'branch_id'   => (int) $deliveryPlatform->branch_id,
                'user_id'     => Auth::id(),
                'action'      => 'delivery.platform.credentials_revealed',
                'resource'    => 'delivery_platform',
                'resource_id' => (int) $deliveryPlatform->id,
                'payload'     => [
                    'platform' => $deliveryPlatform->platform,
                ],
            ]);

            return response()->json([
                'data' => [
                    'id'          => (int) $deliveryPlatform->id,
                    'platform'    => $deliveryPlatform->platform,
                    'branch_id'   => (int) $deliveryPlatform->branch_id,
                    'credentials' => (array) ($deliveryPlatform->credentials ?? []),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * GET /api/admin/delivery-platforms/{id}/webhook-url
     *
     * Returns the canonical webhook URL the operator must register
     * on the third-party platform's dashboard. Mirrors the route
     * defined for DeliveryWebhookController (see routes/api.php).
     */
    public function webhookUrl(int $id): JsonResponse
    {
        $deliveryPlatform = $this->resolveOrFail($id);

        try {
            $appUrl = (string) Config::get('app.url', config('app.url'));
            $appUrl = rtrim($appUrl, '/');
            $platform = $deliveryPlatform->platform;

            // Pattern from routes/api.php:
            //   POST /api/webhooks/delivery/{platform}/{event}
            // We surface the {platform} part and document {event} in
            // the modal's helper text since each platform sends
            // different event names.
            $url = sprintf('%s/api/webhooks/delivery/%s/{event}', $appUrl, $platform);

            return response()->json([
                'data' => [
                    'platform' => $platform,
                    'url'      => $url,
                    'note'     => 'Replace {event} with the event name the platform sends (order.created, order.updated, …).',
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /**
     * Resolve a DeliveryPlatform by id without applying BranchScope.
     *
     * Head-office Admins must be able to edit configs across all
     * branches; the global scope on the model would 404 every row
     * whose branch_id does not match the actor's branch_id. This
     * helper centralises the override so we never accidentally
     * forget to skip the scope on a new endpoint.
     */
    private function resolveOrFail(int $id): DeliveryPlatform
    {
        return DeliveryPlatform::query()
            ->withoutGlobalScopes()
            ->findOrFail($id);
    }

    /**
     * Render a row with credentials masked (last 4 chars visible).
     * Always returns the SAME shape the UI expects, even when the
     * credentials column is empty.
     */
    private function presentMasked(DeliveryPlatform $platform): array
    {
        $creds = (array) ($platform->credentials ?? []);
        $masked = [];
        foreach (['client_id', 'client_secret', 'webhook_secret', 'api_key', 'refresh_token'] as $k) {
            if (array_key_exists($k, $creds) && is_string($creds[$k]) && $creds[$k] !== '') {
                $masked[$k] = $this->mask((string) $creds[$k]);
                $masked[$k . '_set'] = true;
            } else {
                $masked[$k] = null;
                $masked[$k . '_set'] = false;
            }
        }

        return [
            'id'                          => (int) $platform->id,
            'branch_id'                   => (int) $platform->branch_id,
            'platform'                    => $platform->platform,
            'enabled'                     => (bool) $platform->enabled,
            'external_store_id'           => $platform->external_store_id,
            'credentials_masked'          => $masked,
            'webhook_secret_rotated_at'   => optional($platform->webhook_secret_rotated_at)->toIso8601String(),
            'last_webhook_received_at'    => optional($platform->last_webhook_received_at)->toIso8601String(),
            'last_status_pushed_at'       => optional($platform->last_status_pushed_at)->toIso8601String(),
            'created_at'                  => optional($platform->created_at)->toIso8601String(),
            'updated_at'                  => optional($platform->updated_at)->toIso8601String(),
        ];
    }

    private function mask(string $value): string
    {
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', max(4, $len));
        }
        return str_repeat('*', max(4, min(8, $len - 4))) . substr($value, -4);
    }
}

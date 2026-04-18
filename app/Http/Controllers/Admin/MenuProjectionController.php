<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Menu\MenuProjectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read-only admin endpoint returning the channel-scoped menu projection.
 *
 *     GET /api/admin/menu-projection?channel=kiosk&branch_id=1
 *
 * Lives inside the admin API group so it inherits:
 *   - `installed` + `apiKey` + `auth:sanctum`
 *   - `throttle:admin-mutation` (120 rpm / user)
 *
 * Consumers (POS / Kiosk) may be switched to this endpoint in V1.5 via an
 * opt-in feature flag. For V1 the endpoint is available for inspection and
 * serves as the wire contract for the incoming admin catalog UI.
 *
 * @see app/Services/Menu/MenuProjectionService.php
 * @see docs/MENU_PROJECTIONS.md
 */
class MenuProjectionController extends Controller
{
    public function __construct(
        private readonly MenuProjectionService $projection,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel'   => ['required', 'string', Rule::in(MenuProjectionService::SUPPORTED_CHANNELS)],
            'branch_id' => ['required', 'integer', 'min:1'],
        ]);

        $payload = $this->projection->forChannel(
            (string) $validated['channel'],
            (int) $validated['branch_id'],
        );

        return response()->json($payload);
    }
}

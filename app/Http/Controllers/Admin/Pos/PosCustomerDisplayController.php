<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Services\Hardware\CustomerDisplayService;
use App\Services\Hardware\DisplayTransport\CustomerDisplayTransportInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [CUSTOMER-DISPLAY 2026-06-28] Refreshes the SAGA pole display from the POS:
 *   - mode=total  → shows the running cart total (only the total),
 *   - mode=welcome → shows the idle welcome message.
 *
 * Best-effort by design: a missing/failed display returns 200 {sent:false} and
 * NEVER blocks the cashier (try/catch + non-throwing transport).
 */
class PosCustomerDisplayController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'nullable|in:total,welcome',
            'total' => 'nullable|numeric',
        ]);

        $config = (array) config('printing.customer_display', []);
        if (empty($config['enabled'])) {
            return response()->json(['enabled' => false, 'sent' => false]);
        }

        try {
            $service = new CustomerDisplayService(app(CustomerDisplayTransportInterface::class), $config);
            $mode = $data['mode'] ?? 'total';
            $sent = $mode === 'welcome' || ! isset($data['total'])
                ? $service->showWelcome()
                : $service->showTotal((float) $data['total']);

            if (! $sent && $service->lastError()) {
                Log::warning('[CustomerDisplay] send failed', ['error' => $service->lastError(), 'mode' => $mode]);
            }

            return response()->json(['enabled' => true, 'sent' => $sent]);
        } catch (Throwable $e) {
            Log::warning('[CustomerDisplay] exception', ['error' => $e->getMessage()]);

            return response()->json(['enabled' => true, 'sent' => false]);
        }
    }
}

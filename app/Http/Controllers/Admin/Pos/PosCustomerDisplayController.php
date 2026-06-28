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
            $send = fn () => $mode === 'welcome' || ! isset($data['total'])
                ? $service->showWelcome()
                : $service->showTotal((float) $data['total']);

            // [AUDIT P2] Serialize writes to the single serial port: two concurrent
            // COM opens would make the 2nd fail ("port in use") and could leave a
            // stale total. The lock makes refreshes run in arrival order (last wins).
            try {
                $sent = \Illuminate\Support\Facades\Cache::lock('pos:customer-display', 5)->block(2, $send);
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
                $sent = false; // a fresher refresh is already running — drop this one
            }

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

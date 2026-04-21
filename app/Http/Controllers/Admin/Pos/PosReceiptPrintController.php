<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosReceiptPrintController extends Controller
{
    public function increment(Request $request, int $order): JsonResponse
    {
        $branchId = (int) $request->user()->branch_id;

        $updated = Order::query()
            ->whereKey($order)
            ->where('branch_id', $branchId)
            ->update([
                'receipt_print_count' => DB::raw('COALESCE(receipt_print_count, 0) + 1'),
            ]);

        if ($updated === 0) {
            abort(404);
        }

        $freshOrder = Order::query()
            ->select(['id', 'receipt_print_count'])
            ->whereKey($order)
            ->where('branch_id', $branchId)
            ->firstOrFail();

        return response()->json([
            'order_id' => $freshOrder->id,
            'receipt_print_count' => (int) $freshOrder->receipt_print_count,
            'is_duplicata' => (int) $freshOrder->receipt_print_count >= 2,
        ]);
    }
}

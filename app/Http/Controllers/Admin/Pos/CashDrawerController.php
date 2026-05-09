<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Admin\AdminController;
use App\Services\Hardware\EscPosPrinterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashDrawerController extends AdminController
{
    public function __construct(private readonly EscPosPrinterService $printerService)
    {
        parent::__construct();

        $this->middleware(['permission:pos']);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printer_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $branchId = (int) (auth()->user()?->branch_id ?? 0);
        $printerId = isset($data['printer_id']) ? (int) $data['printer_id'] : null;

        $result = $this->printerService->openDrawer($printerId, $branchId);

        $status = ($result['success'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}

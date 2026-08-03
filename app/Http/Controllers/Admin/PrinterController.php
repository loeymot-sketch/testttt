<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PrinterRequest;
use App\Http\Resources\PrinterResource;
use App\Models\Printer;
use App\Services\Hardware\EscPosPrinterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrinterController extends AdminController
{
    public function __construct(
        private readonly EscPosPrinterService $printerService
    ) {
        parent::__construct();

        $this->middleware(['permission:pos'])->only('index', 'show', 'testPrint');
        $this->middleware(['permission:settings'])->only('store', 'update', 'destroy');
    }

    public function index(Request $request)
    {
        $query = Printer::query()->latest('id');

        $requestBranchId = (int) $request->query('branch_id', 0);
        if ($requestBranchId > 0 && (int) $request->user()?->branch_id === 0) {
            $query->where('branch_id', $requestBranchId);
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        return PrinterResource::collection($query->paginate($perPage));
    }

    public function store(PrinterRequest $request)
    {
        $data = $request->validated();
        $data['branch_id'] = $this->resolveBranchId($request);
        $data['port'] = (int) ($data['port'] ?? 9100);
        $data['width_chars'] = (int) ($data['width_chars'] ?? 48);
        $data['status'] = (int) ($data['status'] ?? 1);

        $printer = Printer::query()->create($data);

        return (new PrinterResource($printer))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Printer $printer): PrinterResource
    {
        return new PrinterResource($printer);
    }

    public function update(PrinterRequest $request, Printer $printer): PrinterResource
    {
        $data = $request->validated();

        if ((int) $request->user()?->branch_id > 0) {
            unset($data['branch_id']);
        }

        $printer->update($data);

        return new PrinterResource($printer->fresh());
    }

    public function destroy(Printer $printer): JsonResponse
    {
        $printer->delete();

        return response()->json(null, 204);
    }

    public function testPrint(Printer $printer): JsonResponse
    {
        $ok = $this->printerService->testPrint($printer);

        return response()->json([
            'ok' => $ok,
            'printer_id' => $printer->id,
        ], $ok ? 200 : 502);
    }

    private function resolveBranchId(Request $request): int
    {
        $userBranchId = (int) ($request->user()?->branch_id ?? 0);

        if ($userBranchId > 0) {
            return $userBranchId;
        }

        return (int) $request->validated('branch_id');
    }
}

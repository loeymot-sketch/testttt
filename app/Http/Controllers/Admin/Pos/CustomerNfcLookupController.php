<?php

namespace App\Http\Controllers\Admin\Pos;

use App\Http\Controllers\Admin\AdminController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerNfcLookupController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:pos']);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nfc_uid' => ['required', 'string', 'max:64'],
        ]);

        $uid = $data['nfc_uid'];
        $branchId = (int) (auth()->user()?->branch_id ?? 0);

        // Roles are stored against `App\Models\User` (Spatie morph); use `User` + `role()`
        // even though business logic treats the row as a storefront customer.
        $customer = User::query()
            ->role('Customer')
            ->where('nfc_uid', $uid)
            ->where('branch_id', $branchId)
            ->first();

        if (! $customer) {
            return response()->json([
                'message' => 'customer_nfc_not_found',
            ], 404);
        }

        return response()->json([
            'customer' => [
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
                'phone' => $customer->phone !== null ? (string) $customer->phone : null,
                'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
            ],
        ]);
    }
}

<?php

namespace App\Services;


use Exception;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TransactionService
{

    protected array $transactionFilter = [
        'transaction_no',
        'payment_method',
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';
            $branchScope = $this->resolveBranchScope($requests['branch_id'] ?? null);

            return Transaction::with('order')->where(function ($query) use ($requests, $branchScope) {
                if ($branchScope !== null) {
                    $query->whereHas('order', function ($query) use ($branchScope) {
                        $query->where('branch_id', '=', $branchScope);
                    });
                }

                if (isset($requests['order_serial_no'])) {
                    $query->whereHas('order', function ($query) use ($requests) {
                        $query->where(['order_serial_no' => $requests['order_serial_no']]);
                    });
                }

                if (isset($requests['from_date']) && isset($requests['to_date'])) {
                    $first_date = date('Y-m-d', strtotime($requests['from_date']));
                    $last_date  = date('Y-m-d', strtotime($requests['to_date']));
                    $query->whereDate('created_at', '>=', $first_date)->whereDate(
                        'created_at',
                        '<=',
                        $last_date
                    );
                }
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->transactionFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function resolveBranchScope($requestedBranchId): ?int
    {
        $user = Auth::user();
        if ($this->isGlobalAdmin($user)) {
            return $requestedBranchId !== null && (int) $requestedBranchId > 0
                ? (int) $requestedBranchId
                : null;
        }

        $userBranchId = (int) ($user?->branch_id ?? 0);
        if ($userBranchId <= 0) {
            abort(403, 'Access denied: invalid branch scope.');
        }

        if ($requestedBranchId !== null && (int) $requestedBranchId !== $userBranchId) {
            abort(403, 'Access denied: transactions do not belong to your branch.');
        }

        return $userBranchId;
    }

    private function isGlobalAdmin(?User $user): bool
    {
        return $user !== null
            && $user->branch_id !== null
            && (int) $user->branch_id === 0
            && method_exists($user, 'hasRole')
            && $user->hasRole('Admin');
    }
}

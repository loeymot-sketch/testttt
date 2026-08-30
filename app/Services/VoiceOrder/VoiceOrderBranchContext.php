<?php

namespace App\Services\VoiceOrder;

use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VoiceOrderBranchContext
{
    public function fromAdminRequest(Request $request): int
    {
        $branchId = (int) ($request->user()?->branch_id ?? 0);

        if ($branchId <= 0) {
            throw new HttpException(422, 'Une filiale active est obligatoire pour l’assistant téléphonique.');
        }

        return $branchId;
    }
}

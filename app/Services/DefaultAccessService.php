<?php

namespace App\Services;

use Exception;
use App\Models\DefaultAccess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;

class DefaultAccessService
{
    /**
     * @throws Exception
     */
    public function show(): array
    {
        try {
            $array         = [];
            $defaultAccess = DefaultAccess::where(['user_id' => Auth::id()])->get();
            if ($defaultAccess) {
                foreach ($defaultAccess as $default) {
                    $array[$default->name] = $default->default_id;
                }
            }

            if (!array_key_exists('branch_id', $array)) {
                $array['branch_id'] = $this->fallbackBranchId();
            }

            return $array;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function storeOrUpdate($request = []): array
    {
        try {
            if (!blank($request)) {
                foreach ($request as $key => $item) {
                    if ($key == 'branch_id') {
                        if (Auth::user()->branch_id != '0') {
                            $item = Auth::user()->branch_id;
                        }
                    }
                    $defaultAccess             = DefaultAccess::firstOrNew(['user_id' => Auth::id(), 'name' => $key]);
                    $defaultAccess->default_id = $item;
                    $defaultAccess->save();
                }
            }
            return $this->show();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    private function fallbackBranchId(): ?int
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }

        $branchId = (int) $user->branch_id;
        if ($branchId > 0) {
            return $branchId;
        }

        $defaultBranch = Settings::group('site')->get('site_default_branch');
        if ($defaultBranch === null || $defaultBranch === '') {
            return null;
        }

        $defaultBranchId = (int) $defaultBranch;

        return $defaultBranchId > 0 ? $defaultBranchId : null;
    }
}

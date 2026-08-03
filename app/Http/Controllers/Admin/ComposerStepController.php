<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\ComposerStepRequest;
use App\Http\Resources\ComposerStepResource;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerStepService;
use Illuminate\Http\Request;

class ComposerStepController extends AdminController
{
    public function __construct(private readonly ComposerStepService $steps)
    {
        parent::__construct();
    }

    public function store(ComposerStepRequest $request, ItemWizardProfile $profile)
    {
        $this->authorizeWritableBranchScope($request, $profile->branch_id_scope);

        return new ComposerStepResource($this->steps->create($profile, $request->validated()));
    }

    public function update(ComposerStepRequest $request, ItemWizardStep $step)
    {
        $this->authorizeWritableBranchScope($request, $step->profile?->branch_id_scope);

        return new ComposerStepResource($this->steps->update($step, $request->validated()));
    }

    public function destroy(Request $request, ItemWizardStep $step)
    {
        $this->authorizeWritableBranchScope($request, $step->profile?->branch_id_scope);

        $this->steps->delete($step);

        return response(['status' => true]);
    }
}

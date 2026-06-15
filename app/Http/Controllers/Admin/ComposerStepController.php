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
        $this->assertPublishGate($request, $profile);

        return new ComposerStepResource($this->steps->create($profile, $request->validated()));
    }

    public function update(ComposerStepRequest $request, ItemWizardStep $step)
    {
        $this->authorizeWritableBranchScope($request, $step->profile?->branch_id_scope);
        $this->assertPublishGate($request, $step->profile);

        return new ComposerStepResource($this->steps->update($step, $request->validated()));
    }

    public function destroy(Request $request, ItemWizardStep $step)
    {
        $this->authorizeWritableBranchScope($request, $step->profile?->branch_id_scope);
        $this->assertPublishGate($request, $step->profile);

        $this->steps->delete($step);

        return response(['status' => true]);
    }

    /**
     * [SEC-01 sibling] Adding/editing/deleting a step on an already-PUBLISHED profile broadcasts
     * the change live to every borne (ComposerStepService::dispatchProfileChanged →
     * ComposerProfileChanged → kiosk cache invalidation + outbox), identical to
     * ComposerProfileController::update(). That live push is a publish-level action and must require
     * the publish permission — not be reachable with catalog.compose alone. Drafts stay compose-level.
     */
    private function assertPublishGate(Request $request, ?ItemWizardProfile $profile): void
    {
        abort_unless(
            ! $profile || $profile->is_published === false || $request->user()?->can('catalog.publish'),
            403,
            'Modifier un wizard publié (diffusion en direct) requiert la permission de publication.'
        );
    }
}

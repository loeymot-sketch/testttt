<?php

namespace App\Http\Middleware;

use App\Models\ItemWizardProfile;
use App\Support\WizardPerItemDemo;
use App\Models\ItemWizardStep;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureProfileNotItemOwnedUnlessDemoEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (WizardPerItemDemo::enabled($request)) {
            return $next($request);
        }

        $profile = $this->resolveProfile($request);

        if ($profile && $profile->ownerType() === 'item') {
            abort(404);
        }

        return $next($request);
    }

    private function resolveProfile(Request $request): ?ItemWizardProfile
    {
        try {
            $profile = $this->profileFromRouteValue($request->route('profile'));

            if ($profile) {
                return $profile;
            }

            $step = $request->route('step');

            if ($step instanceof ItemWizardStep) {
                return $step->relationLoaded('profile') ? $step->profile : $step->profile()->first();
            }

            if (is_numeric($step)) {
                return ItemWizardStep::query()->find($step)?->profile;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function profileFromRouteValue(mixed $profile): ?ItemWizardProfile
    {
        if ($profile instanceof ItemWizardProfile) {
            return $profile;
        }

        if (is_numeric($profile)) {
            return ItemWizardProfile::query()->find($profile);
        }

        return null;
    }
}

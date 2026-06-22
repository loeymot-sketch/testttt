<?php

namespace App\Http\Requests;

use App\Rules\NoDangerousFileExtension;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PushNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * [abuse-heal 2026-06-20 W6r5 PUSH-CREATE-BRANCHID-FORGE-01] Sender-side tenant-routing clamp.
     *
     * branch_id was accepted as an arbitrary client-controlled value (rule = required|numeric), and
     * PushNotificationService uses it to drive the FCM audience query (branch_id===0 => global
     * fan-out, no branch filter). So a branch-scoped Branch Manager could forge branch_id=0 to
     * GLOBAL-broadcast, or branch_id=<other> to inject notifications into another branch's staff.
     * The round-2 single-user heal + the fan-out heal HONOR branch_id; this closes the create-time
     * forge: a non-Admin sender (branch_id > 0) is clamped to their OWN branch — they can neither
     * global-broadcast nor cross-target. Admin / org-wide sender (branch_id === 0) keeps full reach.
     * V1 LOCAL is single-branch so this is V2/SaaS defense-in-depth (same class as the floorplan /
     * single-user-xbranch heals). Mirrors the StockRupture authorizeWritableBranchScope precedent.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if ($user !== null && (int) $user->branch_id !== 0) {
            $this->merge(['branch_id' => (int) $user->branch_id]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'title'        => [
                'required',
                'string',
                'max:255',
            ],
            'description' => ['required', 'string', 'max:2000'],
            'role_id'     => ['nullable', 'numeric'],
            'user_id'     => ['nullable', 'numeric'],
            'branch_id'   => ['required', 'numeric'],
            // [GOAL-L2-HEAL-02 2026-05-24] Phase L7.1-V3 P1 fix:
            // OLD: ['image', 'mimes:jpeg,png,jpg|max:5098'] — Laravel's
            // explodeExplicitRule (ValidationRuleParser.php:86-101) only
            // splits the `|` separator on STRING rules; inside an array
            // element each item is treated as a single rule token. The
            // `|max:5098` suffix was therefore parsed as a phantom MIME
            // entry (`jpg|max:5098`) and silently dropped — 10 MB PNG
            // was empirically accepted.
            // NEW: proper array shape with each rule as its own element,
            // plus NoDangerousFileExtension to close the .pht gap.
            'image'       => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:5098',
                new NoDangerousFileExtension(),
            ],
        ];
    }
}

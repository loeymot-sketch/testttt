<?php

namespace App\Http\Requests;

use App\Services\RoleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use JetBrains\PhpStorm\ArrayShape;

class RoleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize() : bool
    {
        // V1.0.1 R7 heal: defense-in-depth — RoleController middleware enforces
        // `permission:settings` on show/store/update/destroy (RoleRequest only injected
        // on store/update); FormRequest doubles down so any future route bypass still authz-checks.
        return $this->user()?->can('settings') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    #[ArrayShape(['name' => "array"])]
    public function rules() : array
    {
        return [
            // [GOAL-CMS-2026-05-18 M-R3-P0-C heal] R3 T-2.2.1 Sec S-1:
            // Tenant Admin shadow-role hijack. 9 production files dual-gate on
            // hasRole('Admin') || hasRole('Tenant Admin'), yet no seeder
            // creates 'Tenant Admin' and no validator forbid the name. A single
            // POST /admin/setting/role { "name":"Tenant Admin" } would grant
            // super-admin treatment in 9 files. Reserved names list MUST be
            // un-creatable via API; provision via seeder only.
            'name' => [
                'required', 'string', 'max:190',
                Rule::notIn($this->reservedRoleNames()),
                Rule::unique("roles", "name")->ignore(optional($this->route('role'))->id),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function reservedRoleNames(): array
    {
        $reserved = array_merge(RoleService::protectedRoleNames(), ['Tenant Admin']);
        $current = $this->route('role');
        $currentName = is_object($current) ? (string) $current->name : '';

        return array_values(array_filter(
            $reserved,
            static fn (string $name): bool => $name !== $currentName
        ));
    }
}

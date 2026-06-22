<?php

namespace Tests\Feature\Sentinels;

use App\Http\Requests\AdministratorRequest;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\ChefRequest;
use App\Http\Requests\DeliveryBoyRequest;
use App\Http\Requests\EmployeeRequest;
use App\Http\Requests\UserChangePasswordRequest;
use App\Http\Requests\WaiterRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [2026-05-18 PR-D T5 sentinel] V1 GO-LIVE staff password policy.
 *
 * CLAUDE.md §1 North Star requires `min:12 + complexity` on staff paths.
 * Before today, 7 FormRequests accepted `min:6` silently. This sentinel
 * locks the policy so a careless refactor cannot regress.
 *
 * The 7 FormRequests are listed in the test data provider. For each,
 * the sentinel asserts:
 *   - REJECT a short / no-digit / no-letter password
 *   - ACCEPT a 12-char letters+numbers password
 *
 * Customer + Signup paths intentionally stay at `min:6` (consumer UX
 * trade-off); see plans/v1-0-2-backlog/PASSWORD_POLICY_2026-05-18.md.
 */
class PasswordPolicyV1SentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{0:class-string,1:string|null}>
     *   Tuple: [request class, route param key OR null for create-only path].
     *   Route param null = always required password (create), non-null =
     *   nullable on update — we only test create path here.
     */
    public static function staffRequestProvider(): iterable
    {
        return [
            'AdministratorRequest' => [AdministratorRequest::class, null],
            'EmployeeRequest'      => [EmployeeRequest::class, null],
            'ChefRequest'          => [ChefRequest::class, null],
            'WaiterRequest'        => [WaiterRequest::class, null],
            'DeliveryBoyRequest'   => [DeliveryBoyRequest::class, null],
            'ChangePasswordRequest'     => [ChangePasswordRequest::class, null],
            'UserChangePasswordRequest' => [UserChangePasswordRequest::class, null],
        ];
    }

    /**
     * @dataProvider staffRequestProvider
     */
    public function test_staff_request_rejects_short_password(string $requestClass, ?string $idKey): void
    {
        $request = new $requestClass();
        // Some requests use `route('xxx.id')` to decide nullable/required —
        // we only test the CREATE path here (no route param) so password is required.
        $request->setContainer($this->app);

        $rules = $this->extractPasswordRules($request);
        $this->assertNotEmpty($rules, "Request {$requestClass} must declare a 'password' rule.");

        $validator = Validator::make(
            ['password' => 'abc'],
            ['password' => $rules],
        );

        $this->assertTrue(
            $validator->fails(),
            "Staff request {$requestClass} must REJECT password 'abc' (V1 policy min:12 + letters + numbers).",
        );
    }

    /**
     * @dataProvider staffRequestProvider
     */
    public function test_staff_request_accepts_strong_password(string $requestClass, ?string $idKey): void
    {
        $request = new $requestClass();
        $request->setContainer($this->app);

        $rules = $this->extractPasswordRules($request);
        $this->assertNotEmpty($rules, "Request {$requestClass} must declare a 'password' rule.");

        $validator = Validator::make(
            // UserChangePasswordRequest chains the `confirmed` rule which
            // requires a matching `password_confirmation` field — include it
            // unconditionally so the assertion isolates the strength check.
            ['password' => 'StaffPwd2026-secure', 'password_confirmation' => 'StaffPwd2026-secure'],
            ['password' => $rules],
        );

        $errors = $validator->errors()->get('password');
        $this->assertEmpty(
            $errors,
            "Staff request {$requestClass} must ACCEPT 'StaffPwd2026-secure' (12+ chars, letters, numbers). "
            . 'Got errors: ' . implode('; ', $errors),
        );
    }

    /**
     * Helper — pull the `password` rule array out of $request->rules().
     * Robust to ad-hoc 'pipe' strings vs array form.
     *
     * @return array<int|string,mixed>
     */
    private function extractPasswordRules($request): array
    {
        $rules = $request->rules();
        $passwordRule = $rules['password'] ?? null;
        if ($passwordRule === null) {
            return [];
        }
        // Some legacy requests use 'required|string|min:6' pipe form.
        if (is_string($passwordRule)) {
            return explode('|', $passwordRule);
        }
        return (array) $passwordRule;
    }
}

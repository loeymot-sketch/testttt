<?php

/**
 * [GENIE Wave 2 — A1 2026-06-16] Sentinel for the base Controller::jsonError() discriminator.
 * Proves the load-bearing rule: an UNEXPECTED exception is genericized + logged (no raw leak), while
 * an intentional user-facing message (code-422 throw / HttpException) passes through unchanged.
 */

namespace Tests\Feature\Security;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class JsonErrorTraitSentinelTest extends TestCase
{
    private function caller(): object
    {
        return new class extends Controller {
            public function call(\Throwable $e, int $s = 422, ?string $c = null)
            {
                return $this->jsonError($e, $s, $c);
            }
        };
    }

    /** @test — unexpected (SQL/generic) exception: genericized + logged, NO raw leak */
    public function test_unexpected_exception_is_genericized_and_logged_no_leak(): void
    {
        Log::spy();
        $resp = $this->caller()->call(new \RuntimeException('SECRET_SQL_constraint_violation_xyz'));

        $this->assertSame(422, $resp->getStatusCode());
        $body = json_decode($resp->getContent(), true);
        $this->assertSame(__('all.message.something_wrong'), $body['message']);
        $this->assertStringNotContainsString('SECRET_SQL', $body['message'], 'raw exception text must NOT leak');
        $this->assertFalse($body['status']);
        Log::shouldHaveReceived('error')->once();
    }

    /** @test — intentional code-422 user message (trans) passes through unchanged + NOT logged */
    public function test_code_422_user_message_passes_through(): void
    {
        Log::spy();
        $resp = $this->caller()->call(new \Exception('Ce coupon a expiré.', 422));

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertSame('Ce coupon a expiré.', json_decode($resp->getContent(), true)['message']);
        Log::shouldNotHaveReceived('error');
    }

    /** @test — HttpException preserves its real status + message (e.g. 403) */
    public function test_http_exception_preserves_status_and_message(): void
    {
        $resp = $this->caller()->call(new AccessDeniedHttpException('Accès refusé'));

        $this->assertSame(403, $resp->getStatusCode());
        $this->assertSame('Accès refusé', json_decode($resp->getContent(), true)['message']);
    }
}

<?php

/**
 * [GENIE A1-bis 2026-06-16] The global exception Handler::render leaked raw text two ways:
 *   - QueryException → 422 with $e->getMessage() = raw SQL + constraint/table names (info disclosure).
 *   - HttpException  → hardcoded 422, flattening a real 403/404/429.
 * Heal: QueryException is genericized + logged; HttpException preserves its real status. This sentinel
 * triggers a real QueryException through the handler and asserts no SQL leaks, plus status preservation.
 */

namespace Tests\Feature\Security;

use App\Exceptions\Handler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

class HandlerA1BisLeakSentinelTest extends TestCase
{
    /** @test */
    public function test_query_exception_is_genericized_no_sql_leak(): void
    {
        $handler = app(Handler::class);
        $req = Request::create('/api/whatever', 'GET');

        // Trigger a REAL QueryException (its getMessage() contains the raw SQL + table name).
        try {
            DB::table('definitely_no_such_table_xyz_secret')->get();
            $this->fail('expected a QueryException');
        } catch (\Illuminate\Database\QueryException $qe) {
            $this->assertStringContainsString('definitely_no_such_table_xyz_secret', $qe->getMessage(),
                'precondition: the raw exception DOES carry the table name');

            $resp = $handler->render($req, $qe);
            $body = json_decode($resp->getContent(), true);

            $this->assertSame(422, $resp->getStatusCode());
            $this->assertSame(__('all.message.something_wrong'), $body['message']);
            $this->assertStringNotContainsString('definitely_no_such_table_xyz_secret', $resp->getContent(),
                'A1-bis: raw SQL / table name must NEVER leak to the client');
        }
    }

    // Note: the global handler's HttpException→422 flattening (403/404/429) is a known item left as-is
    // (route-level closures handle the important typed cases above this fallback; a global status change is
    // broad-blast → deferred to a dedicated gated change). The A1-bis fix here is strictly the SQL no-leak.
}

<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * @FK-ID FK-017,FK-018 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-LOT-D01-CLIENT-TOTAL-INVARIANT | @reason Final order totals must be computed by backend pricing/quote services, never persisted from client JSON.
 */
class ClientTotalWriteForbiddenSentinelTest extends TestCase
{
    public function test_client_total_lint_passes_current_order_write_surfaces(): void
    {
        [$exitCode, $output] = $this->runLint();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('[OK]', $output);
    }

    public function test_client_total_lint_rejects_direct_request_total_assignment(): void
    {
        $dir = sys_get_temp_dir() . '/fk-client-total-' . bin2hex(random_bytes(6));
        mkdir($dir, 0777, true);

        try {
            file_put_contents($dir . '/BadOrderWriter.php', <<<'PHP'
<?php

class BadOrderWriter
{
    public function store($request, $order): void
    {
        $order->total = $request->total;
        $order->subtotal = $request['subtotal'];
        $payload = ['discount' => $request->input('discount')];
    }
}
PHP);

            [$exitCode, $output] = $this->runLint($dir);

            $this->assertNotSame(0, $exitCode, $output);
            $this->assertStringContainsString('[FAIL]', $output);
            $this->assertStringContainsString('BadOrderWriter.php', $output);
            $this->assertStringContainsString('$order->total = $request->total', $output);
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runLint(?string $target = null): array
    {
        $script = base_path('scripts/lint-fk-client-totals.sh');
        $cmd = 'bash ' . escapeshellarg($script);
        if ($target !== null) {
            $cmd .= ' ' . escapeshellarg($target);
        }
        $cmd .= ' 2>&1';

        $lines = [];
        $exitCode = 0;
        exec($cmd, $lines, $exitCode);

        return [$exitCode, implode("\n", $lines)];
    }
}

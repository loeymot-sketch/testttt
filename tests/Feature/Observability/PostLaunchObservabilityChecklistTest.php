<?php

namespace Tests\Feature\Observability;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class PostLaunchObservabilityChecklistTest extends TestCase
{
    public function test_observability_doc_and_runbook_cover_required_kpis_rules_and_cadence(): void
    {
        $doc = base_path('docs/observability/CAISSE_V1_POST_LAUNCH_OBSERVABILITY_2026-04-25.md');
        $runbook = base_path('reports/runbooks/RUNBOOK_POST_LAUNCH_OBSERVABILITY_2026-04-25.md');

        $this->assertFileExists($doc);
        $this->assertFileExists($runbook);

        $docBody = file_get_contents($doc);
        $runbookBody = file_get_contents($runbook);

        foreach ([
            'pos_lcp_p95_ms',
            'kiosk_lcp_p95_ms',
            'kds_lcp_p95_ms',
            'payment_confirm_without_ability',
            'branch_crossover',
            'noop_double_trigger',
            'fiscal_z_mismatch',
            'invalid_seal',
            'kds_error_rate',
            'canary_payment_success_rate',
            'J+1',
            'J+7',
            'J+30',
        ] as $requiredToken) {
            $this->assertStringContainsString($requiredToken, $docBody, "$requiredToken missing from observability doc.");
            $this->assertStringContainsString($requiredToken, $runbookBody, "$requiredToken missing from runbook.");
        }

        $this->assertStringContainsString('exact `branch_id = ?`', $docBody);
        $this->assertStringContainsString('mode=read-only', $runbookBody);
    }

    public function test_checker_help_and_default_fail_closed(): void
    {
        $script = base_path('scripts/post-launch-observability-check.sh');

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'post-launch-observability-check.sh must be executable.');

        $help = $this->process([$script, '--help']);
        $this->assertTrue($help->isSuccessful(), $help->getErrorOutput());
        $this->assertStringContainsString('--m14-preflight-report', $help->getOutput());
        $this->assertStringContainsString('--lcp-evidence', $help->getOutput());
        $this->assertStringContainsString('--anomaly-evidence', $help->getOutput());

        $default = $this->process([$script]);
        $this->assertFalse($default->isSuccessful(), 'Post-launch checker must fail closed without evidence.');
        $this->assertStringContainsString('m14-preflight-report', $default->getOutput());
        $this->assertStringContainsString('lcp-evidence', $default->getOutput());
        $this->assertStringContainsString('anomaly-evidence', $default->getOutput());
    }

    public function test_checker_passes_with_complete_green_evidence_packet(): void
    {
        $script = base_path('scripts/post-launch-observability-check.sh');
        $m14 = tempnam(sys_get_temp_dir(), 'fk-m14-');
        $m15 = tempnam(sys_get_temp_dir(), 'fk-m15-');
        $lcp = tempnam(sys_get_temp_dir(), 'fk-lcp-');
        $anomalies = tempnam(sys_get_temp_dir(), 'fk-anomalies-');
        $cadence = tempnam(sys_get_temp_dir(), 'fk-cadence-');

        file_put_contents($m14, "summary: failures=0 warnings=0\n");
        file_put_contents($m15, "summary: failures=0\npayment_success_rate=99.1\n");
        file_put_contents($lcp, implode("\n", [
            'pos_lcp_p95_ms=1400',
            'kiosk_lcp_p95_ms=1600',
            'kds_lcp_p95_ms=1300',
            'pos_tti_p95_ms=2800',
            'kiosk_payment_wait_p95_ms=2200',
            'kds_release_latency_p95_ms=900',
        ]));
        file_put_contents($anomalies, implode("\n", [
            'payment_confirm_without_ability=0',
            'branch_crossover=0',
            'noop_double_trigger=0',
            'fiscal_z_mismatch=0',
            'invalid_seal=0',
            'kds_error_rate=1.2',
            'canary_payment_success_rate=99.1',
        ]));
        file_put_contents($cadence, "J+1 owner ops\nJ+7 owner tech\nJ+30 post-mortem\n");

        $process = $this->process([
            $script,
            '--m14-preflight-report='.$m14,
            '--m15-canary-report='.$m15,
            '--lcp-evidence='.$lcp,
            '--anomaly-evidence='.$anomalies,
            '--cadence-evidence='.$cadence,
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('mode=read-only', $process->getOutput());
        $this->assertStringContainsString('branch_crossover', $process->getOutput());
        $this->assertStringContainsString('summary: failures=0', $process->getOutput());
    }

    public function test_checker_blocks_p0_anomaly_evidence(): void
    {
        $script = base_path('scripts/post-launch-observability-check.sh');
        $m14 = tempnam(sys_get_temp_dir(), 'fk-m14-');
        $m15 = tempnam(sys_get_temp_dir(), 'fk-m15-');
        $lcp = tempnam(sys_get_temp_dir(), 'fk-lcp-');
        $anomalies = tempnam(sys_get_temp_dir(), 'fk-anomalies-');
        $cadence = tempnam(sys_get_temp_dir(), 'fk-cadence-');

        file_put_contents($m14, "summary: failures=0 warnings=0\n");
        file_put_contents($m15, "summary: failures=0\npayment_success_rate=99.1\n");
        file_put_contents($lcp, "pos_lcp_p95_ms=1400\nkiosk_lcp_p95_ms=1600\nkds_lcp_p95_ms=1300\n");
        file_put_contents($anomalies, implode("\n", [
            'payment_confirm_without_ability=0',
            'branch_crossover=1',
            'noop_double_trigger=0',
            'fiscal_z_mismatch=0',
            'invalid_seal=0',
            'kds_error_rate=1.2',
            'canary_payment_success_rate=99.1',
        ]));
        file_put_contents($cadence, "J+1 done\nJ+7 planned\nJ+30 planned\n");

        $process = $this->process([
            $script,
            '--m14-preflight-report='.$m14,
            '--m15-canary-report='.$m15,
            '--lcp-evidence='.$lcp,
            '--anomaly-evidence='.$anomalies,
            '--cadence-evidence='.$cadence,
        ]);

        $this->assertFalse($process->isSuccessful(), 'P0 branch crossover anomaly must block readiness.');
        $this->assertStringContainsString('branch_crossover', $process->getOutput());
        $this->assertStringContainsString('summary: failures=1', $process->getOutput());
    }

    private function process(array $command): Process
    {
        $process = new Process($command, base_path());
        $process->setTimeout(60);
        $process->run();

        return $process;
    }
}

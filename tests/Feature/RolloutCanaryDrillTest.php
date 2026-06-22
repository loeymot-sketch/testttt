<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class RolloutCanaryDrillTest extends TestCase
{
    public function test_rollout_config_declares_flags_phases_and_predicates(): void
    {
        $config = require base_path('config/caisse_v1_rollout.php');

        $this->assertSame([
            'payment_ledger_v1',
            'pos_revenue_guards',
            'kds_strict_release',
            'quote_v1',
            'fiscal_z_v1',
            'kiosk_offline_strict',
        ], array_keys($config['flags']));

        $this->assertSame(['pilot_branch', 'ten_percent', 'fifty_percent', 'full'], array_column($config['canary']['phases'], 'name'));
        $this->assertSame(95.0, $config['canary']['rollback_predicates']['payment_success_rate']['threshold']);
        $this->assertSame(0, $config['canary']['rollback_predicates']['fiscal_anomaly']['threshold']);
        $this->assertSame(5.0, $config['canary']['rollback_predicates']['kds_error_rate']['threshold']);
    }

    public function test_rollout_drill_help_and_default_fail_closed(): void
    {
        $script = base_path('scripts/rollout-canary-drill.sh');

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'rollout-canary-drill.sh must be executable.');

        $help = $this->process([$script, '--help']);
        $this->assertTrue($help->isSuccessful(), $help->getErrorOutput());
        $this->assertStringContainsString('--preflight-report', $help->getOutput());
        $this->assertStringContainsString('--metrics', $help->getOutput());

        $default = $this->process([$script]);
        $this->assertFalse($default->isSuccessful(), 'Rollout drill must fail closed without evidence.');
        $this->assertStringContainsString('preflight-report', $default->getOutput());
        $this->assertStringContainsString('metrics', $default->getOutput());
    }

    public function test_rollout_drill_passes_with_green_preflight_and_metrics(): void
    {
        $script = base_path('scripts/rollout-canary-drill.sh');
        $preflight = tempnam(sys_get_temp_dir(), 'fk-preflight-');
        $metrics = tempnam(sys_get_temp_dir(), 'fk-metrics-');

        file_put_contents($preflight, "summary: failures=0 warnings=0\n");
        file_put_contents($metrics, implode("\n", [
            'payment_success_rate=99.1',
            'fiscal_anomaly=0',
            'kds_error_rate=1.5',
        ]));

        $process = $this->process([
            $script,
            '--preflight-report='.$preflight,
            '--branch-id=12',
            '--metrics='.$metrics,
            '--phase=pilot_branch',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('summary: failures=0', $process->getOutput());
        $this->assertStringContainsString('exact branch_id=12', $process->getOutput());
    }

    public function test_rollout_drill_blocks_on_rollback_predicate_breach(): void
    {
        $script = base_path('scripts/rollout-canary-drill.sh');
        $preflight = tempnam(sys_get_temp_dir(), 'fk-preflight-');
        $metrics = tempnam(sys_get_temp_dir(), 'fk-metrics-');

        file_put_contents($preflight, "summary: failures=0 warnings=0\n");
        file_put_contents($metrics, implode("\n", [
            'payment_success_rate=94.9',
            'fiscal_anomaly=0',
            'kds_error_rate=1.5',
        ]));

        $process = $this->process([
            $script,
            '--preflight-report='.$preflight,
            '--branch-id=12',
            '--metrics='.$metrics,
        ]);

        $this->assertFalse($process->isSuccessful(), 'Rollback predicate breach must block rollout.');
        $this->assertStringContainsString('payment_success_rate', $process->getOutput());
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

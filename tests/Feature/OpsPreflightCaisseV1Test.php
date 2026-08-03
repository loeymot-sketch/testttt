<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class OpsPreflightCaisseV1Test extends TestCase
{
    public function test_ops_preflight_help_and_default_fail_closed(): void
    {
        $script = base_path('scripts/ops-preflight-caisse-v1.sh');

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'ops-preflight-caisse-v1.sh must be executable.');

        $help = $this->process([$script, '--help']);
        $this->assertTrue($help->isSuccessful(), $help->getErrorOutput());
        $this->assertStringContainsString('--staging-rehearsal-transcript', $help->getOutput());
        $this->assertStringContainsString('--branch-leak-evidence', $help->getOutput());

        $default = $this->process([$script]);
        $this->assertFalse($default->isSuccessful(), 'M14 preflight must fail closed without staging evidence.');
        $this->assertStringContainsString('staging-rehearsal-transcript', $default->getOutput());
        $this->assertStringContainsString('branch-leak-evidence', $default->getOutput());
    }

    public function test_ops_preflight_passes_with_explicit_rehearsal_and_branch_evidence(): void
    {
        $script = base_path('scripts/ops-preflight-caisse-v1.sh');
        $rehearsal = tempnam(sys_get_temp_dir(), 'fk-rehearsal-');
        $branchEvidence = tempnam(sys_get_temp_dir(), 'fk-branch-');

        file_put_contents($rehearsal, implode("\n", [
            'bash scripts/db/dry-run.sh --env=staging --run --connection=sqlite',
            'php artisan migrate --force --database=sqlite',
            'php artisan migrate:rollback --step=1 --force --database=sqlite',
            'php artisan migrate --force --database=sqlite',
        ]));

        file_put_contents($branchEvidence, 'select count(*) from orders where branch_id = 123; exact branch_id check PASS');

        $process = $this->process([
            $script,
            '--staging-rehearsal-transcript='.$rehearsal,
            '--branch-leak-evidence='.$branchEvidence,
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('summary: failures=0', $process->getOutput());
    }

    public function test_preflight_production_help_is_registered(): void
    {
        $process = $this->process(['php', 'artisan', 'app:preflight-production', '--help']);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('--strict', $process->getOutput());
    }

    private function process(array $command): Process
    {
        $process = new Process($command, base_path());
        $process->setTimeout(60);
        $process->run();

        return $process;
    }
}

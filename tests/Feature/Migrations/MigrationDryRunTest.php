<?php

namespace Tests\Feature\Migrations;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class MigrationDryRunTest extends TestCase
{
    public function test_dry_run_help_and_default_fail_closed(): void
    {
        $script = base_path('scripts/db/dry-run.sh');

        $this->assertFileExists($script);
        $this->assertTrue(is_executable($script), 'dry-run.sh must be executable.');

        $help = $this->process([$script, '--help']);
        $this->assertTrue($help->isSuccessful(), $help->getErrorOutput());
        $this->assertStringContainsString('migrate --pretend --force', $help->getOutput());

        $default = $this->process([$script]);
        $this->assertFalse($default->isSuccessful(), 'dry-run.sh must fail closed without --run.');
        $this->assertStringContainsString('Refusing dry-run without --env', $default->getErrorOutput());
    }

    public function test_dry_run_print_command_uses_pretend_and_never_fresh_or_rollback(): void
    {
        $script = base_path('scripts/db/dry-run.sh');

        $process = $this->process([
            $script,
            '--env=staging',
            '--connection=sqlite',
            '--path=database/migrations/2026_04_25_190000_create_order_quotes_table.php',
            '--print-command',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $output = $process->getOutput();
        $this->assertStringContainsString('APP_ENV=staging', $output);
        $this->assertStringContainsString('migrate', $output);
        $this->assertStringContainsString('--pretend', $output);
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('--database=sqlite', $output);
        $this->assertStringContainsString('--path=database/migrations/2026_04_25_190000_create_order_quotes_table.php', $output);
        $this->assertStringNotContainsString('migrate:fresh', $output);
        $this->assertStringNotContainsString('migrate:rollback', $output);
    }

    private function process(array $command): Process
    {
        $process = new Process($command, base_path());
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}

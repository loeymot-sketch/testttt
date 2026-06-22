<?php

namespace Tests\Feature\Migrations;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class MigrationRollbackTest extends TestCase
{
    public function test_backup_and_rehearsal_help_are_available_and_default_fail_closed(): void
    {
        $backup = base_path('scripts/db/backup.sh');
        $rehearsal = base_path('scripts/db/rehearsal.sh');

        $this->assertFileExists($backup);
        $this->assertFileExists($rehearsal);
        $this->assertTrue(is_executable($backup), 'backup.sh must be executable.');
        $this->assertTrue(is_executable($rehearsal), 'rehearsal.sh must be executable.');

        $backupHelp = $this->process([$backup, '--help']);
        $this->assertTrue($backupHelp->isSuccessful(), $backupHelp->getErrorOutput());
        $this->assertStringContainsString('--i-understand-backup', $backupHelp->getOutput());

        $rehearsalHelp = $this->process([$rehearsal, '--help']);
        $this->assertTrue($rehearsalHelp->isSuccessful(), $rehearsalHelp->getErrorOutput());
        $this->assertStringContainsString('migrate:rollback', $rehearsalHelp->getOutput());

        $backupDefault = $this->process([$backup]);
        $this->assertFalse($backupDefault->isSuccessful(), 'backup.sh must fail closed without explicit env and confirmation.');
        $this->assertStringContainsString('Refusing backup without --env', $backupDefault->getErrorOutput());

        $rehearsalDefault = $this->process([$rehearsal]);
        $this->assertFalse($rehearsalDefault->isSuccessful(), 'rehearsal.sh must fail closed without explicit env and manifest.');
        $this->assertStringContainsString('Refusing rehearsal without --env', $rehearsalDefault->getErrorOutput());
    }

    public function test_sqlite_backup_creates_manifest_with_hash(): void
    {
        $script = base_path('scripts/db/backup.sh');
        $source = tempnam(sys_get_temp_dir(), 'fk-sqlite-');
        $outputDir = sys_get_temp_dir().'/fk-migration-backup-'.bin2hex(random_bytes(4));

        file_put_contents($source, 'sqlite backup fixture');
        mkdir($outputDir, 0777, true);

        $process = $this->process([
            $script,
            '--env=staging',
            '--driver=sqlite',
            '--database='.$source,
            '--output-dir='.$outputDir,
            '--i-understand-backup',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $manifestPath = trim($process->getOutput());
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertSame('staging', $manifest['env']);
        $this->assertSame('sqlite', $manifest['driver']);
        $this->assertSame($source, $manifest['source']);
        $this->assertFileExists($manifest['backup_file']);
        $this->assertSame(hash_file('sha256', $manifest['backup_file']), $manifest['sha256']);
        $this->assertTrue($manifest['restore_required_before_rollback']);
    }

    public function test_rehearsal_print_command_documents_up_down_up_and_never_fresh(): void
    {
        $script = base_path('scripts/db/rehearsal.sh');
        $manifest = tempnam(sys_get_temp_dir(), 'fk-manifest-');
        file_put_contents($manifest, '{"env":"staging","driver":"sqlite"}');

        $process = $this->process([
            $script,
            '--env=staging',
            '--connection=sqlite',
            '--backup-manifest='.$manifest,
            '--step=1',
            '--print-command',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $output = $process->getOutput();
        $this->assertStringContainsString('backup manifest:', $output);
        $this->assertStringContainsString('dry-run.sh', $output);
        $this->assertStringContainsString('migrate --force --database=sqlite', $output);
        $this->assertStringContainsString('migrate:rollback --step=1 --force --database=sqlite', $output);
        $this->assertStringNotContainsString('migrate:fresh', $output);
    }

    private function process(array $command): Process
    {
        $process = new Process($command, base_path());
        $process->setTimeout(30);
        $process->run();

        return $process;
    }
}

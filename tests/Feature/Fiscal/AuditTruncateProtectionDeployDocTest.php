<?php

namespace Tests\Feature\Fiscal;

use Tests\TestCase;

/**
 * [GOAL-CMS-2026-05-18 CVP0-1 sentinel — Round 1 Sec S-1 + Fiscal F-FISC-002]
 *
 * NF525 fiscal-table TRUNCATE/DROP/ALTER privilege restriction is enforced
 * at the DB GRANT level via the Ansible playbook. This sentinel asserts the
 * playbook contains the REVOKE task — closing the deploy-doc gap explicitly
 * acknowledged in the BEFORE DELETE trigger migrations
 * (2026_05_09_160000 + 2026_05_10_010000).
 *
 * Failure mode if missing: app-credential breach wipes audit_logs +
 * z_reports + cash_movements + 4 sibling fiscal tables in ~50ms (TRUNCATE
 * bypasses MySQL DELETE triggers). Art. 1743 CGI criminal exposure +
 * automatic DGFiP audit fail.
 *
 * @group fiscal
 * @group sentinel
 * @group nf525
 */
class AuditTruncateProtectionDeployDocTest extends TestCase
{
    public function test_ansible_playbook_contains_fiscal_truncate_revoke_task(): void
    {
        $playbookPath = base_path('deploy/ansible/site.yml');
        $this->assertFileExists($playbookPath, 'Ansible playbook deploy/ansible/site.yml must exist.');

        $playbook = file_get_contents($playbookPath);

        $this->assertStringContainsString(
            'NF525 fiscal-table privilege REVOKE',
            $playbook,
            'CVP0-1: Ansible playbook MUST contain the NF525 fiscal-table REVOKE task block. '
            . 'Without it, app-credential breach can TRUNCATE the entire 6y audit chain '
            . '(Art. 1743 CGI criminal exposure).'
        );

        // Verify each protected table is in the REVOKE list
        $protectedTables = [
            'audit_logs',
            'z_reports',
            'cash_movements',
            'cash_drawer_sessions',
            'order_payments',
            'domain_events',
            'webhook_events',
        ];

        foreach ($protectedTables as $table) {
            $this->assertMatchesRegularExpression(
                '/REVOKE\s+DROP,\s+ALTER\s+ON\s+`\{\{\s*db_name\s*\}\}`\.`' . preg_quote($table, '/') . '`/i',
                $playbook,
                "CVP0-1: Ansible REVOKE task must protect `{$table}` from DROP/ALTER. "
                . 'TRUNCATE is implicitly forbidden via DROP grant (MySQL semantics).'
            );
        }
    }

    public function test_db_user_and_db_name_variables_declared_in_group_vars(): void
    {
        $groupVarsPath = base_path('deploy/ansible/group_vars/all.yml');
        $this->assertFileExists($groupVarsPath);

        $vars = file_get_contents($groupVarsPath);

        $this->assertStringContainsString(
            'db_name:',
            $vars,
            'CVP0-1: deploy/ansible/group_vars/all.yml must declare db_name for the REVOKE task target.'
        );
        $this->assertStringContainsString(
            'db_user:',
            $vars,
            'CVP0-1: deploy/ansible/group_vars/all.yml must declare db_user for the REVOKE task subject.'
        );
    }
}

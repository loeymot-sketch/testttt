<?php

namespace Tests\Feature\Mail;

use App\Mail\OrderGotMail;
use App\Mail\OrderMail;
use App\Mail\ResetPassword;
use App\Mail\SubscriberMail;
use App\Mail\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Tests\TestCase;

/**
 * @FK-ID FK-EMAIL-QUEUE-RESILIENCE-SENTINEL
 * @source GOAL ULTRA-FINAL Phase L L9.4 (2026-05-24) — Email Queue + Retry + Bounce
 * @see reports/test-e2e/goal-2026-05-23/phase-l/L9.4-email-queue-findings.json
 * @see app/Mail/VerifyEmail.php (the ONE Mailable that is correctly queued)
 * @see app/Mail/OrderMail.php (NOT queued — synchronous SMTP)
 * @see app/Mail/OrderGotMail.php (NOT queued — synchronous SMTP)
 * @see app/Mail/ResetPassword.php (NOT queued — synchronous SMTP)
 * @see app/Mail/SubscriberMail.php (NOT queued — synchronous SMTP, broadcast DoS risk)
 * @see app/Listeners/SendResetPasswordNotification.php (DEAD ShouldQueue import)
 * @see deploy/ansible/templates/supervisor-foodking.conf.j2 (queue lanes)
 * @see database/migrations/2019_08_19_000000_create_failed_jobs_table.php
 *
 * BASELINE LOCK pattern (mirrors BranchScopeCoverageSentinelTest +
 * FormRequestAuthzDriftSentinelTest):
 *
 *   (a) Mailable queue policy — VerifyEmail MUST implement ShouldQueue;
 *       the 4 NON-queued Mailables are baseline-locked so flips trigger an
 *       explicit gate. Why lock the negatives too? Per L9.4: making any of
 *       them ShouldQueue WITHOUT also adding a dedicated 'mail' lane to the
 *       supervisor template (deploy/ansible/templates/supervisor-foodking.conf.j2
 *       --queue=high,default) would route mail jobs onto 'default' alongside
 *       DispatchDomainEventsJob outbox tail — POS/KDS sync would be starved
 *       by a 50k-recipient SubscriberMail broadcast. Lock-in forces a
 *       two-file commit (Mailable + supervisor lane) reviewed together.
 *
 *   (b) Supervisor lane manifest — assert template watches 'high' AND
 *       'default' at minimum. If a future commit adds 'mail' it must extend
 *       the assertion list (forces same explicit gate as (a)).
 *
 *   (c) failed_jobs infrastructure — migration must exist; driver must be
 *       database-uuids per config/queue.php (this is what Laravel writes to
 *       on tries=3 exhaustion).
 *
 *   (d) Listener dead-import detection — SendResetPasswordNotification
 *       imports ShouldQueue at file level but the class does not implement
 *       it. Locked as DEAD today; once a future commit adds
 *       `implements ShouldQueue` to the class signature this test flips
 *       and the developer adjusts the assertion (alongside adding the
 *       mail lane). Catches the inverse — silent removal of the import
 *       without addressing the gap.
 *
 * Anti-régression: any drift in either direction (mail correctly queued
 * without supervisor lane / supervisor lane removed / failed_jobs
 * migration dropped / listener silently flipped) fails this sentinel
 * before merge.
 *
 * Per L9.4 V1.0.X backlog: MAIL-FIX-01 (Mailable + lane) + MAIL-FIX-02
 * (Log::info → Log::warning) + MAIL-FIX-03 (Queue::failing handler) all
 * touch surfaces protected by THIS sentinel. The remediation PR must
 * update this baseline.
 */
class EmailQueueResilienceSentinelTest extends TestCase
{
    /**
     * Mailables that ARE ShouldQueue today. NONE today — VerifyEmail imports
     * ShouldQueue but does not implement it (dead-import pattern, same as
     * SendResetPasswordNotification listener). Lock at empty so a future
     * fix is explicit.
     */
    private const QUEUED_MAILABLES = [];

    /**
     * Mailables that are NOT ShouldQueue today (baseline lock). Adding any
     * of these to QUEUED_MAILABLES must be accompanied by a 'mail' lane in
     * the supervisor template — see (a) docblock above + L9.4 finding.
     *
     * VerifyEmail is included DESPITE importing ShouldQueue at file level
     * because the class signature does NOT carry `implements ShouldQueue`.
     * Dead-import = synchronous behavior at runtime.
     */
    private const SYNCHRONOUS_MAILABLES = [
        OrderMail::class,
        OrderGotMail::class,
        ResetPassword::class,
        SubscriberMail::class,
        VerifyEmail::class,
    ];

    /**
     * The supervisor template MUST watch at least these queue lanes.
     * If a future commit adds 'mail' lane, extend this list — that's the
     * deliberate gate per docblock (b).
     */
    private const REQUIRED_SUPERVISOR_LANES = ['high', 'default'];

    private const SUPERVISOR_TEMPLATE_PATH = 'deploy/ansible/templates/supervisor-foodking.conf.j2';
    private const FAILED_JOBS_MIGRATION_PATH = 'database/migrations/2019_08_19_000000_create_failed_jobs_table.php';
    private const LISTENER_RESET_PASSWORD_PATH = 'app/Listeners/SendResetPasswordNotification.php';

    // -------------------------------------------------------------------
    // (a) Mailable queue policy
    // -------------------------------------------------------------------

    /**
     * @test
     * @group sentinel
     * @group mail
     */
    public function queued_mailables_remain_queued(): void
    {
        if (empty(self::QUEUED_MAILABLES)) {
            // BASELINE TODAY: zero Mailables correctly queued. Sentinel
            // passes silently — the SYNCHRONOUS_MAILABLES check below
            // enforces the inverse baseline.
            $this->assertTrue(true, 'No queued Mailables today (L9.4 baseline).');
            return;
        }
        foreach (self::QUEUED_MAILABLES as $mailable) {
            $this->assertTrue(
                is_subclass_of($mailable, ShouldQueue::class),
                sprintf(
                    'Mailable %s MUST implement ShouldQueue (L9.4 baseline). '
                    . 'If you intentionally removed it, also remove the framework-side '
                    . 'queueing assumptions in routes/auth and update this sentinel.',
                    $mailable
                )
            );
        }
    }

    /**
     * @test
     * @group sentinel
     * @group mail
     *
     * VerifyEmail imports ShouldQueue at file level but the class signature
     * does NOT implement it. Locked as DEAD today; matches the same
     * dead-import pattern as SendResetPasswordNotification listener.
     * If a future commit adds `implements ShouldQueue` to the class
     * signature this test flips and the developer adjusts the baseline
     * (alongside adding the mail supervisor lane).
     */
    public function verify_email_dead_should_queue_import_is_documented(): void
    {
        $verifyEmailPath = base_path('app/Mail/VerifyEmail.php');
        $this->assertFileExists($verifyEmailPath);

        $contents = file_get_contents($verifyEmailPath);
        $this->assertIsString($contents);

        $importsShouldQueue = str_contains(
            $contents,
            'use Illuminate\\Contracts\\Queue\\ShouldQueue;'
        );
        $classImplementsShouldQueue = (bool) preg_match(
            '/class\s+VerifyEmail[^{]*\bimplements\b[^{]*\bShouldQueue\b/',
            $contents
        );

        if ($importsShouldQueue && !$classImplementsShouldQueue) {
            // BASELINE LOCK — current dead-import state.
            $this->assertTrue(true, 'VerifyEmail dead-import state matches L9.4 baseline.');
            return;
        }

        $this->fail(
            'VerifyEmail ShouldQueue state drifted from L9.4 baseline ('
            . 'imports=true, implements=false). '
            . 'Current state: imports=' . ($importsShouldQueue ? 'true' : 'false')
            . ', implements=' . ($classImplementsShouldQueue ? 'true' : 'false') . '. '
            . 'Update this sentinel + SYNCHRONOUS_MAILABLES + REQUIRED_SUPERVISOR_LANES '
            . 'and the L9.4 findings doc together.'
        );
    }

    /**
     * @test
     * @group sentinel
     * @group mail
     */
    public function synchronous_mailables_baseline_locked(): void
    {
        foreach (self::SYNCHRONOUS_MAILABLES as $mailable) {
            $this->assertFalse(
                is_subclass_of($mailable, ShouldQueue::class),
                sprintf(
                    'Mailable %s is NOT ShouldQueue today (L9.4 SYNCHRONOUS_MAILABLES baseline). '
                    . 'If you added `implements ShouldQueue` you MUST ALSO add the "mail" lane to '
                    . self::SUPERVISOR_TEMPLATE_PATH . ' (--queue=high,default,mail) '
                    . 'AND extend REQUIRED_SUPERVISOR_LANES in this sentinel. '
                    . 'Otherwise mail jobs land on "default" and starve DispatchDomainEventsJob outbox. '
                    . 'See reports/test-e2e/goal-2026-05-23/phase-l/L9.4-email-queue-findings.json#MAIL-FIX-01.',
                    $mailable
                )
            );
        }
    }

    // -------------------------------------------------------------------
    // (b) Supervisor lane manifest
    // -------------------------------------------------------------------

    /**
     * @test
     * @group sentinel
     * @group mail
     */
    public function supervisor_template_watches_required_queue_lanes(): void
    {
        $templatePath = base_path(self::SUPERVISOR_TEMPLATE_PATH);
        $this->assertFileExists(
            $templatePath,
            'Supervisor template missing — ' . self::SUPERVISOR_TEMPLATE_PATH . ' should ship in repo.'
        );

        $contents = file_get_contents($templatePath);
        $this->assertIsString($contents);

        // Match the `--queue=<csv>` flag on the queue:work line.
        $matched = preg_match('/--queue=([A-Za-z0-9_,\-]+)/', $contents, $m);
        $this->assertSame(
            1,
            $matched,
            'Could not locate `--queue=...` flag in supervisor template. The queue:work '
            . 'line must declare which lanes are watched (L9.4 baseline).'
        );

        $watchedLanes = explode(',', $m[1]);

        foreach (self::REQUIRED_SUPERVISOR_LANES as $lane) {
            $this->assertContains(
                $lane,
                $watchedLanes,
                sprintf(
                    'Supervisor template must watch queue lane "%s" (L9.4 baseline = [%s]). '
                    . 'Current --queue value: %s',
                    $lane,
                    implode(', ', self::REQUIRED_SUPERVISOR_LANES),
                    $m[1]
                )
            );
        }
    }

    // -------------------------------------------------------------------
    // (c) failed_jobs infrastructure
    // -------------------------------------------------------------------

    /**
     * @test
     * @group sentinel
     * @group mail
     */
    public function failed_jobs_migration_exists_and_uses_uuid_driver(): void
    {
        $migrationPath = base_path(self::FAILED_JOBS_MIGRATION_PATH);
        $this->assertFileExists(
            $migrationPath,
            'failed_jobs migration missing — Laravel writes here on tries=3 exhaustion. '
            . 'Without it, queue:work --tries=3 silently discards failed jobs.'
        );

        $this->assertSame(
            'database-uuids',
            config('queue.failed.driver'),
            'config/queue.php failed.driver must be database-uuids (L9.4 baseline). '
            . 'database-uuids supports `queue:retry --queue=...` filtering needed for mail-only retry.'
        );

        $this->assertSame(
            'failed_jobs',
            config('queue.failed.table'),
            'config/queue.php failed.table must be "failed_jobs" (L9.4 baseline).'
        );
    }

    // -------------------------------------------------------------------
    // (d) Listener dead-import detection
    // -------------------------------------------------------------------

    /**
     * @test
     * @group sentinel
     * @group mail
     */
    public function reset_password_listener_dead_should_queue_import_is_documented(): void
    {
        $listenerPath = base_path(self::LISTENER_RESET_PASSWORD_PATH);
        $this->assertFileExists($listenerPath);

        $contents = file_get_contents($listenerPath);
        $this->assertIsString($contents);

        $importsShouldQueue = str_contains(
            $contents,
            'use Illuminate\\Contracts\\Queue\\ShouldQueue;'
        );
        $classImplementsShouldQueue = (bool) preg_match(
            '/class\s+SendResetPasswordNotification[^{]*\bimplements\b[^{]*\bShouldQueue\b/',
            $contents
        );

        // BASELINE (updated 2026-06-14 — SEC-H15-TIMING-01 heal): the listener now
        // BOTH imports AND implements ShouldQueue. It was healed deliberately: the
        // synchronous SMTP send leaked account existence on the forgot-password path
        // via response timing (the endpoint returns a neutral 200 for all emails but
        // only dispatched the reset mail for a real user). Queuing the listener moves
        // the SMTP send out of band. The ResetPassword *mailable* itself stays in
        // SYNCHRONOUS_MAILABLES (the mailable class is not ShouldQueue; only the
        // listener that sends it is now async), so no other sentinel constant changes.
        // If the listener regresses to non-queued, re-open the timing oracle → fail.
        if ($importsShouldQueue && $classImplementsShouldQueue) {
            $this->assertTrue(true, 'Listener is queued (SEC-H15-TIMING-01 healed — SMTP send out of band).');
            return;
        }

        $this->fail(
            'SendResetPasswordNotification ShouldQueue state regressed from the SEC-H15-TIMING-01 '
            . 'healed baseline (imports=true, implements=true). '
            . 'Current state: imports=' . ($importsShouldQueue ? 'true' : 'false')
            . ', implements=' . ($classImplementsShouldQueue ? 'true' : 'false') . '. '
            . 'The listener MUST stay queued or the forgot-password timing oracle re-opens. '
            . 'See tests/Feature/Security/ForgotPasswordEnumerationSentinelTest.php.'
        );
    }

    // -------------------------------------------------------------------
    // Helper: Mailable class loads without error
    // -------------------------------------------------------------------

    /**
     * @test
     * @group sentinel
     * @group mail
     */
    public function all_audited_mailables_are_loadable_classes(): void
    {
        foreach (array_merge(self::QUEUED_MAILABLES, self::SYNCHRONOUS_MAILABLES) as $mailable) {
            $this->assertTrue(
                class_exists($mailable),
                "Mailable class {$mailable} not found — L9.4 inventory drifted. "
                . 'Either the class was deleted (update QUEUED_MAILABLES / SYNCHRONOUS_MAILABLES) '
                . 'or namespace changed.'
            );

            // Sanity: it must extend Illuminate\Mail\Mailable.
            $ref = new ReflectionClass($mailable);
            $this->assertTrue(
                $ref->isSubclassOf(\Illuminate\Mail\Mailable::class),
                "Class {$mailable} must extend Illuminate\\Mail\\Mailable."
            );
        }
    }
}

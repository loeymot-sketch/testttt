<?php

namespace Tests\Feature\Admin;

use App\Models\Subscriber;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] NC-06 re-verify (originally
 * flagged plans/GOAL_MGMT_TESTPLAN_2026-06-01_APPENDIX_full-map.md:546-548).
 *
 * SubscriberMail::build() hardcoded ->subject('Subscriber Notification')
 * regardless of the admin-entered subject — the real subject only appeared
 * as body text ("Subject : {{ $title }}"). Violates ADR-007 (FR-only,
 * user-facing English is forbidden) and silently discards what the admin
 * typed from the actual email header recipients see.
 */
class SubscriberMailSubjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_entered_subject_becomes_the_real_mail_subject_header(): void
    {
        Mail::fake();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        foreach (['subscribers'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'sanctum']);
        }
        $adminRole = \Spatie\Permission\Models\Role::where('name', 'Admin')->where('guard_name', 'sanctum')->first();
        $adminRole->givePermissionTo(['subscribers']);

        $admin = UserFactory::new()->create([]);
        $admin->assignRole('Admin');

        Subscriber::query()->create(['email' => 'client@example.test']);

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/subscriber/send-email', [
                'subject' => 'Menu de Noël disponible',
                'message' => 'Découvrez notre carte des fêtes.',
            ]);

        $resp->assertOk();

        Mail::assertSent(\App\Mail\SubscriberMail::class, function ($mail) {
            // Pre-fix: subject is always the hardcoded English string,
            // never the admin's real entered subject.
            $mail->build();
            return $mail->subject === 'Menu de Noël disponible';
        });
    }
}

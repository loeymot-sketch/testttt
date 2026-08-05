<?php

namespace Tests\Feature\Frontend;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [A1 cycle 5 · GOAL_WEB_ADVERSARIAL_UX_TOTAL 2026-08-05 · P1 SÉCURITÉ]
 *
 * Se déconnecter est un CONTRÔLE DE SÉCURITÉ : il doit fonctionner inconditionnellement pour
 * quiconque présente un jeton valide.
 *
 * Défaut prouvé : `POST /api/auth/logout` était gardé par `verify.api` (e-mail vérifié) alors
 * que `POST /api/auth/login` ne l'est PAS. On pouvait donc se connecter sans jamais pouvoir se
 * déconnecter — 401 « Please verify your email », le jeton CONSERVÉ, et le front avalant
 * l'échec en silence. Mesuré en base au moment du constat : 58 jetons vivants appartenaient à
 * des comptes non vérifiés. Refuser la révocation d'une session, c'est refuser au client le
 * seul moyen de reprendre la main sur son compte.
 */
class LogoutNeverBlockedByEmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** Un compte NON vérifié doit pouvoir révoquer sa session. */
    public function test_unverified_user_can_still_log_out(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $this->assertNotSame(
            401,
            $response->status(),
            "Un compte non vérifié doit pouvoir se déconnecter : refuser la révocation, c'est "
            . "laisser un jeton vivant que le client ne peut plus tuer."
        );
    }

    /** Un compte vérifié le peut évidemment aussi — le comportement historique ne bouge pas. */
    public function test_verified_user_can_log_out(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Sanctum::actingAs($user);

        $this->assertNotSame(401, $this->postJson('/api/auth/logout')->status());
    }

    /**
     * En revanche la SUPPRESSION de compte reste derrière la vérification d'e-mail : c'est une
     * action destructrice et irréversible, pas un moyen de reprendre la main sur sa session.
     */
    public function test_account_deletion_stays_behind_email_verification(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        Sanctum::actingAs($user);

        $this->assertSame(401, $this->postJson('/api/auth/delete-account')->status());
    }
}

<?php

namespace Tests\Feature\Sync;

use Tests\TestCase;

/**
 * [GOAL-CMS-2026-05-18 S-R3-P0-G sentinel — Round 3 T-3.2.2 Sec F-SEC-W6-01]
 *
 * Pusher channel-auth wildcard bypass: previously `routes/channels.php`
 * used `$user->tokenCan('kiosk:order')` as the kiosk discriminator. But
 * LoginController:113 mints staff/admin tokens with abilities=['*']
 * (wildcard) and Sanctum's PersonalAccessToken::can() returns TRUE for
 * any ability when '*' is present — so every admin/staff token also
 * satisfied tokenCan('kiosk:order'), routing them through the kiosk
 * branch where they were denied silently. R3 finding classified this
 * as observably-broken channel auth.
 *
 * Heal: switch to TOKEN NAME check (`$user->currentAccessToken()?->name
 * === 'kiosk-token'`) — un-spoofable, never wildcard-matched. Sentinel
 * asserts the bare `tokenCan('kiosk:order')` pattern is GONE from the
 * channel-auth callback.
 *
 * @group sync
 * @group pusher
 * @group sentinel
 */
class PusherChannelAuthWildcardSentinelTest extends TestCase
{
    public function test_channel_auth_uses_token_name_not_tokenCan(): void
    {
        $source = file_get_contents(base_path('routes/channels.php'));
        $this->assertNotFalse($source);

        // Positive assertion: the heal pattern is in place. Accepts either
        // an inline `currentAccessToken()?->name === 'kiosk-token'` OR a
        // two-line variant (`$x = ->name; if ($x === 'kiosk-token')`).
        $hasInline = preg_match(
            '/currentAccessToken\(\)\??->name\s*===\s*[\'"]kiosk-token[\'"]/',
            $source
        );
        $hasTwoLine = preg_match('/currentAccessToken\(\)\??->name/', $source)
            && preg_match('/===\s*[\'"]kiosk-token[\'"]/', $source);

        $this->assertTrue(
            $hasInline || $hasTwoLine,
            'S-R3-P0-G: routes/channels.php MUST use token-NAME check '
            . '($user->currentAccessToken()?->name === \'kiosk-token\') instead of '
            . 'tokenCan(\'kiosk:order\') for the kiosk branch — closes Sanctum '
            . '`[\'*\']` wildcard bypass (R3 T-3.2.2 Sec F-SEC-W6-01).'
        );

        // Negative assertion: the broken pattern is GONE from the kiosk
        // discriminator. (Note: tokenCan may still appear in OTHER contexts —
        // we only check the channel-auth callback uses the name check.)
        $this->assertDoesNotMatchRegularExpression(
            '/Broadcast::channel\([\'"]branch\.\{branchId\}[\'"].*?tokenCan\([\'"]kiosk:order[\'"]\)/s',
            $source,
            'S-R3-P0-G: routes/channels.php `branch.{branchId}` callback must NOT use '
            . 'tokenCan(\'kiosk:order\') — vulnerable to Sanctum [\'*\'] wildcard.'
        );
    }

    public function test_channel_auth_admin_branch_requires_role_check(): void
    {
        $source = file_get_contents(base_path('routes/channels.php'));

        // The admin-bypass clause must include a role check (R3 T-3.2.2 Sec
        // F-SEC-W6-02 — Guest-Echo-Bypass latent). Bare $user->branch_id===0
        // would grant cross-branch subscription to every customer/guest
        // (they're seeded with branch_id=0 by default).
        $this->assertMatchesRegularExpression(
            '/hasRole\([\'"]Admin[\'"]\)\s*\|\|\s*\$user->hasRole\([\'"]Tenant Admin[\'"]\)/',
            $source,
            'S-R3-P0-G partial / F-SEC-W6-02: routes/channels.php cross-branch '
            . 'subscription must check hasRole(\'Admin\') || hasRole(\'Tenant Admin\') — '
            . 'NOT bare branch_id===0 (which customers + guests also have).'
        );
    }
}

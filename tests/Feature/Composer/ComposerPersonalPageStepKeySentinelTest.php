<?php

namespace Tests\Feature\Composer;

use App\Http\Controllers\Admin\ComposerProfileController;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER W7 audit heal] Drift guard: the PHP reserved-step-key list used by
 * createPersonalPage() to escape the kiosk registry MUST cover every key in the frozen
 * KioskWizardComponent's STEP_KEY_REGISTRY and ADDON_ROLE_TO_TYPE. If the JS registry gains a key
 * that PHP doesn't reserve, a builder-generated step_key could collide again and route a personal
 * page to a frozen specialized component that ignores its options (the P1 this heals).
 */
class ComposerPersonalPageStepKeySentinelTest extends TestCase
{
    public function test_php_reserved_keys_cover_the_frozen_kiosk_registry(): void
    {
        $src = file_get_contents(base_path('resources/js/components/frontend/kiosk/KioskWizardComponent.vue'));
        $this->assertIsString($src);

        $jsKeys = array_merge(
            $this->objectKeys($src, 'STEP_KEY_REGISTRY'),
            $this->objectKeys($src, 'ADDON_ROLE_TO_TYPE'),
        );
        $this->assertNotEmpty($jsKeys, 'failed to parse the kiosk registries');

        $reserved = ComposerProfileController::RESERVED_KIOSK_STEP_KEYS;
        foreach ($jsKeys as $key) {
            $this->assertContains(
                $key,
                $reserved,
                "kiosk registry key '{$key}' is not in ComposerProfileController::RESERVED_KIOSK_STEP_KEYS — "
                .'a personal-page step_key could collide with it and be hijacked by a frozen component.'
            );
        }
    }

    /** Extract the object-literal keys from `const <name> = Object.freeze({ key: ... });`. */
    private function objectKeys(string $src, string $constName): array
    {
        $start = strpos($src, "const {$constName} = Object.freeze({");
        if ($start === false) {
            return [];
        }
        $end = strpos($src, '});', $start);
        $block = substr($src, $start, $end - $start);

        preg_match_all('/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*:/m', $block, $m);

        return $m[1] ?? [];
    }
}

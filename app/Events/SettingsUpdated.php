<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [Wave 5G R9 heal 2026-05-17] Émis lors d'une mutation Admin Settings
 * (currency, tax, site, company, order_setup). Le listener
 * {@see \App\Listeners\PersistSettingsUpdatedToOutbox} persiste un row dans
 * `domain_events` par branche concernée (toutes les branches actives si
 * $branchId est null car les settings sont globaux).
 *
 * RED-team R9 evidence (2026-05-17):
 *   `grep -r "broadcast.*Setting" app/` returned zero rows. POS/Kiosk only
 *   refresh `frontend/setting` on full page reload — currency/tax change in
 *   admin didn't propagate live. This event closes the loop.
 */
class SettingsUpdated
{
    use DispatchableAfterCommit;

    /**
     * @param array<int, string> $changedKeys Group keys touched (e.g. ['currency'], ['tax'], ['site']).
     * @param int|null           $branchId    Optional branch scope (null => global fan-out to active branches).
     */
    public function __construct(
        public array $changedKeys,
        public ?int $branchId = null,
    ) {
    }
}

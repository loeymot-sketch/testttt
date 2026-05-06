<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;

/**
 * [PROMO-DASH-2026-05-06] Émis lors d'une mutation Dashboard d'un code promo
 * (création, mise à jour, suppression, toggle status). Le listener
 * {@see \App\Listeners\PersistCouponChangedToOutbox} persiste un row dans
 * `domain_events` pour chaque branche concernée par le scope du coupon (ou
 * toutes les branches actives si branch_scope est vide / null).
 */
class CouponChanged
{
    use DispatchableAfterCommit;

    public const CHANGE_CREATED = 'created';
    public const CHANGE_UPDATED = 'updated';
    public const CHANGE_DELETED = 'deleted';
    public const CHANGE_STATUS_TOGGLED = 'status_toggled';

    public function __construct(
        public int $couponId,
        public string $changeType,
        public ?string $code = null,
        public ?int $status = null,
        public array $branchScope = [],
        public array $surfaces = [],
        public array $payloadDiff = [],
    ) {
    }
}

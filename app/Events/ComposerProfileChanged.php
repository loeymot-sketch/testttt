<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;
use App\Models\ItemWizardProfile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class ComposerProfileChanged
{
    use DispatchableAfterCommit;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $profileId,
        public readonly string $changeType,
        public readonly ?int $branchId = null,
        public readonly array $payloadDiff = [],
    ) {
    }

    public static function fromProfile(ItemWizardProfile $profile, string $changeType): self
    {
        return new self(
            profileId: (int) $profile->id,
            changeType: $changeType,
            branchId: $profile->branch_id_scope !== null ? (int) $profile->branch_id_scope : null,
            payloadDiff: [
                'item_id' => (int) $profile->item_id,
                'version' => (int) $profile->version,
                'is_published' => (bool) $profile->is_published,
            ],
        );
    }
}

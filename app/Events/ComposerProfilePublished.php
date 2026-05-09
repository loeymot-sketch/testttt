<?php

namespace App\Events;

use App\Events\Concerns\DispatchableAfterCommit;
use App\Models\ItemWizardProfile;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Queue\SerializesModels;

class ComposerProfilePublished
{
    use DispatchableAfterCommit;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public readonly int $profileId)
    {
    }

    public static function fromProfile(ItemWizardProfile $profile): self
    {
        return new self((int) $profile->id);
    }
}

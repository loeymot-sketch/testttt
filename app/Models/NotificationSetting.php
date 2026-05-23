<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class NotificationSetting extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = "settings";

    /**
     * [GOAL-HEAL-SEC-001 2026-05-23] Pin the FCM service-account JSON media
     * collection to the non-public `firebase_private` disk so admin-uploaded
     * Firebase Admin private keys never land under public/ or the symlinked
     * public/storage/. Other settings media (none defined for this model
     * today) continue to use the default MEDIA_DISK. Phase B.3 Security
     * RED-team finding B3.2-001.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('notification-file')
            ->useDisk('firebase_private');
    }

    /**
     * [GOAL-HEAL-SEC-001 2026-05-23] When the JSON lives on a private disk,
     * `getFirstMediaUrl()` returns '' (Spatie's default for non-public
     * disks) — so the old `asset(getFirstMediaUrl(...))` would also return
     * '' even when a file IS attached. We now return a stable, non-leaky
     * sentinel string when a media record exists, used by both the admin
     * UI ("is configured" indicator) and FirebaseService::getAccessToken
     * (which now resolves the path via getFirstMedia()->getPath() rather
     * than by parsing a URL).
     */
    public function getFileAttribute(): string
    {
        $media = $this->getFirstMedia('notification-file');
        if ($media instanceof Media) {
            // Stable non-URL sentinel; FirebaseService reads file via media path.
            return 'firebase://service-account-file.json';
        }
        return '';
    }
}

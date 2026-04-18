<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kiosk Design V1 — Phase 1.1 (8/8)
 *
 * Journal RGPD des consentements loyalty — obligatoire pour prouver le
 * consentement explicite (master prompt §1.6 + CNIL).
 *
 * Principes :
 *  - Checkbox non pré-cochée côté client (enforced Phase 4 UI, validé
 *    serveur via `required|accepted`).
 *  - IP et user-agent stockés HASHÉS (sha256 + app_key salt) — non
 *    réversibles, compliant anonymisation.
 *  - `privacy_notice_version` : identifie la version du texte de politique
 *    de confidentialité au moment du consentement (audit trail).
 *  - Jamais supprimé (pas de `softDeletes`) — la suppression d'un compte
 *    utilisateur cascade les consentements (FK cascadeOnDelete), le droit
 *    à l'effacement RGPD est honoré par cette voie.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('loyalty_consents')) {
            Schema::create('loyalty_consents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->boolean('consent_accepted');
                $table->string('privacy_notice_version', 20);
                $table->char('ip_hash', 64);              // sha256 hex
                $table->char('user_agent_hash', 64);      // sha256 hex
                $table->timestamp('occurred_at')->useCurrent();
                $table->timestamps();

                $table->index(['user_id', 'occurred_at'], 'loyalty_consents_user_ts_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_consents');
    }
};

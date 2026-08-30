<?php

namespace Tests\Unit\VoiceOrder;

use App\Services\VoiceOrder\VoiceOrderRecommendedReply;
use PHPUnit\Framework\TestCase;

class VoiceOrderRecommendedReplyTest extends TestCase
{
    public function test_reply_asks_one_missing_slot_without_business_claims(): void
    {
        $reply = (new VoiceOrderRecommendedReply())->forDraft([
            'lines' => [['name' => 'Cayenne', 'missing_slots' => ['Sauce', 'Crudités']]],
            'ambiguities' => [],
        ]);

        $this->assertSame('Pour Cayenne, quel choix souhaitez-vous pour sauce ?', $reply);
        $this->assertDoesNotMatchRegularExpression('/prix|disponible|minute|allerg|accept/i', $reply);
    }

    public function test_ambiguity_uses_neutral_clarification(): void
    {
        $reply = (new VoiceOrderRecommendedReply())->forDraft(['lines' => [], 'ambiguities' => ['supplément inconnu']]);
        $this->assertStringContainsString('reformuler', $reply);
    }
}

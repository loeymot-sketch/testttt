<?php

namespace Tests\Feature\Sentinels;

use Tests\TestCase;

/**
 * [F-9 OBS RED-RED1 V1.0.1 quick win — 2026-05-19]
 *
 * Sentinel locking the ActionLog::create call site in OrderService::store()
 * to NOT write `Auth::user()->name` into the `details` field. The customer
 * name is plaintext PII in a 6-year-retained NF525-adjacent table; user_id
 * is already persisted on the same row (line 537), so the `Auteur:` segment
 * is a redundancy that leaks PII.
 *
 * Scope: this one site only. Report cites 11 similar sites (R1823, R2008,
 * plus 8 more in other services) — deliberately deferred to V1.0.2 to keep
 * this heal scope-minimal and reversible.
 *
 * Source audit: reports/audit/foundation-2026-05-18/round-1/F-9-OBS/STATUS.md
 *               §HEAL P1 → RED-RED1 (OrderService.php:540-545)
 *
 * Mode: source-pin (static read). Detects regression at PHPUnit time.
 */
class ActionLogPiiRedactionSentinelTest extends TestCase
{
    public function test_order_service_store_action_log_must_not_log_user_name_in_details(): void
    {
        $source = file_get_contents(base_path('app/Services/OrderService.php'));
        $this->assertNotFalse($source, 'OrderService.php missing');

        // The Web/App order creation ActionLog::create() block (the one with
        // action = 'Nouvelle commande Web/App' near line 537) MUST not call
        // Auth::user()->name inside its sprintf. user_id is the canonical
        // lookup key — anything else is unnecessary PII.
        $blockMatched = preg_match(
            "/'action'\s*=>\s*'Nouvelle commande Web\/App'.*?\]\);/s",
            $source,
            $m
        );

        $this->assertSame(
            1,
            $blockMatched,
            "Could not locate the 'Nouvelle commande Web/App' ActionLog::create block. "
                . "If the action label was renamed, update this sentinel."
        );

        $this->assertStringNotContainsString(
            'Auth::user()->name',
            $m[0],
            'OrderService::store ActionLog::create MUST NOT log customer name in details (RGPD 5(1)(c)). '
                . 'See reports/audit/foundation-2026-05-18/round-1/F-9-OBS/STATUS.md §HEAL RED-RED1. '
                . 'The user_id column already provides forensic linkage.'
        );

        $this->assertStringNotContainsString(
            'Auteur:',
            $m[0],
            'OrderService::store ActionLog::create MUST drop the "Auteur:" segment to remove the PII surface.'
        );
    }
}

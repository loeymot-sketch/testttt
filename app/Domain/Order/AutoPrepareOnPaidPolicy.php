<?php

namespace App\Domain\Order;

use App\Enums\OrderStatus;
use App\Enums\PosPaymentMethod;

/**
 * Wave S-1 (2026-05-20) — Centralised policy for the
 * "auto-transition ACCEPT → PREPARING when payment is confirmed" decision.
 *
 * Owner decision context (P-OWNER Wave S-1):
 *   When the cashier confirms a paid order on the POS (cash or TPE) — or when
 *   a kiosk order's online TPE settlement is finalised — the order should
 *   immediately advance from ACCEPT (CONFIRMÉE) to PREPARING (EN PRÉPARATION).
 *   Kitchen sees the ticket already "en cours" without a second tap.
 *
 *   EXCEPTION (Wave S-5 sister mission): kiosk orders that the customer chose
 *   to pay in CASH at the counter must NOT auto-advance. They stay in ACCEPT
 *   until the cashier explicitly validates the cash collection through the
 *   S-5 "à encaisser" UI. This protects against a kitchen starting to prep
 *   an order whose payment may never actually be tendered.
 *
 * Frozen zones honoured (CLAUDE.md §7):
 *   - OrderStateMachine.php is READ-ONLY; this policy only consults the
 *     existing legal transition ACCEPT → PREPARING that `allows()` already
 *     permits (see OrderStateMachine::allows line 45).
 *   - PricingService / composition_snapshot / fiscal_sequence_no are NOT
 *     touched — auto-prepare is a status-only side-effect.
 *
 * Config knob (`config/pos.php` → `auto_prepare_on_paid`, env
 * `POS_AUTO_PREPARE_ON_PAID`) defaults to TRUE; set FALSE for emergency
 * rollback to the legacy "stay in ACCEPT" behaviour without code change.
 *
 * Three integration sites:
 *   1. {@see \App\Services\OrderService::posOrderStore}        — POS direct sale
 *   2. {@see \App\Services\FrontendOrderService::finalizePaidKioskOrder} — kiosk online TPE
 *   3. {@see \App\Services\PaymentService::confirmCounterPayment} — counter-collect
 *
 * @see tests/Feature/Order/AutoPrepareOnPaidTest.php
 */
final class AutoPrepareOnPaidPolicy
{
    /**
     * Should an order that has just become PAID auto-advance to PREPARING?
     *
     * @param  string       $surface           'pos' | 'kiosk' (matches `orders.source_surface`)
     * @param  int|null     $posPaymentMethod  {@see PosPaymentMethod} value, or null when irrelevant
     * @param  bool         $isCounterCollect  TRUE only when called from
     *                                          PaymentService::confirmCounterPayment — disambiguates
     *                                          "kiosk paid online by card" (auto-promote OK) from
     *                                          "kiosk cash physically tendered at counter" (S-5 skip).
     */
    public static function shouldPromote(
        string $surface,
        ?int $posPaymentMethod = null,
        bool $isCounterCollect = false,
    ): bool {
        if (! (bool) config('pos.auto_prepare_on_paid', true)) {
            return false;
        }

        // S-5 EXCEPTION: kiosk cash physically tendered at the counter must
        // wait for cashier validation. Detection collapses to a single check
        // because `confirmCounterPayment` is the ONLY caller path that
        // forwards $isCounterCollect=true and the mode parameter discriminates
        // CASH from CARD/MOBILE/TICKET/OTHER. See plan ULTRA_PLAN_FULLFLOW
        // §S-1↔S-5 disjointness.
        if ($isCounterCollect && $posPaymentMethod === PosPaymentMethod::CASH) {
            return false;
        }

        return true;
    }

    /**
     * Convenience for callers that have already decided to promote: returns
     * the next status. Centralised here so any future intermediate status
     * (e.g. FRAUD_HOLD, MANAGER_APPROVAL) is added in exactly one place.
     */
    public static function nextStatus(): int
    {
        return OrderStatus::PREPARING;
    }
}

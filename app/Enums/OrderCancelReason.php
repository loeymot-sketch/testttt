<?php

namespace App\Enums;

/**
 * [AUDIT-F-004] Whitelist of cancel/reject/return reason codes.
 *
 * Used by:
 *  - Kiosk-originated cancels (TPE decline/cancel/timeout, customer cancel from waiting screen).
 *    Kiosk machine token actors MUST send a whitelisted code.
 *  - Admin POS/Online/Table cancels MAY send a free-text reason instead (legacy compatibility).
 *
 * Audit trail (ORDER_FLOW.md §49) requires non-empty `reason` on every transition to
 * CANCELED, REJECTED, RETURNED. This enum normalises the kiosk-side codes so the
 * `order_status_transitions.reason` column carries a stable analytics dimension.
 */
final class OrderCancelReason
{
    public const TPE_CANCEL_USER  = 'tpe_cancel_user';
    public const TPE_DECLINED     = 'tpe_declined';
    public const TPE_TIMEOUT      = 'tpe_timeout';
    public const CASHIER_VOID     = 'cashier_void';
    public const CUSTOMER_REQUEST = 'customer_request';
    public const STOCKOUT         = 'stockout';
    public const KITCHEN_REJECT   = 'kitchen_reject';
    public const DUPLICATE        = 'duplicate';
    public const MANAGER_OVERRIDE = 'manager_override';
    public const SYSTEM_TIMEOUT   = 'system_timeout';
    public const PAYMENT_FAILED   = 'payment_failed';
    public const OTHER            = 'other';

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::TPE_CANCEL_USER,
            self::TPE_DECLINED,
            self::TPE_TIMEOUT,
            self::CASHIER_VOID,
            self::CUSTOMER_REQUEST,
            self::STOCKOUT,
            self::KITCHEN_REJECT,
            self::DUPLICATE,
            self::MANAGER_OVERRIDE,
            self::SYSTEM_TIMEOUT,
            self::PAYMENT_FAILED,
            self::OTHER,
        ];
    }

    public static function isValid(?string $code): bool
    {
        return is_string($code) && in_array($code, self::all(), true);
    }
}

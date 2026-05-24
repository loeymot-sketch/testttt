<?php

namespace App\Providers;

use App\Events\BranchStatusChanged;
use App\Events\SendOrderDeliveryBoyMail;
use App\Events\SendOrderDeliveryBoyPush;
use App\Events\SendOrderDeliveryBoySms;
use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Events\CatalogChanged;
use App\Events\ComposerProfileChanged;
use App\Events\CouponChanged;
use App\Events\SettingsUpdated;
use App\Events\IngredientAvailabilityChanged;
use App\Events\ItemAvailabilityChanged;
use App\Events\ItemCreated;
use App\Events\ItemDeleted;
use App\Events\ItemUpdated;
// [F-016a-BIS] Branch-scoped extras / variations rupture toggles.
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Events\OrderCanceled;
use App\Events\OrderCreated;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderPaymentStatusChanged;
use App\Events\OrderStatusChanged;
use App\Events\OrderTableChanged;
// [HEAL B.2 2026-05-19] OutboxBroadcastSwallowedEvent listener registration —
// closes RED-Z3 finding B-3 P1 (alarm void on outbox swallow).
use App\Events\OutboxBroadcastSwallowedEvent;
use App\Events\RefundCreated;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Events\SendResetPassword;
use App\Events\SendSmsCode;
use App\Events\StockLevelChanged;
use App\Listeners\SendOrderDeliveryBoyMailNotification;
use App\Listeners\SendOrderDeliveryBoyPushNotification;
use App\Listeners\SendOrderDeliveryBoySmsNotification;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
// [GOAL-J2-HEAL-07 2026-05-24] Phase J-ADV-3 L3 P1 — clawback earned points on refund.
use App\Listeners\ClawbackLoyaltyPointsOnRefund;
use App\Listeners\BumpMenuSnapshotOnItemAvailabilityChanged;
// [HEAL B.2 2026-05-19] Pager-grade escalator for outbox broadcast swallows.
use App\Listeners\EscalateOutboxBroadcastSwallowed;
use App\Listeners\InvalidateKioskMenuCacheOnCatalogChange;
use App\Listeners\InvalidateKioskMenuCacheOnItemAvailabilityChanged;
use App\Listeners\InvalidateMenuProjectionOnIngredientChange;
use App\Listeners\PersistCatalogChangedToOutbox;
use App\Listeners\PersistCouponChangedToOutbox;
use App\Listeners\PersistItemAvailabilityChangedToOutbox;
// [F-016a-BIS]
use App\Listeners\PersistItemExtraAvailabilityChangedToOutbox;
use App\Listeners\PersistItemVariationAvailabilityChangedToOutbox;
use App\Listeners\DecrementItemAvailabilityOnOrder;
use App\Listeners\DecrementStockOnOrderCreated;
use App\Listeners\ReleaseAvailabilityOnOrderCanceled;
use App\Listeners\ReleaseAvailabilityOnRefundCreated;
use App\Listeners\ReleaseStockOnOrderCanceled;
use App\Listeners\ReleaseStockOnRefundCreated;
use App\Listeners\PersistOrderCreatedToOutbox;
use App\Listeners\PersistOrderPaidAtCounterToOutbox;
use App\Listeners\PersistOrderPaymentStatusChangedOnRefundCreated;
use App\Listeners\PersistOrderPaymentStatusChangedToOutbox;
use App\Listeners\PersistOrderStatusChangedToOutbox;
use App\Listeners\PersistOrderTableChangedToOutbox;
use App\Listeners\PersistSettingsUpdatedToOutbox;
use App\Listeners\RevokeTokensOnBranchDeactivated;
use App\Listeners\NotifyStockLowOnStockLevelChanged;
use App\Listeners\SendFcmOnOrderCreated;
use App\Listeners\SendFcmOnOrderStatusChange;
use App\Listeners\SendOrderGotMailNotification;
use App\Listeners\SendOrderGotPushNotification;
use App\Listeners\SendOrderGotSmsNotification;
use App\Listeners\SendOrderMailNotification;
use App\Listeners\SendOrderPushNotification;
use App\Listeners\SendOrderSmsNotification;
use App\Listeners\SendResetPasswordNotification;
use App\Listeners\SendSmsCodeNotification;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class               => [
            SendEmailVerificationNotification::class
        ],
        SendResetPassword::class        => [
            SendResetPasswordNotification::class
        ],
        SendSmsCode::class              => [
            SendSmsCodeNotification::class
        ],
        SendOrderMail::class            => [
            SendOrderMailNotification::class
        ],
        SendOrderSms::class             => [
            SendOrderSmsNotification::class
        ],
        SendOrderPush::class            => [
            SendOrderPushNotification::class
        ],
        SendOrderDeliveryBoyMail::class => [
            SendOrderDeliveryBoyMailNotification::class
        ],
        SendOrderDeliveryBoySms::class  => [
            SendOrderDeliveryBoySmsNotification::class
        ],
        SendOrderDeliveryBoyPush::class => [
            SendOrderDeliveryBoyPushNotification::class
        ],
        SendOrderGotMail::class         => [
            SendOrderGotMailNotification::class
        ],
        SendOrderGotSms::class         => [
            SendOrderGotSmsNotification::class
        ],
        SendOrderGotPush::class         => [
            SendOrderGotPushNotification::class
        ],
        // [SPLASH LOYALTY] Auto-award points when order is delivered.
        // [F-002 round-3 2026-05-10] Listener order matters: Persist*ToOutbox MUST
        // run BEFORE side-effect listeners (FCM, loyalty) because the outbox is the
        // SSOT for KDS/Kiosk/POS sync. With QUEUE_CONNECTION=sync, an exception
        // thrown by ShouldQueue listeners (e.g. FCM job throwing on missing creds)
        // propagates through the event dispatcher and prevents downstream listeners
        // from running — see F-002 evidence: 87 `order.created` rows persisted
        // before id 733, then ZERO for kiosk orders 596+ once FCM started failing.
        // Outbox-first guarantees the durable record is written even if a
        // side-effect listener crashes; the FCM listener also wraps its body in
        // try/catch (defense in depth) so it never throws upward.
        OrderStatusChanged::class => [
            PersistOrderStatusChangedToOutbox::class,
            AwardLoyaltyPointsOnDelivery::class,
            // [PHASE-36-P1] FCM push notifications on status change
            SendFcmOnOrderStatusChange::class,
        ],
        // [PHASE-36-P1] FCM push notifications on new order
        OrderCreated::class => [
            // [F-002 round-3] Outbox SSOT first — see comment block above.
            PersistOrderCreatedToOutbox::class,
            DecrementItemAvailabilityOnOrder::class,
            DecrementStockOnOrderCreated::class,
            SendFcmOnOrderCreated::class,
        ],
        OrderPaidAtCounter::class => [
            PersistOrderPaidAtCounterToOutbox::class,
        ],
        // [P13 — F-VERIFY-09-01 / F-VERIFY-09-10] payment_status transitions.
        OrderPaymentStatusChanged::class => [
            PersistOrderPaymentStatusChangedToOutbox::class,
        ],
        // [F-01 + NEW-05] Compensating release of stock counters on cancel / refund.
        // Idempotent via order_items.released_qty ledger inside AvailabilityService.
        OrderCanceled::class => [
            ReleaseStockOnOrderCanceled::class,
            ReleaseAvailabilityOnOrderCanceled::class,
        ],
        // [HEAL-PLAN-D.3 / RED-Z8 P2-2 — heal/cms-pr1-quickwins-2026-05-18]
        // Persist+broadcast FIRST so a downstream stock / availability release
        // listener throw cannot silently re-open the WG-1 P1-1 broadcast hole.
        // Laravel's sync dispatcher (vendor/.../Events/Dispatcher.php:233-269)
        // halts on listener throw — position matters. Persist listener is
        // itself wrapped in try/catch envelopes (see PersistOrderPaymentStatusChangedOnRefundCreated
        // lines 79-138) so it never propagates upward, guaranteeing the
        // remaining listeners still run when Persist itself fails.
        RefundCreated::class => [
            // [WG-1-WF6-P1-1] Realtime refund signal — MUST run first so
            // POS / admin / OSS clients still receive the broadcast even
            // when a later listener (stock / availability release) throws.
            PersistOrderPaymentStatusChangedOnRefundCreated::class,
            ReleaseStockOnRefundCreated::class,
            ReleaseAvailabilityOnRefundCreated::class,
            // [GOAL-J2-HEAL-07 2026-05-24] Phase J-ADV-3 L3 P1 CONFIRMED:
            // Earned loyalty points were never decremented after a refund —
            // 10 pts/€ default × 30€ order = 300 pts (= 3€) left on customer
            // balance, repeatable cash + points double-dip. Appended LAST so
            // a clawback failure (try/catch isolated) cannot halt the cash-
            // trail / stock / availability cascade above.
            ClawbackLoyaltyPointsOnRefund::class,
        ],
        // [F-02] Floorplan transfer / occupy → KDS gets a non-disruptive update.
        OrderTableChanged::class => [
            PersistOrderTableChangedToOutbox::class,
        ],
        ItemAvailabilityChanged::class => [
            BumpMenuSnapshotOnItemAvailabilityChanged::class,
            // Kiosk Phase 9.1.4 — invalidation du cache `kiosk.menu.branch.{id}`
            // pour que les bornes voient un 86 temps réel en < 1 s au lieu
            // d'attendre l'expiration naturelle du TTL (≤ 60 s).
            InvalidateKioskMenuCacheOnItemAvailabilityChanged::class,
            PersistCatalogChangedToOutbox::class,
            PersistItemAvailabilityChangedToOutbox::class,
        ],
        // [F-016a-BIS] Persist + broadcast branch-scoped extra/variation rupture toggles.
        // No menu snapshot bump — extras/variations live inside item payloads, the
        // surface refresh happens via the dedicated event broadcast that StockManager
        // UI / Kiosk handlers subscribe to (F-016b).
        ItemExtraAvailabilityChanged::class => [
            PersistItemExtraAvailabilityChangedToOutbox::class,
            // [WAVE5-DATA-004] Bridge to generic catalog stream so kiosk menu cache
            // + POS catalog refresh without waiting for F-016b dedicated handlers.
            PersistCatalogChangedToOutbox::class,
            // [Q9-S1 owner Q2=fix 2026-05-21] Without this listener, the kiosk
            // backend menu cache (`kiosk.menu.branch.{id}`, TTL 60s, see
            // MenuController.php:56-88) keeps serving stale extras availability
            // for up to 60s after an admin sauce/topping toggle. The frontend
            // `fetchMenu({force:true})` triggered by the CatalogChanged
            // broadcast only bypasses the FE memory cache — the BE cache must
            // be invalidated explicitly, matching the symmetric wiring on
            // ItemAvailabilityChanged above.
            InvalidateKioskMenuCacheOnCatalogChange::class,
        ],
        ItemVariationAvailabilityChanged::class => [
            PersistItemVariationAvailabilityChangedToOutbox::class,
            // [WAVE5-DATA-004] Bridge to generic catalog stream (same rationale).
            PersistCatalogChangedToOutbox::class,
            // [Q9-S1 owner Q2=fix 2026-05-21] Same rationale as ItemExtra above.
            InvalidateKioskMenuCacheOnCatalogChange::class,
        ],
        IngredientAvailabilityChanged::class => [
            InvalidateMenuProjectionOnIngredientChange::class,
        ],
        CatalogChanged::class => [
            PersistCatalogChangedToOutbox::class,
        ],
        ItemCreated::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
        ],
        // [GOAL-I2-HEAL-02 2026-05-24] Phase I.3 RISK-01 AMBER:
        // Mirror ItemCreated/ItemDeleted so admin rename/reprice flushes
        // kiosk.menu.branch.{id} cache instead of waiting 60s TTL.
        ItemUpdated::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
        ],
        ItemDeleted::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
        ],
        CategoryCreated::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
        ],
        CategoryUpdated::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
        ],
        CategoryDeleted::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
        ],
        ComposerProfileChanged::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
        ],
        StockLevelChanged::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
            PersistCatalogChangedToOutbox::class,
            NotifyStockLowOnStockLevelChanged::class,
        ],
        // [PROMO-DASH-2026-05-06] Coupon mutations -> outbox per active branch.
        CouponChanged::class => [
            PersistCouponChangedToOutbox::class,
        ],
        // [Wave 5G R9 heal 2026-05-17] Admin Settings mutations -> outbox fan-out.
        // POS/Kiosk listening on `private-branch.{id}` receive a SettingsUpdated
        // broadcast and refresh their `frontend/setting` payload live.
        SettingsUpdated::class => [
            PersistSettingsUpdatedToOutbox::class,
        ],
        // [Wave 5G R10 heal 2026-05-17] Branch status flip -> revoke Sanctum
        // tokens for users of that branch when new status === INACTIVE.
        // Closes the 480-min TTL hole flagged by RED-team R10.
        // [T-6.4 GOAL Phase 2 2026-05-18] + PersistBranchStatusChangedToOutbox
        // (Z7-V1.0.2-P2-01). Order: RevokeTokens FIRST (sync DB delete),
        // PersistToOutbox SECOND (async broadcast via DispatchDomainEventsJob).
        BranchStatusChanged::class => [
            RevokeTokensOnBranchDeactivated::class,
            \App\Listeners\PersistBranchStatusChangedToOutbox::class,
        ],
        // [HEAL B.2 2026-05-19] V1 LOCAL pager-grade alarm for outbox
        // broadcast swallows. Closes RED-Z3 §B-3 alarm void.
        // Listener emits Log::channel('fiscal')->critical with the full
        // structured payload — see EscalateOutboxBroadcastSwallowed::class
        // for channel/payload/queueing rationale.
        OutboxBroadcastSwallowedEvent::class => [
            EscalateOutboxBroadcastSwallowed::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        User::observe(UserObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}

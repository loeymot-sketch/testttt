<?php

namespace App\Providers;

use App\Events\SendOrderDeliveryBoyMail;
use App\Events\SendOrderDeliveryBoyPush;
use App\Events\SendOrderDeliveryBoySms;
use App\Events\CategoryCreated;
use App\Events\CategoryDeleted;
use App\Events\CategoryUpdated;
use App\Events\ItemAvailabilityChanged;
use App\Events\ItemCreated;
use App\Events\ItemDeleted;
use App\Events\OrderCanceled;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Events\OrderTableChanged;
use App\Events\RefundCreated;
use App\Events\SendOrderGotMail;
use App\Events\SendOrderGotPush;
use App\Events\SendOrderGotSms;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Events\SendResetPassword;
use App\Events\SendSmsCode;
use App\Listeners\SendOrderDeliveryBoyMailNotification;
use App\Listeners\SendOrderDeliveryBoyPushNotification;
use App\Listeners\SendOrderDeliveryBoySmsNotification;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Listeners\BumpMenuSnapshotOnItemAvailabilityChanged;
use App\Listeners\InvalidateKioskMenuCacheOnCatalogChange;
use App\Listeners\InvalidateKioskMenuCacheOnItemAvailabilityChanged;
use App\Listeners\PersistItemAvailabilityChangedToOutbox;
use App\Listeners\DecrementItemAvailabilityOnOrder;
use App\Listeners\ReleaseAvailabilityOnOrderCanceled;
use App\Listeners\ReleaseAvailabilityOnRefundCreated;
use App\Listeners\PersistOrderCreatedToOutbox;
use App\Listeners\PersistOrderStatusChangedToOutbox;
use App\Listeners\PersistOrderTableChangedToOutbox;
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
        // [SPLASH LOYALTY] Auto-award points when order is delivered
        OrderStatusChanged::class => [
            AwardLoyaltyPointsOnDelivery::class,
            // [PHASE-36-P1] FCM push notifications on status change
            SendFcmOnOrderStatusChange::class,
            PersistOrderStatusChangedToOutbox::class,
        ],
        // [PHASE-36-P1] FCM push notifications on new order
        OrderCreated::class => [
            SendFcmOnOrderCreated::class,
            PersistOrderCreatedToOutbox::class,
            DecrementItemAvailabilityOnOrder::class,
        ],
        // [F-01 + NEW-05] Compensating release of stock counters on cancel / refund.
        // Idempotent via order_items.released_qty ledger inside AvailabilityService.
        OrderCanceled::class => [
            ReleaseAvailabilityOnOrderCanceled::class,
        ],
        RefundCreated::class => [
            ReleaseAvailabilityOnRefundCreated::class,
        ],
        // [F-02] Floorplan transfer / occupy → KDS gets a non-disruptive update.
        OrderTableChanged::class => [
            PersistOrderTableChangedToOutbox::class,
        ],
        ItemAvailabilityChanged::class => [
            PersistItemAvailabilityChangedToOutbox::class,
            BumpMenuSnapshotOnItemAvailabilityChanged::class,
            // Kiosk Phase 9.1.4 — invalidation du cache `kiosk.menu.branch.{id}`
            // pour que les bornes voient un 86 temps réel en < 1 s au lieu
            // d'attendre l'expiration naturelle du TTL (≤ 60 s).
            InvalidateKioskMenuCacheOnItemAvailabilityChanged::class,
        ],
        ItemCreated::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
        ],
        ItemDeleted::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
        ],
        CategoryCreated::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
        ],
        CategoryUpdated::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
        ],
        CategoryDeleted::class => [
            InvalidateKioskMenuCacheOnCatalogChange::class,
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

<?php
namespace App\Enums;

interface PaymentGateway
{
    const CASH_ON_DELIVERY  = 1;
    const E_WALLET          = 2;
    const PAYPAL            = 3;
    const CARD              = 4;  // TPE / carte bancaire (kiosk)
    const TICKET_RESTAURANT = 5;  // Titre-restaurant (kiosk)
    // [PARALLEL-TRACK-1.1] Payment was settled by the delivery platform
    // (Uber Eats / Deliveroo / Delicity). FoodKing only records the
    // remittance — the actual card / wallet transaction is owned by
    // the platform. Numbered 100 to leave 6-99 free for future
    // POS-internal payment methods.
    const DELIVERY_PLATFORM = 100;
}

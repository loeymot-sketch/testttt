<?php
namespace App\Enums;

interface PaymentGateway
{
    const CASH_ON_DELIVERY  = 1;
    const E_WALLET          = 2;
    const PAYPAL            = 3;
    const CARD              = 4;  // TPE / carte bancaire (kiosk)
    const TICKET_RESTAURANT = 5;  // Titre-restaurant (kiosk)
    const PAY_AT_COUNTER    = 6;  // V1 launch — kiosk submits unpaid order, caisse finalises
}

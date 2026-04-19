<?php

namespace App\Enums;

interface PosPaymentMethod
{
    const CASH = 1;

    const CARD = 2;

    const MOBILE_BANKING = 3;

    const OTHER = 4;

    /** Titres-restaurant / chèques déjeuner (aligné sur {@see \App\Enums\PaymentGateway::TICKET_RESTAURANT}) */
    const TICKET_RESTAURANT = 5;
}

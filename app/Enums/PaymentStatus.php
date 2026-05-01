<?php

namespace App\Enums;

interface PaymentStatus
{
    const PAID            = 5;
    const UNPAID          = 10;
    const PENDING_COUNTER = 15;
    const REFUNDED        = 20;
}

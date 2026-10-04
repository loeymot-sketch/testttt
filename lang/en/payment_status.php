<?php

use App\Enums\PaymentStatus;

return [
    PaymentStatus::PAID   => 'Paid',
    PaymentStatus::UNPAID => 'Unpaid',
    // [ONB-04 2026-08-28] Absents ici : la cle brute s'affichait a la place.
    // 15 = routage Plan B de la borne vers la caisse ; 20 = contrepartie comptable.
    PaymentStatus::PENDING_COUNTER => 'Pay at the counter',
    PaymentStatus::REFUNDED => 'Refunded',

];

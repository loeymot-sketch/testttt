<?php

use App\Enums\PosPaymentMethod;

return [
    PosPaymentMethod::CARD => 'Card',
    PosPaymentMethod::CASH => 'Cash',
    PosPaymentMethod::OTHER => 'Other',
    PosPaymentMethod::MOBILE_BANKING => 'MFS',
    PosPaymentMethod::TICKET_RESTAURANT => 'Meal voucher',
    // [ONB-04 2026-08-28] Absent ici : la cle brute s'affichait.
    PosPaymentMethod::COUNTER_DEFERRED => 'Deferred counter payment',

];

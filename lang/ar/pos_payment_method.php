<?php

use App\Enums\PosPaymentMethod;

return [
    PosPaymentMethod::CARD => 'بطاقة',
    PosPaymentMethod::CASH => 'نقداً',
    PosPaymentMethod::OTHER => 'آخر',
    PosPaymentMethod::MOBILE_BANKING => 'MFS',
    PosPaymentMethod::TICKET_RESTAURANT => 'قسيمة وجبات',
    // [ONB-04 2026-08-28] راجع lang/en/pos_payment_method.php
    PosPaymentMethod::COUNTER_DEFERRED => 'دفع مؤجل عند الكاونتر',

];

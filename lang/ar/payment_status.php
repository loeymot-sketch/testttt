<?php

use App\Enums\PaymentStatus;

return [
    PaymentStatus::PAID   => 'تم الدفع',
    PaymentStatus::UNPAID => 'غير مدفوع',
    // [ONB-04 2026-08-28] راجع lang/en/payment_status.php
    PaymentStatus::PENDING_COUNTER => 'الدفع عند الكاونتر',
    PaymentStatus::REFUNDED => 'مسترد',

];

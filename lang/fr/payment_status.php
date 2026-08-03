<?php

use App\Enums\PaymentStatus;

return [
    PaymentStatus::PAID   => 'Payé',
    PaymentStatus::UNPAID => 'Non payé',
    PaymentStatus::PENDING_COUNTER => 'À régler au comptoir',
    PaymentStatus::REFUNDED => 'Remboursé',
    ''                    => '',

];

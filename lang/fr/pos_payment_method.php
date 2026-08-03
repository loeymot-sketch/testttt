<?php

use App\Enums\PosPaymentMethod;

return [
    PosPaymentMethod::CARD => 'Carte',
    PosPaymentMethod::CASH => 'Espèces',
    PosPaymentMethod::OTHER => 'Autre',
    PosPaymentMethod::MOBILE_BANKING => 'Paiement mobile',
    PosPaymentMethod::TICKET_RESTAURANT => 'Titre-restaurant',
    PosPaymentMethod::COUNTER_DEFERRED => 'Comptoir différé',
    '' => '',
];

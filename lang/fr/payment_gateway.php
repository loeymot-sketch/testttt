<?php

use App\Enums\PaymentGateway;

return [
    PaymentGateway::CASH_ON_DELIVERY => 'Espèces',
    PaymentGateway::E_WALLET         => 'Portefeuille électronique',
    PaymentGateway::PAYPAL           => 'PayPal',
    ''                               => '',

];

<?php

namespace Database\Seeders;

use App\Enums\GatewayMode;
use App\Enums\Activity;
use App\Models\GatewayOption;
use App\Models\PaymentGateway;
use Dipokhalder\EnvEditor\EnvEditor;
use Illuminate\Database\Seeder;

class PaymentGatewayDataTableSeeder extends Seeder
{

    public array $gateways = [
        [
            "slug" => "paypal",
            "status" => Activity::ENABLE,
            "options" => [
                [
                    "option" => 'paypal_app_id',
                    "value" => 'sb-qzxs18789565@business.example.com',
                ],
                [
                    "option" => 'paypal_client_id',
                    "value" => 'AbcV-BG5b30hjofcp2dj41GB1OYXE8_9-egRlV8z4R7vHiA-1mgL3Fvj3pkrOJyq0dC_vHNRAh_tp74p'
                ],
                [
                    "option" => 'paypal_client_secret',
                    "value" => 'EP6r5hEtBc6icJeEseZIiOJqSRnFvqNLI7yxjplzITaObh-t-516gGJ_EysXisLtEavaIMcjrG9aYprz'
                ],
                [
                    "option" => 'paypal_mode',
                    "value" => GatewayMode::SANDBOX
                ],
                [
                    "option" => 'paypal_status',
                    "value" => Activity::ENABLE
                ],
            ]
        ],
        [
            "slug" => "stripe",
            "status" => Activity::ENABLE,
            "options" => [
                [
                    "option" => 'stripe_key',
                    "value" => 'pk_test_6rhnZv1NmRtSp5DfziBO8YFb00X65CfFwq',
                ],
                [
                    "option" => 'stripe_secret',
                    "value" => 'sk_test_YKyuAoMHjXaUADW4SuKuXeIn0079Pu1OSD',
                ],
                [
                    "option" => 'stripe_mode',
                    "value" => GatewayMode::SANDBOX,
                ],
                [
                    "option" => 'stripe_status',
                    "value" => Activity::ENABLE,
                ],
            ]
        ]
    ];

    public function run(): void
    {
        $envService = new EnvEditor();
        if ($envService->getValue('DEMO')) {
            foreach ($this->gateways as $gateway) {
                $payment = PaymentGateway::where(['slug' => $gateway['slug']])->first();
                if ($payment) {
                    $payment->status = $gateway['status'];
                    $payment->save();
                }
                $this->gatewayOption($gateway['options']);
            }
        }
    }

    public function gatewayOption($options): void
    {
        if (!blank($options)) {
            foreach ($options as $option) {
                $gatewayOption = GatewayOption::where(['option' => $option['option']])->first();
                if ($gatewayOption) {
                    $gatewayOption->value = $option['value'];
                    $gatewayOption->save();
                }
            }
        }
    }
}
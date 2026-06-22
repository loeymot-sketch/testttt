<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],


    'paytm-wallet' => [
        'env'              => "",
        'merchant_id'      => "",
        'merchant_key'     => "",
        'merchant_website' => "",
        'channel'          => "",
        'industry_type'    => "",
    ],

    'easypaisa' => [
        'env'              => "",
        'storeId'      => "",
        'hashKey'     => "",
    ],

    /**
     * [PHASE-36-P1] Firebase Cloud Messaging (FCM) configuration.
     * Get your Server Key from Firebase Console → Project Settings → Cloud Messaging
     */
    'fcm' => [
        'server_key' => env('FCM_SERVER_KEY', ''),
        'sender_id'  => env('FCM_SENDER_ID', ''),
        'topic_prefix' => env('FCM_TOPIC_PREFIX', 'foodking'),
    ],

    /**
     * [Sprint 3A — Webhook idempotency 2026-05-16]
     * Stripe webhook signing secret (whsec_...). Distinct from the gateway
     * api_key stored in PaymentGateway DB options. Used by
     * \Stripe\Webhook::constructEvent() to verify the Stripe-Signature header
     * on inbound /payment/stripe-webhook/ requests.
     */
    'stripe' => [
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],

];

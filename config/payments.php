<?php

use App\Services\Payments\SimulatedPaymentProvider;
use App\Services\Payments\AsaasPaymentProvider;

return [
    'default' => env('PAYMENT_PROVIDER', 'simulated'),
    'cash_in' => [
        'card_test_phones' => env('CARD_CASH_IN_TEST_PHONES', ''),
        'pix_provider' => env('PIX_CASH_IN_PROVIDER', env('PAYMENT_PROVIDER', 'simulated')),
        'boleto_provider' => env('BOLETO_CASH_IN_PROVIDER', env('PAYMENT_PROVIDER', 'simulated')),
        'credit_card_provider' => env('CREDIT_CARD_CASH_IN_PROVIDER', env('PIX_CASH_IN_PROVIDER', env('PAYMENT_PROVIDER', 'simulated'))),
        'debit_card_provider' => env('DEBIT_CARD_CASH_IN_PROVIDER', env('CREDIT_CARD_CASH_IN_PROVIDER', env('PAYMENT_PROVIDER', 'simulated'))),
        'boleto_due_days' => (int) env('BOLETO_DUE_DAYS', 93),
    ],
    'cash_out' => [
        'pix_provider' => env('PIX_CASH_OUT_PROVIDER', env('PAYMENT_PROVIDER', 'simulated')),
    ],

    'providers' => [
        'simulated' => [
            'driver' => SimulatedPaymentProvider::class,
        ],

        'asaas' => [
            'driver' => AsaasPaymentProvider::class,
            'base_url' => env('ASAAS_BASE_URL', 'https://api.asaas.com'),
            'api_key' => env('ASAAS_API_KEY'),
            'webhook_token' => env('ASAAS_WEBHOOK_TOKEN'),
            'verify_ssl' => env('ASAAS_HTTP_VERIFY_SSL', true),
        ],

        'pagarme' => [
            'driver' => env('PAGARME_PAYMENT_PROVIDER_CLASS'),
            'base_url' => env('PAGARME_BASE_URL'),
            'api_key' => env('PAGARME_API_KEY'),
        ],

        'c6' => [
            'driver' => env('C6_PAYMENT_PROVIDER_CLASS'),
            'base_url' => env('C6_BASE_URL'),
            'client_id' => env('C6_CLIENT_ID'),
            'client_secret' => env('C6_CLIENT_SECRET'),
        ],
    ],
];

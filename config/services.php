<?php

return [

    'documents' => [
        'public_url' => env('DOCUMENTS_PUBLIC_URL', rtrim(env('APP_URL', ''), '/').'/public'),
    ],

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

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'public_number' => env('WHATSAPP_PUBLIC_NUMBER', ''),
        'asaas_activity_admin_phone' => env('WHATSAPP_ASAAS_ACTIVITY_ADMIN_PHONE', ''),
        'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'verify_ssl' => env('WHATSAPP_HTTP_VERIFY_SSL', true),
        'welcome_image_url' => env('WHATSAPP_WELCOME_IMAGE_URL'),
        'boleto_pdf_url' => env('WHATSAPP_BOLETO_PDF_URL'),
        'receipt_pdf_url' => env('WHATSAPP_RECEIPT_PDF_URL'),
        'statement_image_url' => env('WHATSAPP_STATEMENT_IMAGE_URL'),
        'start_template' => env('WHATSAPP_START_TEMPLATE', 'boas_vindas'),
        'start_template_language' => env('WHATSAPP_START_TEMPLATE_LANGUAGE', 'pt_BR'),
        'transfer_notification_template' => env('WHATSAPP_TRANSFER_NOTIFICATION_TEMPLATE', 'aviso_transferencia_v1'),
        'transfer_notification_template_language' => env('WHATSAPP_TRANSFER_NOTIFICATION_TEMPLATE_LANGUAGE', 'pt_BR'),
        'first_transfer_notification_template' => env('WHATSAPP_FIRST_TRANSFER_NOTIFICATION_TEMPLATE', 'primeira_transferencia_v1'),
        'first_transfer_notification_template_language' => env('WHATSAPP_FIRST_TRANSFER_NOTIFICATION_TEMPLATE_LANGUAGE', 'pt_BR'),
        'pix_copy_code_template' => env('WHATSAPP_PIX_COPY_CODE_TEMPLATE') ?: 'mpmm_pix_copy_code',
        'boleto_copy_code_template' => env('WHATSAPP_BOLETO_COPY_CODE_TEMPLATE') ?: 'mpmm_boleto_copy_code',
        'payment_code_template_language' => env('WHATSAPP_PAYMENT_CODE_TEMPLATE_LANGUAGE', 'pt_BR'),
        'inter_message_delay_ms' => (int) env('WHATSAPP_INTER_MESSAGE_DELAY_MS', 1000),
    ],

];

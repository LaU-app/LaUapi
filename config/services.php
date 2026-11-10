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

    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
    ],

    'giphy' => [
        'api_key' => env('GIPHY_API_KEY'),
    ],

    'make' => [
    'webhook_url' => env('MAKE_WEBHOOK_URL'),
],

    // PayPal (comercios verificados de LaU Marketplace). Los hosts por entorno viven en
    // App\Services\PayPal\PayPalClient. Se aceptan los nombres PAYPAL_SANDBOX_* anteriores.
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox') === 'live' ? 'live' : 'sandbox',
        'client_id' => env('PAYPAL_CLIENT_ID') ?: env('PAYPAL_SANDBOX_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET') ?: env('PAYPAL_SANDBOX_CLIENT_SECRET'),
        // Account ID (merchant ID) de la cuenta Business dueña de la app Platform.
        'platform_account_id' => env('PAYPAL_PLATFORM_ACCOUNT_ID') ?: env('PAYPAL_SANDBOX_PLATFORM_ACCOUNT_ID'),
        // Opcional: se envía como PayPal-Partner-Attribution-Id si existe.
        'bn_code' => env('PAYPAL_BN_CODE') ?: env('PAYPAL_SANDBOX_BN_CODE'),
    ],

    // TypeSafe AI (Jev, modelo System One): decisiones tipadas para la compra inteligente.
    // La clave solo vive en el servidor.
    'typesafe' => [
        'api_key' => env('TYPESAFE_API_KEY'),
        'model' => env('TYPESAFE_MODEL') ?: 'jev-latest',
        'endpoint' => 'https://api.typesafe.ai/v1/systemone',
        'timeout' => 20,
    ],

    // YouTube Data API v3 (opcional): videos de reseñas de productos. Sin clave se ofrece un enlace de búsqueda.
    'youtube' => [
        'key' => env('YOUTUBE_API_KEY'),
    ],

    // Agente de compras: modelo de lenguaje abierto con API compatible con OpenAI.
    // Local: Ollama (http://127.0.0.1:11434/v1). Producción: el mismo tipo de modelo abierto
    // servido por un proveedor (por ejemplo Groq). Sin base_url el chat queda deshabilitado.
    'agent' => [
        'base_url' => rtrim((string) env('AGENT_LLM_BASE_URL', ''), '/'),
        'api_key' => env('AGENT_LLM_API_KEY'),
        'model' => env('AGENT_LLM_MODEL') ?: 'qwen3.5:9b',
        'timeout' => (int) env('AGENT_LLM_TIMEOUT', 120),
        // Razonamiento del modelo antes de responder ("think" en Ollama, reasoning_effort en otros).
        'reasoning' => env('AGENT_LLM_REASONING', 'low'),
    ],

    // Inicio de sesión con Google (web): el código se canjea en el servidor para que el
    // client secret nunca llegue al navegador. El client ID es público.
    'google_oauth' => [
        'client_id' => env('GOOGLE_WEB_CLIENT_ID', '620970885957-jo1cvl0a27eqo51vq5d5lt45i0sd6llo.apps.googleusercontent.com'),
        'client_secret' => env('GOOGLE_WEB_CLIENT_SECRET'),
        // Además de FRONTEND_URL/auth/callback, separadas por coma (por ejemplo para desarrollo local).
        'extra_redirects' => array_filter(array_map('trim', explode(',', (string) env('GOOGLE_OAUTH_EXTRA_REDIRECTS', 'http://localhost:5173/auth/callback')))),
    ],

    // URL pública de la app (a dónde vuelve un comercio al terminar la conexión con PayPal).
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'https://lauapp.es'), '/'),

    // Correo al que responden los comercios (los avisos salen desde MAIL_FROM_ADDRESS).
    'lau' => [
        'reply_to' => env('MAIL_REPLY_TO_ADDRESS', 'lauapp.es@gmail.com'),
    ],

];

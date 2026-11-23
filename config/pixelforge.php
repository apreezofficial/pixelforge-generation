<?php

/*
 * Pixelforge provider configuration.
 *
 * Rates are USD per 1M tokens, as published at GET /v1/pricing. They are kept
 * as strings so no float ever touches money. The rates actually used for a
 * generation are snapshotted from the API response when it is created.
 */
return [
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    // Seconds a webhook timestamp may differ from our clock. Keep at 300 in production;
    // only raise it locally if the development machine's clock is wrong.
    'webhook_tolerance' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

    // Markup applied to provider cost: charge = provider cost * (1 + markup).
    // Expressed as a fraction numerator/denominator to keep integer math exact.
    'markup' => ['numerator' => 5, 'denominator' => 4], // 25%

    'max_submit_attempts' => 12,

    'rates' => [
        'input_text' => '5.00',
        'input_image' => '10.00',
        'output' => '40.00',
        'reasoning' => '12.50',
    ],

    'text_chars_per_token' => 4,
    'text_token_variance' => 0.15,
    'image_tokens_per_reference' => 765,
    'max_reference_images' => 4,

    'output_tokens' => [
        'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
        'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
        'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
    ],

    'reasoning_tokens' => [
        'low' => ['min' => 0, 'max' => 500],
        'medium' => ['min' => 200, 'max' => 2000],
        'high' => ['min' => 1000, 'max' => 8000],
    ],
];

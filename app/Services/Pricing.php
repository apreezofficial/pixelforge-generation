<?php

namespace App\Services;

use App\Support\Money;

/**
 * Turns token usage into provider cost and customer charge, and produces
 * the upfront estimate shown before a generation is submitted.
 */
class Pricing
{
    /**
     * Upfront estimate. This is an upper bound: text tokens at the top of the
     * variance band and reasoning at its maximum. The real cost is known only
     * after the generation completes, and is never higher than this.
     *
     * @return array{text_tokens:int,image_tokens:int,output_tokens:int,reasoning_tokens:int,provider_nano:int,charge_nano:int}
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceCount): array
    {
        $chars = mb_strlen($prompt);
        $baseTextTokens = (int) ceil($chars / config('pixelforge.text_chars_per_token'));
        $textTokens = (int) ceil($baseTextTokens * (1 + config('pixelforge.text_token_variance')));
        $imageTokens = $referenceCount * config('pixelforge.image_tokens_per_reference');
        $outputTokens = $this->outputTokens($quality, $size);
        $reasoningTokens = config("pixelforge.reasoning_tokens.$quality.max");

        $providerNano = $this->providerCost(
            ['input_text' => $textTokens, 'input_image' => $imageTokens, 'output' => $outputTokens, 'reasoning' => $reasoningTokens],
            config('pixelforge.rates'),
        );

        return [
            'text_tokens' => $textTokens,
            'image_tokens' => $imageTokens,
            'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'provider_nano' => $providerNano,
            'charge_nano' => $this->charge($providerNano),
        ];
    }

    public function outputTokens(string $quality, string $size): int
    {
        return config("pixelforge.output_tokens.$quality.$size")
            ?? throw new \InvalidArgumentException("Unsupported quality/size: $quality $size");
    }

    /**
     * Exact provider cost in nanodollars for a set of token counts.
     *
     * @param  array{input_text:int,input_image:int,output:int,reasoning:int}  $tokens
     * @param  array<string,string>  $rates  USD per 1M tokens, keyed like the API's rates object
     */
    public function providerCost(array $tokens, array $rates): int
    {
        $total = 0;
        foreach ($tokens as $kind => $count) {
            $total += Money::tokensCost($count, Money::rateToHundredths($rates[$kind]));
        }

        return $total;
    }

    /** Customer charge: provider cost plus the configured markup, rounded up to the nano. */
    public function charge(int $providerNano): int
    {
        $markup = config('pixelforge.markup');

        return Money::withMarkup($providerNano, $markup['numerator'], $markup['denominator']);
    }
}

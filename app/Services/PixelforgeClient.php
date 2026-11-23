<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge HTTP API. It returns raw responses so
 * callers can branch on status and error code.
 */
class PixelforgeClient
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string,string>  $metadata
     */
    public function createGeneration(
        string $idempotencyKey,
        string $prompt,
        string $size,
        string $quality,
        array $referenceImages,
        array $metadata = [],
    ): Response {
        return $this->http()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/generations', array_filter([
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'metadata' => $metadata ?: null,
            ], fn ($v) => $v !== null));
    }

    public function getGeneration(string $pixelforgeId): Response
    {
        return $this->http()->get("/generations/{$pixelforgeId}");
    }

    /**
     * Used by the reconciliation command to check our provider cost totals.
     */
    public function usage(string $startDate, string $endDate): Response
    {
        return $this->http()->get('/usage', ['start_date' => $startDate, 'end_date' => $endDate]);
    }

    /**
     * @throws ConnectionException
     */
    private function http(): PendingRequest
    {
        return Http::baseUrl(config('pixelforge.base_url'))
            ->withToken((string) config('pixelforge.api_key'))
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5);
    }
}

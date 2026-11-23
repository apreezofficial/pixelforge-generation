<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends a generation to Pixelforge. Retries are safe: the local uuid is sent as the
 * Idempotency-Key, so a retry after a timeout cannot create a second generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // we manage retries ourselves via release()

    public function __construct(public int $generationId) {}

    public function handle(PixelforgeClient $client, GenerationUpdater $updater): void
    {
        $generation = Generation::query()->find($this->generationId);

        if ($generation === null || $generation->pixelforge_id !== null || $generation->isFinal()) {
            return; // already submitted or finished; a duplicate job is a no-op
        }

        $generation->increment('submit_attempts');

        try {
            $response = $client->createGeneration(
                idempotencyKey: $generation->uuid,
                prompt: $generation->prompt,
                size: $generation->size,
                quality: $generation->quality,
                referenceImages: $generation->reference_images,
                metadata: ['generation_uuid' => $generation->uuid],
            );
        } catch (ConnectionException $e) {
            $this->retryLater($generation, null, 'connection_error');

            return;
        }

        $status = $response->status();

        if ($status === 202) {
            $updater->apply($generation, $response->json());

            return;
        }

        $code = $response->json('error.code', 'provider_error');
        $message = $response->json('error.message', 'Pixelforge request failed.');

        // Errors that mean "not now": back off and try again.
        if (in_array($status, [429, 503], true)) {
            $this->retryLater($generation, (int) $response->header('Retry-After') ?: null, $code, $message);

            return;
        }

        // Anything else is a permanent rejection (blocked prompt, validation, bad key).
        // Nothing was created on Pixelforge's side, so the generation is failed and free.
        Log::warning('Pixelforge rejected generation', ['uuid' => $generation->uuid, 'status' => $status, 'code' => $code]);
        $generation->update([
            'status' => Generation::FAILED,
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    private function retryLater(Generation $generation, ?int $retryAfter, string $code, ?string $message = null): void
    {
        if ($generation->submit_attempts >= config('pixelforge.max_submit_attempts')) {
            $generation->update([
                'status' => Generation::FAILED,
                'error_code' => $code,
                'error_message' => $message ?? 'Pixelforge stayed unavailable. No charge was made.',
                'completed_at' => now(),
            ]);

            return;
        }

        // Use Retry-After when given, otherwise exponential backoff capped at 5 minutes.
        $delay = $retryAfter ?? min(300, 2 ** $generation->submit_attempts);

        self::dispatch($generation->id)->delay(now()->addSeconds($delay));
    }
}

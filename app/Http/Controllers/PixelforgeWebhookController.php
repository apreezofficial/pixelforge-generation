<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Services\GenerationUpdater;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __construct(private GenerationUpdater $updater) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Verify against the raw body, before any JSON decoding changes whitespace or ordering.
        $raw = $request->getContent();

        if (! $this->signatureIsValid($request->header('Pixelforge-Signature'), $raw)) {
            Log::warning('Pixelforge webhook rejected: invalid signature', [
                'has_header' => $request->hasHeader('Pixelforge-Signature'),
                'secret_configured' => config('pixelforge.webhook_secret') !== null,
                'header_shape' => preg_replace('/[0-9a-f]{64}/', '<64hex>', (string) $request->header('Pixelforge-Signature')),
                'clock_skew_seconds' => time() - (int) preg_replace('/^t=(\d+).*/', '$1', (string) $request->header('Pixelforge-Signature')),
                'body_bytes' => strlen($raw),
            ]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $event = json_decode($raw, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        // ping is the dashboard test button; nothing to record.
        if ($event['type'] === 'ping') {
            return response()->json(['received' => true]);
        }

        $remote = $event['data']['object'] ?? null;
        if (! is_array($remote) || empty($remote['id'])) {
            return response()->json(['error' => 'invalid_payload'], 400);
        }

        try {
            $outcome = $this->process($event, $remote);
        } catch (\RuntimeException $e) {
            // 503 asks Pixelforge to retry later; we have recorded nothing for this event.
            Log::warning('Pixelforge webhook deferred', ['event' => $event['id'], 'reason' => $e->getMessage()]);

            return response()->json(['error' => 'retry_later'], 503);
        }

        Log::info('Pixelforge webhook', ['event' => $event['id'], 'type' => $event['type'], 'outcome' => $outcome]);

        return response()->json(['received' => true, 'outcome' => $outcome]);
    }

    /**
     * @param  array<string,mixed>  $event
     * @param  array<string,mixed>  $remote
     */
    private function process(array $event, array $remote): string
    {
        return DB::transaction(function () use ($event, $remote) {
            // INSERT OR IGNORE on the primary key: a duplicate delivery inserts nothing and
            // does no work. Concurrent duplicates wait on the index, so only one applies.
            $inserted = DB::table('webhook_events')->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'payload' => json_encode($event),
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return 'duplicate';
            }

            $generation = Generation::query()->where('pixelforge_id', $remote['id'])->first();

            if ($generation === null) {
                // The POST response that stores pixelforge_id has not been saved yet. Failing
                // makes Pixelforge retry later, and the rollback also drops this event record.
                throw new \RuntimeException("Unknown generation {$remote['id']}");
            }

            $this->updater->apply($generation, $remote);

            return 'applied';
        });
    }

    private function signatureIsValid(?string $header, string $raw): bool
    {
        $secret = (string) config('pixelforge.webhook_secret');
        if ($header === null || $secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        // Reject old or future timestamps to stop replays.
        if (abs(Carbon::now()->timestamp - (int) $timestamp) > config('pixelforge.webhook_tolerance')) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}

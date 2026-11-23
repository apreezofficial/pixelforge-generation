<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a Pixelforge generation object (from a webhook, a POST response or
 * a poll) to our record. Safe to call any number of times, in any order:
 *  - status only moves forward, so a late "processing" never reopens a finished job;
 *  - a charge is written once, guarded by the unique index on charges.generation_id.
 */
class GenerationUpdater
{
    public function __construct(private Pricing $pricing) {}

    /**
     * @param  array<string,mixed>  $remote  the generation object as returned by Pixelforge
     */
    public function apply(Generation $generation, array $remote): void
    {
        DB::transaction(function () use ($generation, $remote) {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            $pixelforgeId = $remote['id'] ?? null;
            if ($pixelforgeId !== null && $generation->pixelforge_id === null) {
                $generation->pixelforge_id = $pixelforgeId;
            }

            $incoming = $remote['status'] ?? null;
            if ($incoming === null || $generation->isFinal()) {
                // Already final: nothing further can change it, so only keep the pixelforge id.
                $generation->save();

                return;
            }

            $current = Generation::RANK[$generation->status] ?? 0;
            $next = Generation::RANK[$incoming] ?? 0;

            if ($next < $current) {
                $generation->save();

                return;
            }

            $generation->status = $incoming;
            $generation->rates = $this->normaliseRates($remote['rates'] ?? null) ?? $generation->rates;

            if ($incoming === Generation::SUCCEEDED) {
                $this->recordSuccess($generation, $remote);
            } elseif ($incoming === Generation::FAILED) {
                // A failed generation is free: no usage, no charge.
                $generation->error_code = $remote['error']['code'] ?? 'generation_failed';
                $generation->error_message = $remote['error']['message'] ?? null;
                $generation->completed_at = $this->parseTime($remote['completed_at'] ?? null) ?? now();
            }

            $generation->save();
        });
    }

    private function recordSuccess(Generation $generation, array $remote): void
    {
        $usage = $remote['usage'] ?? null;
        $rates = $this->normaliseRates($remote['rates'] ?? null) ?? $generation->rates;

        if ($usage === null || $rates === null) {
            throw new \RuntimeException("Succeeded generation {$generation->pixelforge_id} has no usage or rates");
        }

        $providerNano = $this->pricing->providerCost([
            'input_text' => (int) $usage['input_text_tokens'],
            'input_image' => (int) $usage['input_image_tokens'],
            'output' => (int) $usage['output_tokens'],
            'reasoning' => (int) $usage['reasoning_tokens'],
        ], $rates);

        $chargeNano = $this->pricing->charge($providerNano);

        $generation->usage = $usage;
        $generation->provider_cost_nano = $providerNano;
        $generation->charge_nano = $chargeNano;
        $generation->image_url = $remote['image_url'] ?? null;
        $generation->image_url_expires_at = $this->parseTime($remote['image_url_expires_at'] ?? null);
        $generation->completed_at = $this->parseTime($remote['completed_at'] ?? null) ?? now();

        // Guarded by the unique index: a second write for the same generation fails the
        // insert, so an event can never bill twice even if it slips past the status check.
        Charge::query()->firstOrCreate(
            ['generation_id' => $generation->id],
            [
                'user_id' => $generation->user_id,
                'amount_nano' => $chargeNano,
                'provider_cost_nano' => $providerNano,
                'charged_at' => $generation->completed_at,
            ],
        );
    }

    /**
     * Pixelforge rates use API names; map them to the keys Pricing uses.
     *
     * @return array<string,string>|null
     */
    private function normaliseRates(?array $rates): ?array
    {
        if ($rates === null) {
            return null;
        }

        return [
            'input_text' => (string) $rates['input_text_tokens'],
            'input_image' => (string) $rates['input_image_tokens'],
            'output' => (string) $rates['output_tokens'],
            'reasoning' => (string) $rates['reasoning_tokens'],
        ];
    }

    private function parseTime(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value);
    }
}

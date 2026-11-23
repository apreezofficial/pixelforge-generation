<?php

namespace App\Console\Commands;

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\GenerationUpdater;
use App\Services\PixelforgeClient;
use Illuminate\Console\Command;

/**
 * Safety net for lost webhooks: polls generations that should have finished and
 * re-dispatches submissions that never reached Pixelforge. Both operations are
 * idempotent, so running this alongside webhooks is safe.
 */
class SyncGenerations extends Command
{
    protected $signature = 'generations:sync {--older-than=45 : Only poll generations at least this many seconds old}';

    protected $description = 'Poll Pixelforge for in-flight generations and retry stuck submissions';

    public function handle(PixelforgeClient $client, GenerationUpdater $updater): int
    {
        $cutoff = now()->subSeconds((int) $this->option('older-than'));

        Generation::query()
            ->whereNotNull('pixelforge_id')
            ->whereIn('status', [Generation::QUEUED, Generation::PROCESSING])
            ->where('updated_at', '<', $cutoff)
            ->each(function (Generation $generation) use ($client, $updater) {
                $response = $client->getGeneration($generation->pixelforge_id);

                if ($response->successful()) {
                    $updater->apply($generation, $response->json());
                } else {
                    $this->warn("Could not poll {$generation->pixelforge_id}: HTTP {$response->status()}");
                }
            });

        // Submissions with no Pixelforge id for a while lost their queue job (or exhausted it).
        // Re-dispatching is safe because the same Idempotency-Key is reused.
        Generation::query()
            ->whereNull('pixelforge_id')
            ->where('status', Generation::SUBMITTING)
            ->where('updated_at', '<', now()->subMinutes(2))
            ->each(fn (Generation $generation) => SubmitGeneration::dispatch($generation->id));

        return self::SUCCESS;
    }
}
